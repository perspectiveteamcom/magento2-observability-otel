<?php
declare(strict_types=1);

namespace Perspective\ObservabilityOtel;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\LocalRootSpan;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;

/** Hooks are deliberately independent of Magento DI compilation and core edits. */
final class Instrumentation
{
    private static bool $logging = false;
    private static float $requestStart = 0.0;
    private static string $route = 'unresolved';

    public static function register(): void
    {
        $otel = new CachedInstrumentation('perspective.magento', '0.1.0');
        $pendingResponses = new \WeakMap();
        $recordRequest = static function ($response, ?\Throwable $exception, float $start, string $route) use ($otel): void {
            $status = $exception ? 500 : ($response instanceof \Magento\Framework\App\Response\HttpInterface ? $response->getHttpResponseCode() : 200);
            $otel->meter()->createHistogram('magento.http.server.duration', 's', 'Magento request duration',
                ['ExplicitBucketBoundaries' => [0.01, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]])
                ->record(microtime(true) - $start, ['magento.route' => $route, 'http.response.status_code' => $status]);
        };
        Hook::register('Magento\Framework\App\ResponseInterface', 'sendResponse',
            post: static function ($response, array $params, $result, ?\Throwable $exception) use ($pendingResponses, $recordRequest): void {
                if (!isset($pendingResponses[$response])) {
                    return;
                }
                [$start, $route] = $pendingResponses[$response];
                unset($pendingResponses[$response]);
                $recordRequest($response, $exception, $start, $route);
            });
        Hook::register('Magento\Framework\App\Http', 'launch',
            pre: static function ($object, array $params, string $class): void {
                if ($class !== 'Magento\Framework\App\Http') {
                    return;
                }
                self::$requestStart = microtime(true);
                self::$route = 'unresolved';
            },
            post: static function ($object, array $params, $response, ?\Throwable $exception, string $class) use ($pendingResponses, $recordRequest): void {
                if ($class !== 'Magento\Framework\App\Http') {
                    return;
                }
                // Magento REST sets error codes while rendering sendResponse().
                if ($response instanceof \Magento\Framework\Webapi\Rest\Response && !$exception) {
                    $pendingResponses[$response] = [self::$requestStart, self::$route];
                    return;
                }
                $recordRequest($response, $exception, self::$requestStart, self::$route);
            });
        Hook::register('Magento\Framework\App\FrontController', 'dispatch',
            post: static function ($object, array $params): void {
                $request = $params[0] ?? null;
                if ($request && method_exists($request, 'getFullActionName')) {
                    self::$route = (string) $request->getFullActionName();
                    LocalRootSpan::current()->updateName($request->getMethod() . ' ' . self::$route);
                    LocalRootSpan::current()->setAttribute('magento.route', self::$route);
                }
            });
        $jobs = new \WeakMap();
        $begin = static function ($object, string $type, \Closure $start) use ($jobs): void {
            // Reserve a frame before fallible telemetry work. A failed nested pre-hook
            // must never detach or end the enclosing application job's span.
            $frame = (object) ['type' => $type, 'span' => null, 'scope' => null];
            $frames = $jobs[$object] ?? [];
            $frames[] = $frame;
            $jobs[$object] = $frames;
            $frame->span = $start();
            $frame->scope = Context::storage()->attach($frame->span->storeInContext(Context::getCurrent()));
        };
        $finish = static function ($object, array $params, $returnValue, ?\Throwable $exception) use ($otel, $jobs): void {
            $frames = $jobs[$object] ?? [];
            if (!$frames) {
                return;
            }
            $frame = array_pop($frames);
            if ($frames) {
                $jobs[$object] = $frames;
            } else {
                unset($jobs[$object]);
            }
            if ($frame->scope) {
                $frame->scope->detach();
            }
            $span = $frame->span;
            if (!$span) {
                return;
            }
            $type = $frame->type;
            $status = $exception ? 'error' : 'success';
            if ($exception) {
                $span->setStatus(StatusCode::STATUS_ERROR);
                $span->setAttribute('error.type', $exception::class);
            }
            $span->end();
            $otel->meter()->createCounter('magento.job.executions')->add(1, ['job.type' => $type, 'status' => $status]);
            // Consumers and cron must export between jobs. A web publisher uses SDK
            // shutdown instead of adding three synchronous flushes to a business call.
            if (PHP_SAPI !== 'cli' || $type === 'producer') {
                return;
            }
            foreach ([Globals::tracerProvider(), Globals::meterProvider(), Globals::loggerProvider()] as $provider) {
                if (method_exists($provider, 'forceFlush')) {
                    $provider->forceFlush();
                }
            }
        };

        Hook::register('Magento\Cron\Observer\ProcessCronQueueObserver', '_runJob',
            pre: static function ($object, array $params) use ($otel, $begin): void {
                $begin($object, 'cron', static function () use ($otel, $params) {
                    $schedule = $params[3] ?? null;
                    $code = $schedule ? (string) $schedule->getJobCode() : 'unknown';
                    return $otel->tracer()->spanBuilder('cron ' . $code)
                        ->setSpanKind(SpanKind::KIND_INTERNAL)
                        ->setAttribute('magento.cron.job', $code)
                        ->setAttribute('magento.cron.group', (string) ($params[4] ?? 'default'))->startSpan();
                });
            }, post: $finish);

        Hook::register('Magento\Framework\MessageQueue\Consumer', 'dispatchMessage',
            pre: static function ($object, array $params) use ($otel, $begin): void {
                $begin($object, 'consumer', static function () use ($otel, $params) {
                    $properties = $params[0]->getProperties();
                    $headers = $properties['application_headers'] ?? [];
                    $headers = is_object($headers) && method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array) $headers;
                    return $otel->tracer()->spanBuilder('consume ' . ($properties['topic_name'] ?? 'unknown'))
                        ->setParent(Globals::propagator()->extract($headers))
                        ->setSpanKind(SpanKind::KIND_CONSUMER)
                        ->setAttribute('messaging.destination.name', $properties['topic_name'] ?? 'unknown')->startSpan();
                });
            }, post: $finish);

        Hook::register('Magento\Framework\Amqp\Exchange', 'enqueue',
            pre: static function ($object, array $params) use ($otel, $begin): void {
                $begin($object, 'producer', static function () use ($otel, $params) {
                    return $otel->tracer()->spanBuilder('publish ' . $params[0])
                        ->setSpanKind(SpanKind::KIND_PRODUCER)
                        ->setAttribute('messaging.system', 'rabbitmq')
                        ->setAttribute('messaging.destination.name', (string) $params[0])->startSpan();
                });
                // Observe the publish call without changing its envelope, headers or body.
            }, post: $finish);

        Hook::register('Monolog\Logger', 'addRecord', pre: static function ($logger, array $params) use ($otel): void {
            if (self::$logging) {
                return;
            }
            self::$logging = true;
            try {
                $level = $params[0] instanceof \BackedEnum ? $params[0]->value : (int) $params[0];
                // Preserve useful messages and correlation; never export arbitrary context arrays.
                $message = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', (string) $params[1]);
                $message = preg_replace('/(password|authorization|token|secret)[=: ]+[^ ,;\s]+/i', '$1=[redacted]', $message);
                $otel->logger()->logRecordBuilder()
                    ->setBody(substr($message, 0, 8192))
                    ->setSeverityNumber(match (true) {
                        $level >= 500 => 21, $level >= 400 => 17, $level >= 300 => 13,
                        $level >= 200 => 9, default => 5,
                    })
                    ->setAttribute('log.logger', $logger->getName())
                    ->emit();
            } finally {
                self::$logging = false;
            }
        });
    }
}
