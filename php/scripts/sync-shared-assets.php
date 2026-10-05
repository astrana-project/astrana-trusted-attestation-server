<?php

/**
 * Copies the monorepo's single-source shared inputs into the locations this implementation reads them
 * from at runtime (base_path('contract'|'schema'), resource_path(), public/). The source of truth is
 * ../shared, and the copies written here are git-ignored.
 *
 * Runs automatically after Composer autoload generation (see composer.json "post-autoload-dump") and is
 * also available on demand as `composer sync-shared`. Keeping the application reading from its own tree
 * means the built image is self-contained. It does not need ../shared at runtime, only at build.
 */
$root = dirname(__DIR__);               // php/
$shared = dirname($root).'/shared';   // ../shared

$map = [
    "$shared/contract/attribution.json" => "$root/contract/attribution.json",
    "$shared/contract/relationship-types.json" => "$root/contract/relationship-types.json",
    "$shared/schema/schema-postgres.sql" => "$root/schema/schema-postgres.sql",
    "$shared/schema/schema-mysql.sql" => "$root/schema/schema-mysql.sql",
    "$shared/schema/schema-mssql.sql" => "$root/schema/schema-mssql.sql",
    "$shared/ui/ui-strings.json" => "$root/resources/ui-strings.json",
    "$shared/ui/dist/trusted-attestation.css" => "$root/public/trusted-attestation.css",
    "$shared/ui/favicon.svg" => "$root/public/favicon.svg",
    "$shared/ui/theme-overrides.css" => "$root/public/theme-overrides.css",
];

$failed = false;
foreach ($map as $src => $dst) {
    if (! is_file($src)) {
        fwrite(STDERR, "sync-shared: missing source $src\n");
        $failed = true;

        continue;
    }
    if (! is_dir(dirname($dst))) {
        mkdir(dirname($dst), 0775, true);
    }
    if (! copy($src, $dst)) {
        fwrite(STDERR, "sync-shared: failed to copy $src -> $dst\n");
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo 'sync-shared: copied '.count($map)." shared files from ../shared\n";
