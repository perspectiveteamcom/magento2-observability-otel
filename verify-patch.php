<?php
declare(strict_types=1);
// Run from the Magento root, without loading Magento or its bootstrap.
$vendor = getcwd() . '/vendor';
$manifest = json_decode(file_get_contents(__DIR__ . '/patches/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['files'] as $file => $expected) {
    $path = $vendor . '/' . $manifest['package'] . '/' . $file;
    if (!is_file($path) || hash_file('sha256', $path) !== $expected) {
        fwrite(STDERR, 'FAIL Magento REST instrumentation patch: ' . $file . PHP_EOL);
        exit(1);
    }
}
echo "PASS Magento REST instrumentation patch\n";
