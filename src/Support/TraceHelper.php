<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Support;

/**
 * Builds a collapsed stack trace relative to the application, excluding vendor and framework noise.
 *
 * @return array<int, string>
 */
final class TraceHelper
{
    public static function getCollapsedTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $lines = self::collectFrames($trace, ignoreConfigured: true);

        // Vendor-only call sites (queue fail storage, framework listeners, etc.)
        // otherwise leave an empty stack and the explorer can't show "from where".
        if ($lines === []) {
            $lines = self::collectFrames($trace, ignoreConfigured: false, limit: 8);
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $trace
     * @return list<string>
     */
    private static function collectFrames(array $trace, bool $ignoreConfigured, int $limit = 0): array
    {
        $basePath = self::basePath();
        $lines = [];

        foreach ($trace as $frame) {
            $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : null;
            $line = $frame['line'] ?? 0;

            if ($file === null) {
                continue;
            }

            if (! str_ends_with($file, '.php')) {
                continue;
            }

            if (str_contains($file, 'storage/framework/views')) {
                $original = self::originalBladeFromCompiled($file);
                $file = $original ?? $file;
                $line = $original !== null ? 0 : $line;
            }

            if ($ignoreConfigured && self::shouldIgnoreFile($file)) {
                continue;
            }

            if (basename($file) === 'artisan') {
                continue;
            }

            if (str_contains($file, 'public/index.php')) {
                continue;
            }

            if (str_contains($file, 'ContextLogging') || str_contains($file, 'ServiceProvider')) {
                continue;
            }

            $relativeFile = $basePath !== '' && str_starts_with($file, $basePath.'/')
                ? substr($file, strlen($basePath) + 1)
                : $file;

            if (str_contains($relativeFile, 'storage/framework/views/') && is_readable($file)) {
                $handle = @fopen($file, 'r');
                if ($handle !== false) {
                    $firstLine = fgets($handle);
                    fclose($handle);
                    if (
                        $firstLine !== false
                        && preg_match('/content:\s*(.+?\.blade\.php)/', $firstLine, $matches)
                    ) {
                        $resolved = $matches[1];
                        $relativeFile = (str_starts_with($resolved, $basePath.'/'))
                            ? substr($resolved, strlen($basePath) + 1).' (compiled)'
                            : $resolved.' (compiled)';
                    }
                }
            }

            $lines[] = "{$relativeFile}:{$line}";

            if ($limit > 0 && count($lines) >= $limit) {
                break;
            }
        }

        return $lines;
    }

    /**
     * Whether a file path should be omitted from collapsed traces.
     *
     * Uses context-logging.trace.ignore_paths: relative entries are under
     * base_path(); absolute entries match the filesystem path as logged.
     */
    public static function shouldIgnoreFile(string $file): bool
    {
        $file = str_replace('\\', '/', $file);
        $basePath = self::basePath();
        $relative = ($basePath !== '' && str_starts_with($file, $basePath.'/'))
            ? substr($file, strlen($basePath) + 1)
            : null;

        foreach (self::ignorePathPrefixes() as $prefix) {
            if (self::pathMatchesPrefix($file, $prefix)) {
                return true;
            }

            if ($relative !== null && ! str_starts_with($prefix, '/') && self::pathMatchesPrefix($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function ignorePathPrefixes(): array
    {
        $configured = config('context-logging.trace.ignore_paths', ['vendor']);
        if (! is_array($configured)) {
            $configured = ['vendor'];
        }

        $basePath = self::basePath();
        $prefixes = [];

        foreach ($configured as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = rtrim(str_replace('\\', '/', trim($entry)), '/');
            if ($entry === '') {
                continue;
            }

            if (str_starts_with($entry, '/')) {
                $prefixes[] = $entry;
                continue;
            }

            // Relative to the app root (e.g. "vendor", "extra-packages/vendor").
            $prefixes[] = $entry;
            if ($basePath !== '') {
                $prefixes[] = $basePath.'/'.$entry;
            }
        }

        return array_values(array_unique($prefixes));
    }

    private static function pathMatchesPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    private static function basePath(): string
    {
        return str_replace('\\', '/', (string) (function_exists('base_path') ? base_path() : ''));
    }

    private static function originalBladeFromCompiled(string $compiledFile): ?string
    {
        if (! is_file($compiledFile)) {
            return null;
        }

        $contents = @file_get_contents($compiledFile);
        if ($contents === false) {
            return null;
        }

        if (preg_match('/\/\*\*PATH\s+(.*?)\s+ENDPATH\*\*\//', $contents, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
