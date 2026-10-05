<?php

/**
 * Adds to sbom.json what the container image installs outside Composer, after the CycloneDX generator has
 * written it.
 *
 *   php scripts/add-native-sbom-components.php [Dockerfile] [sbom.json]
 *
 * The generator reads only the Composer lock file, so two kinds of component would be missing from the bill
 * of materials:
 *
 * - The Debian packages the Dockerfile adds to the published image, for the PostgreSQL driver and intl.
 *   Debian does not let an image pin these, so their versions are those the image was last built with,
 *   recorded below with the Debian release they came from. The release is checked against the one the
 *   Dockerfile's runtime image names (php:8.4-cli-trixie), and a different release is refused until the
 *   image has been rebuilt and the list below brought up to date from it.
 * - Microsoft's ODBC Driver 18 for SQL Server and the sqlsrv and pdo_sqlsrv extensions from PECL, which only
 *   an image built with WITH_SQLSRV=true installs. The published image supports MySQL and PostgreSQL only,
 *   so these are listed with the optional scope, which CycloneDX defines as not installed, and the
 *   application does not depend on them. Their versions are read from the Dockerfile's SQLSRV_VERSION,
 *   PDO_SQLSRV_VERSION and MSODBCSQL18_VERSION arguments, so the two cannot drift. A Dockerfile that turns
 *   SQL Server on by default is refused, because these entries would then misdescribe the image.
 *
 * Running it again replaces its own entries, so it is safe to run after every regeneration. A refused
 * Dockerfile leaves the file as it was. The paths default to this folder's Dockerfile and sbom.json.
 */
$root = dirname(__DIR__);
$dockerfilePath = $argv[1] ?? $root.'/Dockerfile';
$sbomPath = $argv[2] ?? $root.'/sbom.json';

$debian = [
    'codename' => 'trixie',
    'version' => '13',
    // Name, the version the image was last built with, the licence its Debian copyright file declares, and
    // what it is for.
    'packages' => [
        ['libicu76', '76.1-4', 'MIT', 'International Components for Unicode, the library intl uses'],
        ['libpq5', '17.11-0+deb13u1', 'PostgreSQL', 'PostgreSQL client library, the library pdo_pgsql uses'],
    ],
];

$refuse = static function (string $message) use ($dockerfilePath): never {
    fwrite(STDERR, "add-native-sbom-components: $dockerfilePath $message\n");
    exit(1);
};

$dockerfile = (string) file_get_contents($dockerfilePath);

preg_match_all('/^FROM (\S+)/m', $dockerfile, $images);
$runtimeImage = end($images[1]) ?: '';
if (! str_ends_with($runtimeImage, '-'.$debian['codename'])) {
    $refuse("builds on $runtimeImage, not on Debian {$debian['codename']}, the release the package versions here were recorded from");
}

if (preg_match('/^ARG WITH_SQLSRV=false$/m', $dockerfile) !== 1) {
    $refuse('does not leave SQL Server out by default (ARG WITH_SQLSRV=false)');
}

$pins = [];
foreach (['SQLSRV_VERSION', 'PDO_SQLSRV_VERSION', 'MSODBCSQL18_VERSION'] as $argument) {
    if (preg_match('/^ARG '.$argument.'=(\S+)$/m', $dockerfile, $match) !== 1) {
        $refuse("does not pin $argument");
    }
    $pins[$argument] = $match[1];
}

$distro = 'distro=debian-'.$debian['version'];
$notPublished = 'Not in the published image, which supports MySQL and PostgreSQL only. Installed only in an image built with --build-arg WITH_SQLSRV=true.';

$debianPackages = array_map(static fn (array $package): array => [
    'bom-ref' => "deb/$package[0]-$package[1]",
    'type' => 'library',
    'supplier' => ['name' => 'Debian'],
    'name' => $package[0],
    'version' => $package[1],
    'description' => "$package[3], installed from Debian in the container image",
    'licenses' => [['expression' => $package[2]]],
    'purl' => "pkg:deb/debian/$package[0]@$package[1]?arch=amd64&$distro",
], $debian['packages']);

