<?php
declare(strict_types=1);
namespace Perspective\ObservabilityOtel;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

final class SqlSpanExporter implements SpanExporterInterface
{
    public function __construct(private SpanExporterInterface $delegate) {}
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $safe = [];
        foreach ($batch as $span) {
            $a = $span->getAttributes();
            $safe[] = $a->has('db.query.text') || $a->has('db.statement') ? new SqlSpanData($span) : $span;
        }
        return $this->delegate->export($safe, $cancellation);
    }
    public function shutdown(?CancellationInterface $cancellation = null): bool { return $this->delegate->shutdown($cancellation); }
    public function forceFlush(?CancellationInterface $cancellation = null): bool { return $this->delegate->forceFlush($cancellation); }
}
