<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$major = (new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion();
$config = is_file(dirname(__DIR__) . '/phpstan-' . $major . '.neon') ? 'phpstan-' . $major . '.neon' : 'phpstan.neon';
passthru(escapeshellarg(PHP_BINARY) . ' vendor/bin/phpstan analyse -c ' . escapeshellarg($config) . ' --no-progress', $status);
exit($status);