$extension = static fn (string $name, string $version): array => [
    'bom-ref' => "pecl/$name-$version",
    'type' => 'library',
    'supplier' => ['name' => 'Microsoft'],
    'name' => $name,
    'version' => $version,
    'description' => "PHP extension for Microsoft SQL Server from PECL. $notPublished",
    'scope' => 'optional',
    'licenses' => [['license' => ['id' => 'MIT']]],
    'purl' => "pkg:generic/$name@$version?download_url=https://pecl.php.net/get/$name-$version.tgz",
    'externalReferences' => [['type' => 'distribution', 'url' => "https://pecl.php.net/package/$name"]],
];

$driverRef = 'deb/msodbcsql18-'.$pins['MSODBCSQL18_VERSION'];
$sqlServer = [
    $extension('sqlsrv', $pins['SQLSRV_VERSION']),
    $extension('pdo_sqlsrv', $pins['PDO_SQLSRV_VERSION']),
    [
        'bom-ref' => $driverRef,
        'type' => 'library',
        'supplier' => ['name' => 'Microsoft'],
        'name' => 'msodbcsql18',
        'version' => $pins['MSODBCSQL18_VERSION'],
        'description' => "Microsoft ODBC Driver 18 for SQL Server from Microsoft's package repository. $notPublished",
        'scope' => 'optional',
        'licenses' => [['license' => ['name' => 'Microsoft Software License Terms for Microsoft ODBC Driver 18 for SQL Server', 'url' => 'https://aka.ms/odbc18eula']]],
        'purl' => 'pkg:deb/microsoft/msodbcsql18@'.$pins['MSODBCSQL18_VERSION']."?arch=amd64&$distro",
        'externalReferences' => [['type' => 'distribution', 'url' => "https://packages.microsoft.com/debian/{$debian['version']}/prod"]],
    ],
];
$components = [...$debianPackages, ...$sqlServer];

// Objects stay objects, so the generator's own structure is written back exactly as it was.
$bom = json_decode((string) file_get_contents($sbomPath), false, 512, JSON_THROW_ON_ERROR);
$ours = static fn (string $ref): bool => str_starts_with($ref, 'pecl/') || str_starts_with($ref, 'deb/');
$rootRef = $bom->metadata->component->{'bom-ref'};

$bom->components = array_values(array_filter($bom->components, static fn (object $c): bool => ! $ours($c->{'bom-ref'})));
array_push($bom->components, ...array_map(static fn (array $c): object => json_decode((string) json_encode($c)), $components));

// The application depends on the Debian packages and not on the optional SQL Server components, which
// depend only on each other.
$debianRefs = array_column($debianPackages, 'bom-ref');
sort($debianRefs);
$bom->dependencies = array_values(array_filter($bom->dependencies, static fn (object $d): bool => ! $ours($d->ref)));
foreach ($bom->dependencies as $dependency) {
    if ($dependency->ref === $rootRef) {
        $dependency->dependsOn = array_values(array_filter($dependency->dependsOn ?? [], static fn (string $ref): bool => ! $ours($ref)));
        array_push($dependency->dependsOn, ...$debianRefs);
    }
}
foreach ($debianRefs as $ref) {
    $bom->dependencies[] = (object) ['ref' => $ref];
}
$bom->dependencies[] = (object) ['ref' => $sqlServer[0]['bom-ref'], 'dependsOn' => [$driverRef]];
$bom->dependencies[] = (object) ['ref' => $sqlServer[1]['bom-ref'], 'dependsOn' => [$driverRef]];
$bom->dependencies[] = (object) ['ref' => $driverRef];

file_put_contents($sbomPath, json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo 'add-native-sbom-components: added '.implode(', ', array_column($components, 'bom-ref'))."\n";
