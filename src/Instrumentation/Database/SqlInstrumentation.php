<?php
/**
 * This file is part of the Mumzworld_OpenTelemetry package.
 *
 * @author    Raj KB <rajendra.bhatta@mumzworld.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com)
 */
declare(strict_types=1);

namespace Mumzworld\OpenTelemetry\Instrumentation\Database;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Adapter\Pdo\Mysql\Interceptor as MysqlInterceptor;
use Magento\Framework\DB\Select;
use Mumzworld\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Trace\LocalRootSpan;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;
use WeakMap;
use function OpenTelemetry\Instrumentation\hook;

class SqlInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.sql';
    private const SPAN_NAME_PREFIX = 'SQL:';
    private const SQL_QUERY_SPAN_LIMIT = 2048;

    /** @var WeakMap<object, WeakMap<object, list<array{class: string, started: bool}>>>|null */
    private static ?WeakMap $framesByContext = null;
    private static ?object $mainContext = null;
    /** @var WeakMap<object, int>|null */
    private static ?WeakMap $countsByRoot = null;

    private static function withinTraceLimit(): bool
    {
        $root = LocalRootSpan::current();
        if (!$root->getContext()->isValid()) {
            // With no active local root, each query starts its own trace.
            return true;
        }
        $counts = self::$countsByRoot ??= new WeakMap();
        $count = $counts[$root] ?? 0;
        if ($count >= self::SQL_QUERY_SPAN_LIMIT) {
            return false;
        }
        $counts[$root] = $count + 1;
        return true;
    }

    /** Keep nested hooks separate for each Fiber and database adapter. */
    private static function frames(): WeakMap
    {
        $context = \Fiber::getCurrent() ?? (self::$mainContext ??= new \stdClass());
        $contexts = self::$framesByContext ??= new WeakMap();
        if (!isset($contexts[$context])) {
            $contexts[$context] = new WeakMap();
        }
        return $contexts[$context];
    }

    private static function pushFrame(Mysql $subject, string $class): bool
    {
        $frames = self::frames();
        $stack = $frames[$subject] ?? [];
        $previous = $stack ? $stack[count($stack) - 1]['class'] : null;
        // Parent calls inside an interceptor are one operation. A recursive
        // interceptor call from a plugin is a distinct nested operation.
        $inherited = $previous !== null && $previous !== $class && is_subclass_of($previous, $class);
        $stack[] = ['class' => $class, 'started' => false];
        $frames[$subject] = $stack;
        return $inherited;
    }

    private static function markFrameStarted(Mysql $subject): void
    {
        $frames = self::frames();
        $stack = $frames[$subject];
        $stack[count($stack) - 1]['started'] = true;
        $frames[$subject] = $stack;
    }

    private static function popFrame(Mysql $subject): bool
    {
        $frames = self::frames();
        $stack = $frames[$subject] ?? [];
        if (!$stack) {
            return false;
        }
        $started = array_pop($stack)['started'];
        if ($stack) {
            $frames[$subject] = $stack;
        } else {
            unset($frames[$subject]);
        }
        return $started;
    }

    /**
     * @inheritdoc
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    protected static function getInstrumentationName(): string
    {
        return self::INSTRUMENTATION_NAME;
    }

    /**
     * @inheritdoc
     */
    public static function register(): void
    {
        self::instrumentSqlQuery();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentSqlQuery(): void
    {
        hook(
            Mysql::class,
            'query',
            static function (
                Mysql $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                // Magento interceptors wrap the base adapter and otherwise
                // produce a second span for the same query. A nested query
                // made by a plugin remains a distinct child span.
                if (self::pushFrame($subject, $class)) {
                    return;
                }
                if (!self::withinTraceLimit()) {
                    return;
                }

                [$sqlString, ] = self::prepareQueryData($params);
                $spanName = sprintf(
                    '%s %s',
                    self::SPAN_NAME_PREFIX,
                    self::getOperationNameFromQuery($sqlString)
                );
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    Mysql::class,
                    $filename,
                    $lineno,
                )
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(
                        TraceAttributes::DB_QUERY_TEXT,
                        self::saferStringEncode(self::truncateString($sqlString))
                    )
                    // Note: Uncomment only if required. It might expose sensitive data.
                    // Don't forget to add back the $bind var: [$sqlString, $bind] = self::prepareQueryData($params);
                    /*->setAttribute(
                        TraceAttributes::DB_OPERATION_PARAMETER,
                        self::saferStringEncode(self::saferJsonEncode(self::extractElements($bind)))
                    )*/;
                self::startSpanAndAttachToContext($builder);
                self::markFrameStarted($subject);
            },
            static function (
                Mysql       $subject,
                array       $params,
                mixed       $returnValue,
                ?Throwable  $exception,
            ) {
                if (!self::popFrame($subject)) {
                    return;
                }
                try {
                    self::updateSpanWithRowCount($returnValue);
                } finally {
                    self::endSpan($exception);
                }
            },
        );
    }

    /**
     * @param array $params
     * @return array
     */
    private static function prepareQueryData(array $params): array
    {
        $sql = $params[0] ?? '';
        $bind = $params[1] ?? [];

        if ($sql instanceof Select) {
            $bind = empty($bind) ? $sql->getBind() : $bind;
            $sqlString = $sql->__toString();
        } else {
            $bind = is_array($bind) ? $bind : [$bind];
            $sqlString = $sql;
        }

        return [(string)$sqlString, $bind];
    }

    /**
     * @param mixed $returnValue
     * @return void
     */
    private static function updateSpanWithRowCount(mixed $returnValue): void
    {
        if (!$returnValue || !is_object($returnValue) || !method_exists($returnValue, 'rowCount')) {
            return;
        }

        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }

        $span = Span::fromContext($scope->context());
        if (!$span) {
            return;
        }

        try {
            $rowCount = $returnValue->rowCount();
        } catch (Throwable) {
            // Row counts are optional telemetry; never expose a driver error
            // or affect a successfully completed application query.
            return;
        }
        $span->setAttribute('db.query.affected_rows', $rowCount);
    }

    /**
     * Gets operation name from the SQL query
     *
     * @param string $query
     * @return string
     */
    public static function getOperationNameFromQuery(string $query): string
    {
        $pattern = '/^\s*('
            . 'SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|'
            . 'EXEC|DESCRIBE|SHOW|SET|USE|BEGIN|COMMIT|ROLLBACK|EXPLAIN'
            . ')\b/i';

        if (preg_match($pattern, $query, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}
