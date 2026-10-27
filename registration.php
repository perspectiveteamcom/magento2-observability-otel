<?php
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Perspective_ObservabilityOtel', __DIR__);
// The optional module must not load its SDK or PHP 8 classes on a legacy runtime.
if (PHP_VERSION_ID >= 80300) {
    \Perspective\ObservabilityOtel\Bootstrap::register();
}
