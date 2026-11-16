<?php
/**
 * This file is part of the Mumzworld_OpenTelemetry package.
 *
 * @author    Raj KB <rajendra.bhatta@mumzworld.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com)
 */
declare(strict_types=1);

namespace Mumzworld\OpenTelemetry\Instrumentation\Core;

use Magento\Framework\Event\Manager\Proxy as ManagerProxy;
use Magento\Framework\Event\InvokerInterface;
use Mumzworld\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\LocalRootSpan;
use Throwable;
use WeakMap;
use function OpenTelemetry\Instrumentation\hook;

class EventObserverInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento.event-observer';
    private const SPAN_NAME_PREFIX = 'Event:';
    /** @var WeakMap<object, WeakMap<object, list<array{class: string, started: bool}>>>|null */
    private static ?WeakMap $framesByContext = null;
    private static ?object $mainContext = null;
    private static ?WeakMap $renderObserverCounts = null;
    private const EXCLUDED_OBSERVERS = [
        'pagecache',
        'catalogrule',
        'magento_currencysymbol_currency_display_options',
        'inventory'
    ];

    private static function frames(): WeakMap
    {
        $context = \Fiber::getCurrent() ?? (self::$mainContext ??= new \stdClass());
        $contexts = self::$framesByContext ??= new WeakMap();
        return $contexts[$context] ??= new WeakMap();
    }

    private static function pushFrame(InvokerInterface $subject, string $class): bool
    {
        $frames = self::frames();
        $stack = $frames[$subject] ?? [];
        $previous = $stack ? $stack[count($stack) - 1]['class'] : null;
        $inherited = $previous !== null && $previous !== $class && is_subclass_of($previous, $class);
        $stack[] = ['class' => $class, 'started' => false];
        $frames[$subject] = $stack;
        return $inherited;
    }

    private static function markStarted(InvokerInterface $subject): void
    {
        $frames = self::frames();
        $stack = $frames[$subject];
        $stack[count($stack) - 1]['started'] = true;
        $frames[$subject] = $stack;
    }

    private static function popFrame(InvokerInterface $subject): bool
    {
        $frames = self::frames();
        $stack = $frames[$subject] ?? [];
        if (!$stack) { return false; }
        $started = array_pop($stack)['started'];
        if ($stack) { $frames[$subject] = $stack; } else { unset($frames[$subject]); }
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
        // Only uncomment if required
        #self::instrumentEventManagerDispatch();
        self::instrumentEventInvokerDispatch();
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentEventManagerDispatch(): void
    {
        hook(
            ManagerProxy::class,
            'dispatch',
            static function (
                ManagerProxy     $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                $eventName = $params[0] ?? 'NA';

                $spanName = sprintf('%s %s', self::SPAN_NAME_PREFIX, $eventName);
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )
                    ->setSpanKind(SpanKind::KIND_INTERNAL);

                self::startSpanAndAttachToContext($builder);
            },
            static function (
                ManagerProxy     $subject,
                array       $params,
                mixed       $returnValue,
                ?Throwable  $exception,
            ) {
                self::endSpan($exception);
            },
        );
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentEventInvokerDispatch(): void
    {
        hook(
            InvokerInterface::class,
            'dispatch',
            static function (
                InvokerInterface     $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                if (self::pushFrame($subject, $class)) {
                    return;
                }
                $observerConfig = $params[0] ?? [];
                $observerName = $observerConfig['name'] ?? 'NA';
                if (in_array($observerName, self::EXCLUDED_OBSERVERS)) {
                    return;
                }

                // Magento dispatches these events for every block. Keep a few
                // examples, while leaving nested work attached to the block.
                $event = $params[1] ?? null;
                $eventName = $event instanceof \Magento\Framework\Event\Observer
                    ? $event->getEvent()?->getName() : null;
                if ($eventName === 'view_block_abstract_to_html_before' || $eventName === 'view_block_abstract_to_html_after') {
                    $root = LocalRootSpan::current();
                    $counts = self::$renderObserverCounts ??= new WeakMap();
                    $seen = $counts[$root] ?? [];
                    $key = $eventName . ':' . $observerName;
                    $seen[$key] = ($seen[$key] ?? 0) + 1;
                    $counts[$root] = $seen;
                    if ($seen[$key] > 3) { return; }
                }

                $spanName = sprintf('Observer: %s', $observerName);
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute('magento.event.observer.invoked.details', self::saferJsonEncode($observerConfig));

                self::startSpanAndAttachToContext($builder);
                self::markStarted($subject);
            },
            static function (
                InvokerInterface     $subject,
                array       $params,
                mixed       $returnValue,
                ?Throwable  $exception,
            ) {
                if (self::popFrame($subject)) { self::endSpan($exception); }
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}
