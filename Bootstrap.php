<?php
declare(strict_types=1);

namespace Perspective\ObservabilityOtel;

/** Runs during Composer module registration, before application dispatch. */
final class Bootstrap
{
    private static bool $registered = false;

    private static function magentoRoot(): ?string
    {
        $directory = __DIR__;
        while (dirname($directory) !== $directory) {
            if (is_file($directory . '/app/etc/config.php')) { return $directory; }
            $directory = dirname($directory);
        }
        return null;
    }

    public static function register(): void
    {
        if (self::$registered || !extension_loaded('opentelemetry')
            || !filter_var(getenv('OTEL_PHP_AUTOLOAD_ENABLED'), FILTER_VALIDATE_BOOLEAN)
            || filter_var(getenv('OTEL_SDK_DISABLED'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        try {
            $root = defined('BP') ? BP : self::magentoRoot();
            if ($root === null) { return; }
            $configFile = $root . '/app/etc/config.php';
            $config = is_file($configFile) ? require $configFile : [];
            if (empty($config['modules']['Perspective_ObservabilityOtel'])
                || !class_exists(\OpenTelemetry\SDK\Registry::class)) {
                return;
            }
            self::$registered = true;
            \OpenTelemetry\SDK\Registry::registerTransportFactory('http', LocalTransportFactory::class, true);
            \OpenTelemetry\SDK\Registry::registerSpanExporterFactory('otlp', SqlSpanExporterFactory::class, true);
            // Add query shape to execute/fetch spans before the exporter removes literal values.
            if (getenv('OTEL_PHP_INSTRUMENTATION_PDO_DISTRIBUTE_STATEMENT_TO_LINKED_SPANS') === false) {
                putenv('OTEL_PHP_INSTRUMENTATION_PDO_DISTRIBUTE_STATEMENT_TO_LINKED_SPANS=true');
            }
            RedisInstrumentation::register();
            Instrumentation::register();
            \Perspective\Observability\Profiler::register();
        } catch (\Throwable $error) {
            // Never turn an observability initialization failure into a broken response.
            try {
                @error_log('Perspective_ObservabilityOtel initialization skipped: ' . $error::class);
            } catch (\Throwable $loggingError) {
                // Keep a failing application error handler out of bootstrap too.
            }
        }
    }
}
