#!/usr/bin/env php
<?php

/**
 * Sidecar helper: generate instrumented Guzzle Client sources and remap Composer autoload.
 *
 * Preferred (automatic): allow the Composer plugin so dump-autoload keeps the patch applied:
 *
 *   composer config allow-plugins.michael4d45/context-logging true
 *   composer dump-autoload
 *
 * Manual fallback (from the Laravel app root):
 *   php vendor/bin/install-guzzle-patch.php
 *
 * Then set:
 *   CONTEXT_LOG_HTTP_ENABLED=true
 *   CONTEXT_LOG_HTTP_GUZZLE_PATCH=true
 */

use Michael4d45\ContextLogging\Guzzle\PatchInstaller;

$autoloadCandidates = [
    dirname(__DIR__, 3).'/autoload.php',
    dirname(__DIR__).'/vendor/autoload.php',
];

$loaded = false;
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        $loaded = true;
        break;
    }
}

if (! $loaded) {
    $packageAutoload = dirname(__DIR__).'/vendor/autoload.php';
    if (is_file($packageAutoload)) {
        require $packageAutoload;
        $loaded = true;
    }
}

if (! $loaded) {
    fwrite(STDERR, "Unable to locate Composer autoload.php\n");
    exit(1);
}

if (! class_exists(PatchInstaller::class)) {
    require dirname(__DIR__).'/src/Guzzle/PatchInstaller.php';
}

$ok = (new PatchInstaller())->install(preAutoloadDump: false);

if (! $ok) {
    fwrite(STDERR, "Guzzle patch install skipped (guzzlehttp/guzzle not installed).\n");
    exit(0);
}

fwrite(STDOUT, "Guzzle Client patch ready. Enable with CONTEXT_LOG_HTTP_GUZZLE_PATCH=true\n");
fwrite(STDOUT, "Tip: allow-plugins.michael4d45/context-logging=true so composer dump-autoload keeps this applied.\n");
