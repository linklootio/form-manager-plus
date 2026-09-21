<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
function ensure(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$key = $composer['extra']['typo3/cms']['extension-key'];
$version = $composer['extra']['typo3/cms']['version'];
ensure(in_array($key, ['form_manager_plus', 'form_manager_plus_pro'], true), 'Unknown extension key');
ensure($composer['type'] === 'typo3-cms-extension', 'Invalid package type');
ensure($composer['license'] === 'GPL-2.0-or-later', 'Review licence metadata');
ensure(array_key_exists('providesPackages', $composer['extra']['typo3/cms']['Package']), 'Classic-mode declaration missing');
$metadata = json_decode(file_get_contents($root . '/composer.json'));
ensure(is_object($metadata->extra->{'typo3/cms'}->Package->providesPackages), 'Empty providesPackages must be an object');
$_EXTKEY = $key; $EM_CONF = []; require $root . '/ext_emconf.php';
ensure($EM_CONF[$key]['version'] === $version, 'Version mismatch');
ensure($composer['require']['typo3/cms-core'] === '~13.4.0 || ~14.3.0', 'Unexpected Core support range');
foreach (['LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'CONTRIBUTING.md', 'RELEASE.md', 'Documentation/Index.rst', 'Documentation/guides.xml', 'Resources/Public/Icons/Extension.svg'] as $file) ensure(is_file($root . '/' . $file), 'Missing ' . $file);
if ($key === 'form_manager_plus') {
    foreach (['FormTransfer', 'TransferValidator', 'ConfigurationInspector', 'FormOperations'] as $class) ensure(!is_file($root . '/Classes/Service/' . $class . '.php'), 'Pro implementation leaked into Lite: ' . $class);
    $labels = [];
    foreach (['locallang.xlf', 'de.locallang.xlf'] as $name) {
        $xml = simplexml_load_file($root . '/Resources/Private/Language/' . $name, SimpleXMLElement::class, LIBXML_NONET);
        ensure($xml !== false, 'Invalid XLF'); $ids = [];
        foreach ($xml->file->body->{'trans-unit'} as $unit) { $id = (string)$unit['id']; ensure(!isset($ids[$id]), 'Duplicate label ' . $id); $ids[$id] = true; }
        ksort($ids); $labels[] = array_keys($ids);
    }
    ensure($labels[0] === $labels[1], 'Translation keys differ');
    $js = file_get_contents($root . '/Resources/Public/JavaScript/translations.js');
    preg_match_all('/"(ui_[a-f0-9]+)":/', $js, $matches);
    foreach ($matches[1] as $id) ensure(in_array('fmp.' . $id, $labels[0], true), 'Missing UI translation ' . $id);
} else ensure(isset($composer['require']['linkloot/form-manager-plus']), 'Pro must depend on Lite');
echo "PASS {$key} {$version}: metadata, documentation, licence, edition boundary and translations\n";
