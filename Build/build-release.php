<?php
declare(strict_types=1);
require __DIR__ . '/check-release.php';
if (!class_exists(ZipArchive::class)) throw new RuntimeException('PHP ext-zip is required for packaging');
$distribution = $root . '/dist';
if (!is_dir($distribution) && !mkdir($distribution, 0775, true)) throw new RuntimeException('Cannot create dist');
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->isLink()) continue;
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('~^(?:vendor|var|public|dist|\.git|\.build|\.phpunit.cache|\.phpstan-cache|Documentation-GENERATED-temp)/~', $relative)) continue;
    if (in_array($relative, ['composer.lock', '.php-cs-fixer.cache'], true) || preg_match('~(?:^|/)(?:\.env(?:\..*)?|settings\.php|additional\.php)$~', $relative)) continue;
    $files[$relative] = $file->getPathname();
}
ksort($files);
$manifest = ['extension' => $key, 'version' => $version, 'artifacts' => []];
foreach (['extension', 'source'] as $kind) {
    $filename = $key . '-' . $version . ($kind === 'source' ? '-source' : '') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($distribution . '/' . $filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create ZIP');
    $included = [];
    foreach ($files as $relative => $path) {
        if ($kind === 'extension' && (preg_match('~^(?:Tests|Build|\.github)/~', $relative) || str_starts_with($relative, '.') || str_starts_with($relative, 'phpstan') || $relative === 'phpunit.xml')) continue;
        $top = explode('/', $relative)[0];
        $directories = $kind === 'source' ? ['Classes', 'Configuration', 'Resources', 'Documentation', 'Tests', 'Build', '.github'] : ['Classes', 'Configuration', 'Resources', 'Documentation'];
        $rootFiles = ['composer.json', 'ext_emconf.php', 'ext_localconf.php', 'ext_tables.sql', 'LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'CONTRIBUTING.md', 'RELEASE.md'];
        if ($kind === 'source') $rootFiles = array_merge($rootFiles, ['.editorconfig', '.gitignore', '.gitattributes', '.php-cs-fixer.dist.php', 'phpunit.xml', 'phpstan.neon', 'phpstan-13.neon', 'phpstan-14.neon']);
        if (!in_array($top, $directories, true) && !in_array($relative, $rootFiles, true)) continue;
        $content = file_get_contents($path);
        if ($content === false) throw new RuntimeException('Cannot read ' . $relative);
        if (!str_starts_with($relative, 'Resources/Public/Vendor/')) $content = str_replace("\r\n", "\n", $content);
        if (!$zip->addFromString($relative, $content)) throw new RuntimeException('Cannot package ' . $relative);
        $zip->setMtimeName($relative, 315532800);
        $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        $included[] = $relative;
    }
    if (!$zip->close()) throw new RuntimeException('Cannot finalize ZIP');
    $manifest['artifacts'][] = ['file' => $filename, 'sha256' => hash_file('sha256', $distribution . '/' . $filename), 'files' => $included];
    echo "Built {$filename} (" . count($included) . " files)\n";
}
file_put_contents($distribution . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
