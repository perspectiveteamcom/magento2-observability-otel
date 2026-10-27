<?php
declare(strict_types=1);
namespace Perspective\ObservabilityOtel\Cron;

use Magento\Framework\App\ResourceConnection;
use OpenTelemetry\API\Globals;

final class Heartbeat
{
    public function __construct(private ResourceConnection $resource) {}

    public function execute(): void
    {
        try {
            if (!class_exists(Globals::class) || filter_var(getenv('OTEL_SDK_DISABLED'), FILTER_VALIDATE_BOOLEAN)
                || !filter_var(getenv('OTEL_PHP_AUTOLOAD_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
                return;
            }
            $meter = Globals::meterProvider()->getMeter('perspective.magento', '0.1.0');
            $meter->createGauge('magento.cron.heartbeat', 's')->record(time());
            $connection = $this->resource->getConnection();
            $table = $connection->quoteIdentifier($this->resource->getTableName('cron_schedule'));
            $rows = $connection->fetchPairs("SELECT status,COUNT(*) FROM {$table} WHERE scheduled_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY GROUP BY status");
            $gauge = $meter->createGauge('magento.cron.jobs');
            foreach (['pending', 'running', 'success', 'missed', 'error'] as $status) {
                $gauge->record((int) ($rows[$status] ?? 0), ['status' => $status]);
            }
            Globals::meterProvider()->forceFlush();
        } catch (\Throwable $error) {
            try {
                @error_log('Perspective_ObservabilityOtel heartbeat skipped: ' . $error::class);
            } catch (\Throwable $loggingError) {
                // Monitoring must not change the result of the surrounding cron runner.
            }
        }
    }
}
