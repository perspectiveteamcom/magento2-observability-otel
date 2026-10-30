<?php
declare(strict_types=1);
namespace Perspective\ObservabilityOtel;

use OpenTelemetry\SDK\Trace\SpanExporter\SpanExporterFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

final class SqlSpanExporterFactory implements SpanExporterFactoryInterface
{
    public function create(): SpanExporterInterface
    {
        return new SqlSpanExporter((new \OpenTelemetry\Contrib\Otlp\SpanExporterFactory())->create());
    }
}
