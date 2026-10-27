<?php
declare(strict_types=1);

namespace Perspective\ObservabilityOtel;

/** Keep exceptions in our callbacks out of application responses. */
final class Hook
{
    private static bool $reported = false;

    public static function register(string $class, string $method, ?\Closure $pre = null, ?\Closure $post = null): void
    {
        $guard = static function (?\Closure $callback): ?\Closure {
            if ($callback === null) {
                return null;
            }
            return static function (...$arguments) use ($callback) {
                try {
                    $callback(...$arguments);
                } catch (\Throwable $error) {
                    if (!self::$reported) {
                        self::$reported = true;
                        try {
                            @error_log('Perspective_ObservabilityOtel callback skipped: ' . $error::class);
                        } catch (\Throwable $loggingError) {
                            // A custom error handler or broken log destination must also stay isolated.
                        }
                    }
                }
                // OTel hook return values can replace application arguments or results.
                return null;
            };
        };
        \OpenTelemetry\Instrumentation\hook($class, $method, pre: $guard($pre), post: $guard($post));
    }
}
