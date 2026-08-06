<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\Support\TraceHelper;

class TraceHelperTest extends TestCase
{
    public function test_default_ignore_paths_include_app_vendor(): void
    {
        $this->assertTrue(TraceHelper::shouldIgnoreFile(base_path('vendor/foo/bar.php')));
        $this->assertFalse(TraceHelper::shouldIgnoreFile(base_path('app/Http/Controllers/HomeController.php')));
    }

    public function test_absolute_ignore_paths_match_sidecar_vendor_trees(): void
    {
        config([
            'context-logging.trace.ignore_paths' => [
                'vendor',
                '/opt/extra-packages/vendor',
                '/opt/other-extra-packages/vendor',
            ],
        ]);

        $this->assertTrue(
            TraceHelper::shouldIgnoreFile('/opt/extra-packages/vendor/acme/pkg/src/Middleware/EmitContextMiddleware.php')
        );
        $this->assertTrue(
            TraceHelper::shouldIgnoreFile('/opt/other-extra-packages/vendor/acme/pkg/src/Middleware/EmitContextMiddleware.php')
        );
        $this->assertFalse(
            TraceHelper::shouldIgnoreFile('/opt/extra-packages/other/src/Thing.php')
        );
        $this->assertTrue(TraceHelper::shouldIgnoreFile(base_path('vendor/foo/bar.php')));
    }

    public function test_relative_ignore_paths_match_under_base_path(): void
    {
        config([
            'context-logging.trace.ignore_paths' => [
                'vendor',
                'extra-packages/vendor',
            ],
        ]);

        $this->assertTrue(
            TraceHelper::shouldIgnoreFile(base_path('extra-packages/vendor/acme/pkg/src/Thing.php'))
        );
        $this->assertFalse(
            TraceHelper::shouldIgnoreFile(base_path('extra-packages/src/Thing.php'))
        );
    }

    public function test_collapsed_trace_falls_back_to_vendor_frames_when_app_stack_is_empty(): void
    {
        config([
            'context-logging.trace.ignore_paths' => ['vendor'],
        ]);

        $lines = TraceHelper::getCollapsedTrace();

        // This test file lives under the package (ignored as ContextLogging / vendor
        // depending on install). The fallback must still return at least one frame
        // so framework-only call sites are not opaque in the explorer.
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/\.php:\d+$/', $line);
            $this->assertStringNotContainsString('TraceHelper.php', $line);
        }
    }
}
