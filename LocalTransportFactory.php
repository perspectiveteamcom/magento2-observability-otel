<?php
declare(strict_types=1);

namespace Perspective\ObservabilityOtel;

use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;

/** The local Collector owns retries and durable queues; PHP must not block on them. */
final class LocalTransportFactory implements TransportFactoryInterface
{
    public function create(
        string $endpoint,
        string $contentType,
        array $headers = [],
        $compression = null,
        float $timeout = 10.,
        int $retryDelay = 100,
        int $maxRetries = 3,
        ?string $cacert = null,
        ?string $cert = null,
        ?string $key = null,
    ): TransportInterface {
        $transport = (new PsrTransportFactory())->create(
            $endpoint, $contentType, $headers, $compression === 'none' ? null : $compression,
            $timeout > 0 ? min($timeout, 0.25) : 0.25, 0, 0, $cacert, $cert, $key,
        );
        $parts = parse_url($endpoint);
        $origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ':' . ($parts['port'] ?? '');

        return new LocalTransport($transport, $origin);
    }
}
