<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling\Adapters;

use Michael4d45\ContextLogging\Profiling\Contracts\ProfilerAdapter;
use Michael4d45\ContextLogging\Profiling\ProfileRef;

/**
 * Detects an active Xdebug profiler run and attaches the cachegrind path.
 */
final class XdebugAdapter implements ProfilerAdapter
{
    public function name(): string
    {
        return 'xdebug';
    }

    public function detect(): ?ProfileRef
    {
        if (! extension_loaded('xdebug')) {
            return null;
        }

        if (! $this->isProfilingMode()) {
            return null;
        }

        $path = $this->resolveProfilerPath();

        // Profiling mode can be enabled without a file yet (trigger not fired).
        if ($path === null && ! $this->looksActivelyProfiling()) {
            return null;
        }

        $outputDir = ini_get('xdebug.output_dir');
        $outputDir = is_string($outputDir) && $outputDir !== '' ? $outputDir : '/tmp';

        return new ProfileRef(
            vendor: 'xdebug',
            enabled: true,
            profileId: $path !== null ? basename($path) : null,
            path: $path,
            url: null,
            meta: [
                'mode' => $this->modeString(),
                'output_dir' => $outputDir,
            ],
        );
    }

    private function resolveProfilerPath(): ?string
    {
        if (function_exists('xdebug_get_profiler_filename')) {
            try {
                $filename = xdebug_get_profiler_filename();
                if (is_string($filename) && $filename !== '') {
                    return $filename;
                }
            } catch (\Throwable) {
                // fall through to predicted path when this request is profiling
            }
        }

        if ($this->looksActivelyProfiling()) {
            return $this->predictProfilerPath();
        }

        return null;
    }

    private function predictProfilerPath(): ?string
    {
        $outputDir = ini_get('xdebug.output_dir');
        $outputDir = is_string($outputDir) && $outputDir !== '' ? $outputDir : '/tmp';

        $pattern = ini_get('xdebug.profiler_output_name');
        $pattern = is_string($pattern) && $pattern !== '' ? $pattern : 'cachegrind.out.%p';

        $script = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? 'script';
        $script = is_string($script) ? $script : 'script';

        $replacements = [
            '%p' => (string) getmypid(),
            '%h' => (string) (gethostname() ?: 'unknown'),
            '%r' => (string) random_int(0, 0xffff_ffff),
            '%s' => pathinfo($script, PATHINFO_FILENAME) ?: 'script',
            '%t' => (string) time(),
            '%u' => str_replace('\\', '_', pathinfo($script, PATHINFO_FILENAME) ?: 'script'),
        ];

        $filename = strtr($pattern, $replacements);

        return rtrim(str_replace('\\', '/', $outputDir), '/').'/'.ltrim($filename, '/');
    }

    private function isProfilingMode(): bool
    {
        if (function_exists('xdebug_info')) {
            try {
                $modes = xdebug_info('mode');
                if (is_array($modes) && in_array('profile', $modes, true)) {
                    return true;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        $mode = ini_get('xdebug.mode');
        if (is_string($mode) && str_contains($mode, 'profile')) {
            return true;
        }

        return (bool) ini_get('xdebug.profiler_enable');
    }

    private function looksActivelyProfiling(): bool
    {
        // Trigger cookie / env indicates this request likely started a profile.
        if (! empty($_ENV['XDEBUG_TRIGGER']) || ! empty($_GET['XDEBUG_TRIGGER']) || ! empty($_COOKIE['XDEBUG_TRIGGER'])) {
            return true;
        }

        if (! empty($_ENV['XDEBUG_PROFILE']) || ! empty($_GET['XDEBUG_PROFILE']) || ! empty($_COOKIE['XDEBUG_PROFILE'])) {
            return true;
        }

        $start = (string) ini_get('xdebug.start_with_request');

        return in_array($start, ['yes', '1', 'true'], true);
    }

    private function modeString(): ?string
    {
        $mode = ini_get('xdebug.mode');

        return is_string($mode) && $mode !== '' ? $mode : null;
    }
}
