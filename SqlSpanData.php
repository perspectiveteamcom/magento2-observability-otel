<?php
declare(strict_types=1);
namespace Perspective\ObservabilityOtel;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\StatusDataInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;

/** Preserve IDs, parentage, timing and status; replace only SQL payload and label. */
final class SqlSpanData implements SpanDataInterface
{
    private AttributesInterface $attributes;
    private string $name;
    public function __construct(private SpanDataInterface $source)
    {
        $a = $source->getAttributes()->toArray();
        $sql = $a['db.query.text'] ?? $a['db.statement'] ?? '';
        unset($a['db.query.text'], $a['db.statement']);
        $normalized = [];
        if (getenv('OBSERVABILITY_SQL_DETAILS') !== 'false' && is_string($sql)) {
            try { $normalized = SqlNormalizer::normalize($sql); } catch (\Throwable $ignored) {}
        }
        $a = array_merge($a, $normalized);
        $this->attributes = new Attributes($a, $source->getAttributes()->getDroppedAttributesCount());
        $this->name = $source->getName();
        if (isset($normalized['db.query.summary']) && str_starts_with($this->name, 'PDO')) {
            $this->name .= ' ' . $normalized['db.query.summary'];
        }
    }
    public function getName(): string { return $this->name; }
    public function getAttributes(): AttributesInterface { return $this->attributes; }
    public function getKind(): int { return $this->source->getKind(); }
    public function getContext(): API\SpanContextInterface { return $this->source->getContext(); }
    public function getParentContext(): API\SpanContextInterface { return $this->source->getParentContext(); }
    public function getTraceId(): string { return $this->source->getTraceId(); }
    public function getSpanId(): string { return $this->source->getSpanId(); }
    public function getParentSpanId(): string { return $this->source->getParentSpanId(); }
    public function getStatus(): StatusDataInterface { return $this->source->getStatus(); }
    public function getStartEpochNanos(): int { return $this->source->getStartEpochNanos(); }
    public function getEvents(): array { return $this->source->getEvents(); }
    public function getLinks(): array { return $this->source->getLinks(); }
    public function getEndEpochNanos(): int { return $this->source->getEndEpochNanos(); }
    public function hasEnded(): bool { return $this->source->hasEnded(); }
    public function getInstrumentationScope(): InstrumentationScopeInterface { return $this->source->getInstrumentationScope(); }
    public function getResource(): ResourceInfo { return $this->source->getResource(); }
    public function getTotalDroppedEvents(): int { return $this->source->getTotalDroppedEvents(); }
    public function getTotalDroppedLinks(): int { return $this->source->getTotalDroppedLinks(); }
}
