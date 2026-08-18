<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\Guzzle\ClientPatch;
use Michael4d45\ContextLogging\Guzzle\PatchInstaller;
use Michael4d45\ContextLogging\HttpClientInstrumentation;
use ReflectionMethod;

/**
 * Regression coverage for the sidecar-loaded install path: when this package is bind-mounted
 * into a target app as a classmap-only sidecar (not a real Composer dependency — no psr-4
 * entry for our own namespace), every class our Guzzle Client patch can reach at runtime must
 * still be discoverable, or GuzzleHttp\Client fatals the moment anything constructs one.
 */
class PatchInstallerClassmapTest extends TestCase
{
    public function test_scan_package_classmap_finds_every_declared_class(): void
    {
        $installer = new PatchInstaller();
        $method = new ReflectionMethod($installer, 'scanPackageClassmap');

        $classes = $method->invoke($installer);

        // The classes ClientPatch's patched GuzzleHttp\Client constructor directly needs must
        // be present, or the exact "Class ... not found" fatal this test guards against recurs.
        $this->assertArrayHasKey(ClientPatch::class, $classes);
        $this->assertArrayHasKey(HttpClientInstrumentation::class, $classes);

        foreach ($classes as $fqcn => $path) {
            $this->assertFileExists($path, "Scanned entry for {$fqcn} points at a missing file.");
        }
    }

    public function test_scan_package_classmap_excludes_generated_guzzle_stubs(): void
    {
        $installer = new PatchInstaller();
        $method = new ReflectionMethod($installer, 'scanPackageClassmap');

        $classes = $method->invoke($installer);

        // Generated/Client.php and Generated/UnpatchedClient.php declare classes under the
        // GuzzleHttp namespace and are upserted separately by patchVendorAutoloadFiles();
        // scanning them here too would produce a conflicting/duplicate classmap entry.
        $this->assertArrayNotHasKey(\GuzzleHttp\Client::class, $classes);
        $this->assertArrayNotHasKey(\GuzzleHttp\UnpatchedClient::class, $classes);
    }

    public function test_extract_fqcn_reads_namespace_and_class_name(): void
    {
        $installer = new PatchInstaller();
        $method = new ReflectionMethod($installer, 'extractFqcn');

        $fqcn = $method->invoke($installer, dirname(__DIR__).'/src/Guzzle/ClientPatch.php');

        $this->assertSame(ClientPatch::class, $fqcn);
    }
}
