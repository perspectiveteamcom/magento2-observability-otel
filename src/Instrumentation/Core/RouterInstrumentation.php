<?php
declare(strict_types=1);
namespace Mumzworld\OpenTelemetry\Instrumentation\Core;
use Magento\Framework\App\RouterInterface;
use Mumzworld\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Trace\LocalRootSpan;
use Throwable;
use WeakMap;
use function OpenTelemetry\Instrumentation\hook;
class RouterInstrumentation extends AbstractInstrumentation {
    /** @var WeakMap<object, WeakMap<object, list<array{class: string, started: bool}>>>|null */
    private static ?WeakMap $framesByContext = null;
    private static ?object $mainContext = null;

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

    private static function pushFrame(RouterInterface $subject, string $class): bool
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

    private static function markFrameStarted(RouterInterface $subject): void
    {
        $frames = self::frames();
        $stack = $frames[$subject];
        $stack[count($stack) - 1]['started'] = true;
        $frames[$subject] = $stack;
    }

    private static function popFrame(RouterInterface $subject): bool
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

    protected static function getInstrumentationName(): string { return 'io.opentelemetry.contrib.php.magento.router'; }
    public static function register(): void {
        hook(RouterInterface::class, 'match',
            static function (RouterInterface $subject, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                if (self::pushFrame($subject, $class)) { return; }
                $builder = self::createSpanBuilder('Router: '.get_class($subject).'::match', $function, $class, $filename, $lineno);
                self::startSpanAndAttachToContext($builder);
                self::markFrameStarted($subject);
            },
            static function (RouterInterface $subject, array $params, mixed $result, ?Throwable $exception) {
                if (self::popFrame($subject)) { self::endSpan($exception); }
                if ($exception || $result === null || !isset($params[0]) || !is_object($params[0]) || !method_exists($params[0], 'getFullActionName')) { return; }
                try {
                    $action = $params[0]->getFullActionName();
                    if (is_string($action) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $action)) {
                        $root = LocalRootSpan::current();
                        $root->setAttribute('magento.route', $action);
                        $root->updateName($action);
                    }
                } catch (Throwable) {
                    // Tracing must not change Magento's router result.
                }
            }
        );
    }
}
