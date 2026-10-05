<?php

/**
 * Writes public/THIRD-PARTY-NOTICES.txt, which the licence page links to. Runs after every Composer install
 * (see composer.json "post-autoload-dump") and again while the container image is built.
 *
 *   php scripts/generate-third-party-notices.php [--debian-packages=FILE] [--lock=FILE] [--vendor=DIR]
 *       [--attribution=FILE] [--bundled=DIR] [--doc-root=DIR] [--output=FILE]
 *
 * The notices open with the project's name and its own copyright line, from the contract's attribution.json.
 * Then come the notices of the components bundled into the shared inputs rather than installed by Composer,
 * Bootstrap in the stylesheet, from shared/third-party/bundled, which the .NET and Java implementations
 * read too. Then, for every runtime package in the Composer lock file (its "packages", not its
 * "packages-dev"), the name, version, declared licences and source, and the licence file as the package
 * ships it in vendor/, which carries the copyright lines. With --debian-packages, a file naming one Debian
 * package per line, which the image build passes, the same follows for each of those packages from its
 * folder under /usr/share/doc: the copyright file, and any licence or notice file beside it, such as the
 * licence of Microsoft's ODBC driver in an image built with SQL Server support.
 *
 * Most of these licences make the notice a condition of redistribution, so a package with no licence file
 * stops the script, and the install or build with it, before anything is written.
 *
 * The paths default to this folder's composer.lock, vendor/, contract/attribution.json and
 * public/THIRD-PARTY-NOTICES.txt, to ../shared/third-party/bundled and to /usr/share/doc.
 */
$root = dirname(__DIR__);
$option = static function (string $name, ?string $default): ?string {
    $value = getopt('', ["$name:"])[$name] ?? $default;

    return is_array($value) ? end($value) : $value;
};
$lockPath = $option('lock', $root.'/composer.lock');
$vendorPath = $option('vendor', $root.'/vendor');
$attributionPath = $option('attribution', $root.'/contract/attribution.json');
$bundledPath = $option('bundled', dirname($root).'/shared/third-party/bundled');
$docRoot = $option('doc-root', '/usr/share/doc');
$debianListPath = $option('debian-packages', null);
$outputPath = $option('output', $root.'/public/THIRD-PARTY-NOTICES.txt');

$rule = str_repeat('-', 80);

/** @return list<string> the files in $folder whose names say they hold a licence, copyright or notice */
$licenceFiles = static function (string $folder): array {
    $names = is_dir($folder) ? scandir($folder) : [];
    $matching = preg_grep('/^(licen[cs]e|copying|copyright|notice)([.-]|$)/i', $names) ?: [];
    sort($matching);

    return array_map(static fn (string $name): string => "$folder/$name", array_values($matching));
};

$stop = static function (string $message): never {
    fwrite(STDERR, "generate-third-party-notices: $message\n");
    exit(1);
};

$text = static fn (string $file): string => rtrim(str_replace("\r\n", "\n", (string) file_get_contents($file)));

/** One package's entry: its heading lines, then the text of each licence file. */
$entry = static fn (array $heading, array $files): string => implode("\n", array_filter($heading))."\n\n"
    .implode("\n\n", array_map($text, $files));

$bundled = glob("$bundledPath/*.txt") ?: [];
sort($bundled);
$entries = array_map($text, $bundled);

foreach (json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR)['packages'] as $package) {
    $files = $licenceFiles("$vendorPath/{$package['name']}");
    if ($files === []) {
        $stop("{$package['name']} has no licence file in $vendorPath");
    }
    $entries[] = $entry([
        "{$package['name']} {$package['version']}",
        'Licence: '.implode(' or ', $package['license'] ?? ['not declared']),
        isset($package['source']['url']) ? "Source: {$package['source']['url']}" : null,
    ], $files);
}

$debianPackages = $debianListPath === null ? [] : array_filter(array_map('trim', file($debianListPath) ?: []));
foreach ($debianPackages as $name) {
    $files = $licenceFiles("$docRoot/$name");
    if ($files === []) {
        $stop("the Debian package $name has no copyright file in $docRoot/$name");
    }
    $entries[] = $entry([$name, 'Debian package added by the container image'], $files);
}

$attribution = json_decode((string) file_get_contents($attributionPath), true, 512, JSON_THROW_ON_ERROR);
$heading = "{$attribution['project_name']}, PHP implementation\n{$attribution['license']['notice']}\n\n"
    ."This software includes the third-party components listed below, each under its own licence. Each entry\n"
    ."gives the component's version, its licence and where its source code can be obtained, followed by the\n"
    ."licence and copyright texts the component ships. This file is generated from the packages Composer\n"
    ."installed and, in the container image, the Debian packages the image adds. PHP itself and the rest of\n"
    ."the Debian system come from the official php image, whose own notices are under /usr/share/doc.\n";

file_put_contents($outputPath, $heading.implode('', array_map(static fn (string $e): string => "\n$rule\n$e\n", $entries)));

echo 'generate-third-party-notices: wrote '.count($entries)." entries to $outputPath\n";
