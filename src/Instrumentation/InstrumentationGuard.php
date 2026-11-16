<?php
/**
 * This file is part of the Mumzworld_OpenTelemetry package.
 *
 * @author    Raj KB <rajendra.bhatta@mumzworld.com>
 * @copyright Copyright (c) 2025 MumzWorld (https://www.mumzworld.com)
 */
declare(strict_types=1);

namespace Mumzworld\OpenTelemetry\Instrumentation;

use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\SdkAutoloader;
use Throwable;

/**
 * Guards against inappropriate OpenTelemetry instrumentation in Magento
 */
class InstrumentationGuard
{
    /**
     * Instrumentation name used for SDK checks
     */
    private const INSTRUMENTATION_NAME = 'magento2';

    /**
     * List of URLs that should be excluded from instrumentation
     */
    private const EXCLUDED_URLS = [
        'health_check.php',
        'get.php',
        'static.php',
        'cron.php'
    ];

    /**
     * Development/testing scripts that should be excluded
     */
    private const EXCLUDED_SCRIPTS = [
        'phpstan',
        'phpunit'
    ];

    /**
     * Determines if the current context is eligible for OpenTelemetry instrumentation
     *
     * @return bool True if instrumentation should be applied, false otherwise
     * phpcs:disable Magento2.Functions.StaticFunction
     */
    public static function isInstrumentationEligible(): bool
    {
        try {
            if (!self::isInstrumentationEnabled() || self::isExcludedScript()) {
                return false;
            }

            return true;
        } catch (Throwable $e) {

            return false;
        }
    }

    /**
     * Checks if instrumentation is enabled and available
     *
     * @return bool
     */
    private static function isInstrumentationEnabled(): bool
    {
        return class_exists(Sdk::class)
            && !Sdk::isInstrumentationDisabled(self::INSTRUMENTATION_NAME)
            && \extension_loaded('opentelemetry')
            && SdkAutoloader::isEnabled()
            && !SdkAutoloader::isExcludedUrl();
    }

    /**
     * Checks if the current script is in the excluded list
     *
     * @return bool
     */
    private static function isExcludedScript(): bool
    {
        //phpcs:ignore
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        foreach (array_merge(self::EXCLUDED_SCRIPTS, self::EXCLUDED_URLS) as $script) {
            if (str_contains($scriptName, $script)) {
                return true;
            }
        }

        return false;
    }

    //phpcs:enable Magento2.Functions.StaticFunction
}
