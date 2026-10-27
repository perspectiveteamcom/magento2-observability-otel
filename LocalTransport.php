<?php
declare(strict_types=1);

namespace Perspective\ObservabilityOtel;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

/** One failed attempt opens a process-local, shared-across-signals circuit for 5s. */
final class LocalTransport implements TransportInterface
{
    private static array $retryAt = [];

    public function __construct(private TransportInterface $delegate, private string $origin) {}

    public function contentType(): string
    {
        return $this->delegate->contentType();
    }

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ((self::$retryAt[$this->origin] ?? 0) > microtime(true)) {
            return new ErrorFuture(new \RuntimeException('Local telemetry Collector circuit is open'));
        }
        try {
            return $this->delegate->send($payload, $cancellation)->catch(function (\Throwable $error): never {
                self::$retryAt[$this->origin] = microtime(true) + 5;
                throw $error;
            });
        } catch (\Throwable $error) {
            // Some transports fail before returning a Future (for example during
            // serialization or request construction). Apply the same backoff.
            self::$retryAt[$this->origin] = microtime(true) + 5;
            return new ErrorFuture($error);
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->delegate->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->delegate->forceFlush($cancellation);
    }
}
