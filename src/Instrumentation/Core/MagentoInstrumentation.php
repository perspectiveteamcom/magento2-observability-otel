<?php
/**
 * This file is part of the Mumzworld_OpenTelemetry package.
 *
 * @author    Raj KB <rajendra.bhatta@mumzworld.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com)
 */
declare(strict_types=1);

namespace Mumzworld\OpenTelemetry\Instrumentation\Core;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Http;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Mumzworld\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class MagentoInstrumentation extends AbstractInstrumentation
{
    public const INSTRUMENTATION_NAME = 'io.opentelemetry.contrib.php.magento';

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
        self::instrumentBootstrapRun();
        self::instrumentHttpResponse();
        self::instrumentAppTerminate();
        self::instrumentHttpCatchException();
    }

    /** Record the HTTP response on the active request span. */
    private static function instrumentHttpResponse(): void
    {
        // Exception handlers can send their response after launch() has thrown.
        // Capture that response while the request span is still active too.
        hook(
            HttpResponse::class,
            'sendResponse',
            static function (HttpResponse $response) {
                self::recordHttpStatus($response);
            },
        );
        hook(
            Http::class,
            'launch',
            null,
            static function (Http $subject, array $params, mixed $response, ?Throwable $exception) {
                if (!$response instanceof ResponseInterface || !method_exists($response, 'getHttpResponseCode')) {
                    return;
                }
                self::recordHttpStatus($response);
            },
        );
    }

    private static function recordHttpStatus(ResponseInterface $response): void
    {
        $code = (int) $response->getHttpResponseCode();
        if ($code < 100 || $code > 599) {
            return;
        }
        $span = Span::getCurrent();
        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $code);
        if ($code >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public static function instrumentBootstrapRun(): void
    {
        hook(
            Bootstrap::class,
            'run',
            static function (
                Bootstrap   $subject,
                array       $params,
                string      $class,
                string      $function,
                ?string     $filename,
                ?int        $lineno,
            ) {
                $spanName = 'Bootstrap::run()';
                $builder = self::createSpanBuilder(
                    $spanName,
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )->setSpanKind(SpanKind::KIND_INTERNAL);

                // The native SDK does not create an HTTP server span on its
                // own. Join the incoming trace when there is no server span
                // already active (for example one supplied by the distro).
                $parent = null;
                if (PHP_SAPI !== 'cli' && !Span::getCurrent()->getContext()->isValid()) {
                    $uri = $_SERVER['REQUEST_URI'] ?? '';
                    if (is_string($uri)) {
                        $path = explode('?', $uri, 2)[0];
                        if (str_starts_with($path, '/') && strlen($path) <= 2048 && !preg_match('/[\x00-\x1f\x7f]/', $path)) {
                            $builder->setAttribute('url.path', $path);
                        }
                    }
                    $carrier = [];
                    foreach (Globals::propagator()->fields() as $field) {
                        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $field));
                        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                            $carrier[$field] = $_SERVER[$key];
                        }
                    }
                    $parent = Globals::propagator()->extract($carrier);
                    $builder->setSpanKind(SpanKind::KIND_SERVER);
                }

                self::startSpanAndAttachToContext($builder, $parent);
            },
            static function (
                Bootstrap   $subject,
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
    private static function instrumentAppTerminate(): void
    {
        hook(
            Bootstrap::class,
            'terminate',
            static function (
                Bootstrap $bootstrap,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno
            ) {
                $scope = Context::storage()->scope();
                $span = Span::getCurrent();
                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, "500");
                $span->recordException($params[0]);
                $span->setStatus(StatusCode::STATUS_ERROR, $params[0]->getMessage());
                $scope->detach();
                $span->end();
            }
        );
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private static function instrumentHttpCatchException(): void
    {

        hook(
            Http::class,
            'catchException',
            null,
            static function (
                Http $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception,
            ) {
                $httpException = $params[1] ?? $exception;
                self::endSpan($httpException);
            },
        );
    }
    //phpcs:enable Magento2.Functions.StaticFunction
}
