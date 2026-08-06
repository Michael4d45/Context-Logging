<?php

namespace Michael4d45\ContextLogging\Guzzle;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\InstalledVersions;
use RuntimeException;

/**
 * Generates UnpatchedClient and remaps GuzzleHttp\Client in Composer autoload files.
 *
 * Runtime capture is gated by context-logging.http.guzzle_patch / CONTEXT_LOG_HTTP_GUZZLE_PATCH.
 */
class PatchInstaller
{
    public function __construct(
        protected ?Composer $composer = null,
        protected ?IOInterface $io = null,
    ) {}

    /**
     * @param  bool  $preAutoloadDump  When true (Composer PRE hook), wire root autoload and skip
     *                                 rewriting vendor classmap files that Composer is about to regenerate.
     */
    public function install(bool $preAutoloadDump = false): bool
    {
        $packageRoot = dirname(__DIR__, 2);
        $generatedDir = $packageRoot.'/src/Guzzle/Generated';
        $guzzleClientPath = $this->findGuzzleClientPath();

        if ($guzzleClientPath === null || ! is_file($guzzleClientPath)) {
            $this->write('context-logging: guzzlehttp/guzzle Client.php not found; skipping Guzzle patch.');

            return false;
        }

        if (! is_dir($generatedDir) && ! mkdir($generatedDir, 0775, true) && ! is_dir($generatedDir)) {
            throw new RuntimeException("Unable to create {$generatedDir}");
        }

        $original = file_get_contents($guzzleClientPath);
        if ($original === false) {
            throw new RuntimeException("Unable to read {$guzzleClientPath}");
        }

        $unpatched = preg_replace(
            '/\bclass\s+Client\b/',
            'class UnpatchedClient',
            $original,
            1,
            $count
        );

        if ($count !== 1 || ! is_string($unpatched)) {
            throw new RuntimeException('Failed to rename Guzzle Client to UnpatchedClient.');
        }

        $unpatched = str_replace('@final', '@internal context-logging unpatched', $unpatched);
        file_put_contents($generatedDir.'/UnpatchedClient.php', $unpatched);

        $clientStub = <<<'PHP'
<?php

namespace GuzzleHttp;

use Michael4d45\ContextLogging\Guzzle\ClientPatch;

/**
 * Instrumented Guzzle client (context-logging sidecar patch).
 */
class Client extends UnpatchedClient
{
    public function __construct(array $config = [])
    {
        parent::__construct(ClientPatch::apply($config));
        ClientPatch::afterConstruct($this);
    }
}

PHP;

        file_put_contents($generatedDir.'/Client.php', $clientStub);

        if ($preAutoloadDump) {
            $this->wireRootAutoload($generatedDir);
            $this->write('context-logging: Guzzle Client patch sources ready for autoload dump.');

            return true;
        }

        $this->wireRootAutoload($generatedDir);
        $patched = $this->patchVendorAutoloadFiles($generatedDir);

        if ($patched) {
            $this->write('context-logging: Guzzle Client patch installed.');
        } else {
            $this->write('context-logging: generated Client sources; vendor autoload remap skipped (run from app root).');
        }

        return true;
    }

    /**
     * Whether Generated/UnpatchedClient.php is out of date vs the active Guzzle Client.php.
     *
     * Sidecar installs often generate against a transitive guzzle in extra-packages
     * while the app autoloads a different guzzle (e.g. Multiplexing::NONE mismatch).
     */
    public function isGeneratedStale(): bool
    {
        $guzzleClientPath = $this->findGuzzleClientPath();
        $packageRoot = dirname(__DIR__, 2);
        $generatedPath = $packageRoot.'/src/Guzzle/Generated/UnpatchedClient.php';

        if ($guzzleClientPath === null || ! is_file($guzzleClientPath) || ! is_file($generatedPath)) {
            return true;
        }

        $original = file_get_contents($guzzleClientPath);
        $actual = file_get_contents($generatedPath);

        if ($original === false || $actual === false) {
            return true;
        }

        $expected = preg_replace(
            '/\bclass\s+Client\b/',
            'class UnpatchedClient',
            $original,
            1,
            $count
        );

        if ($count !== 1 || ! is_string($expected)) {
            return true;
        }

        $expected = str_replace('@final', '@internal context-logging unpatched', $expected);

        return hash('sha256', str_replace("\r\n", "\n", $expected))
            !== hash('sha256', str_replace("\r\n", "\n", $actual));
    }

    protected function findGuzzleClientPath(): ?string
    {
        $fromEnv = getenv('CONTEXT_LOGGING_GUZZLE_CLIENT') ?: ($_ENV['CONTEXT_LOGGING_GUZZLE_CLIENT'] ?? null);
        if (is_string($fromEnv) && $fromEnv !== '' && is_file($fromEnv)) {
            return $fromEnv;
        }

        // Prefer the Guzzle package already on the autoload path (app vendor), not a
        // transitive copy under an extra-packages sidecar that may differ.
        if (class_exists(\GuzzleHttp\Multiplexing::class, true)) {
            try {
                $multiplexingFile = (new \ReflectionClass(\GuzzleHttp\Multiplexing::class))->getFileName();
                if (is_string($multiplexingFile) && $multiplexingFile !== '') {
                    $candidate = dirname($multiplexingFile).'/Client.php';
                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        $appRoot = getenv('APP_ROOT') ?: ($_ENV['APP_ROOT'] ?? null);
        if (is_string($appRoot) && $appRoot !== '') {
            $candidate = rtrim($appRoot, '/\\').'/vendor/guzzlehttp/guzzle/src/Client.php';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        if (class_exists(InstalledVersions::class)) {
            try {
                $installPath = InstalledVersions::getInstallPath('guzzlehttp/guzzle');
                if (is_string($installPath) && $installPath !== '') {
                    $candidate = rtrim($installPath, '/\\').'/src/Client.php';
                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        $candidates = [
            dirname(__DIR__, 3).'/guzzlehttp/guzzle/src/Client.php',
            dirname(__DIR__, 4).'/vendor/guzzlehttp/guzzle/src/Client.php',
            dirname(__DIR__, 2).'/vendor/guzzlehttp/guzzle/src/Client.php',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function findVendorDir(): ?string
    {
        if ($this->composer !== null) {
            $vendor = $this->composer->getConfig()->get('vendor-dir');
            if (is_string($vendor) && is_dir($vendor)) {
                return rtrim($vendor, '/\\');
            }
        }

        if (class_exists(InstalledVersions::class)) {
            try {
                $root = InstalledVersions::getRootPackage()['install_path'] ?? null;
                if (is_string($root) && $root !== '') {
                    $vendor = rtrim($root, '/\\').'/vendor';
                    if (is_dir($vendor)) {
                        return $vendor;
                    }
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        $candidates = [
            dirname(__DIR__, 4).'/vendor',
            dirname(__DIR__, 3).'/vendor',
            getcwd().'/vendor',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate.'/composer')) {
                return rtrim($candidate, '/\\');
            }
        }

        return null;
    }

    protected function wireRootAutoload(string $generatedDir): void
    {
        if ($this->composer === null) {
            return;
        }

        $rootPackage = $this->composer->getPackage();
        $autoload = $rootPackage->getAutoload();

        $exclude = $autoload['exclude-from-classmap'] ?? [];
        $exclude = array_values(array_unique(array_merge($exclude, [
            'vendor/guzzlehttp/guzzle/src/Client.php',
            '**/guzzlehttp/guzzle/src/Client.php',
        ])));
        $autoload['exclude-from-classmap'] = $exclude;

        $classmap = $autoload['classmap'] ?? [];
        $vendorGenerated = 'vendor/michael4d45/context-logging/src/Guzzle/Generated';
        $classmap[] = $vendorGenerated;

        $relativeGenerated = $this->relativeToVendorParent($generatedDir);
        if ($relativeGenerated !== null) {
            $classmap[] = $relativeGenerated;
        }

        $autoload['classmap'] = array_values(array_unique($classmap));
        $rootPackage->setAutoload($autoload);
    }

    protected function relativeToVendorParent(string $absolutePath): ?string
    {
        $vendorDir = $this->findVendorDir();
        if ($vendorDir === null) {
            return null;
        }

        $root = dirname($vendorDir);
        $absolutePath = realpath($absolutePath) ?: $absolutePath;
        $root = realpath($root) ?: $root;

        if (! str_starts_with($absolutePath, $root)) {
            return null;
        }

        return ltrim(str_replace('\\', '/', substr($absolutePath, strlen($root))), '/');
    }

    /**
     * Remap GuzzleHttp\Client using Composer-style $vendorDir / $baseDir expressions.
     */
    protected function patchVendorAutoloadFiles(string $generatedDir): bool
    {
        $vendorDir = $this->findVendorDir();
        if ($vendorDir === null) {
            return false;
        }

        $clientExpr = $this->autoloadPathExpression($generatedDir.'/Client.php', $vendorDir);
        $unpatchedExpr = $this->autoloadPathExpression($generatedDir.'/UnpatchedClient.php', $vendorDir);

        if ($clientExpr === null || $unpatchedExpr === null) {
            $this->write('context-logging: could not express Generated Client paths relative to vendor/base; using absolute paths.');
            $clientExpr = var_export(realpath($generatedDir.'/Client.php') ?: ($generatedDir.'/Client.php'), true);
            $unpatchedExpr = var_export(realpath($generatedDir.'/UnpatchedClient.php') ?: ($generatedDir.'/UnpatchedClient.php'), true);
        }

        $clientStaticExpr = $this->autoloadStaticPathExpression($generatedDir.'/Client.php', $vendorDir);
        $unpatchedStaticExpr = $this->autoloadStaticPathExpression($generatedDir.'/UnpatchedClient.php', $vendorDir);

        if ($clientStaticExpr === null || $unpatchedStaticExpr === null) {
            $clientStaticExpr = var_export(realpath($generatedDir.'/Client.php') ?: ($generatedDir.'/Client.php'), true);
            $unpatchedStaticExpr = var_export(realpath($generatedDir.'/UnpatchedClient.php') ?: ($generatedDir.'/UnpatchedClient.php'), true);
        }

        $classmapFile = $vendorDir.'/composer/autoload_classmap.php';
        $staticFile = $vendorDir.'/composer/autoload_static.php';

        if (! is_file($classmapFile)) {
            return false;
        }

        $this->rewriteClassmapFile($classmapFile, $clientExpr, $unpatchedExpr);
        if (is_file($staticFile)) {
            $this->rewriteStaticClassmap($staticFile, $clientStaticExpr, $unpatchedStaticExpr);
        }

        return true;
    }

    /**
     * Build a PHP expression like `$vendorDir . '/michael4d45/.../Client.php'` for autoload_classmap.php.
     */
    protected function autoloadPathExpression(string $absolutePath, string $vendorDir): ?string
    {
        $absolutePath = realpath($absolutePath) ?: $absolutePath;
        $vendorDirReal = realpath($vendorDir) ?: $vendorDir;
        $baseDirReal = realpath(dirname($vendorDir)) ?: dirname($vendorDir);

        $absolutePath = str_replace('\\', '/', $absolutePath);
        $vendorDirReal = str_replace('\\', '/', $vendorDirReal);
        $baseDirReal = str_replace('\\', '/', $baseDirReal);

        if (str_starts_with($absolutePath, $vendorDirReal.'/')) {
            $suffix = substr($absolutePath, strlen($vendorDirReal));

            return '$vendorDir . '.var_export($suffix, true);
        }

        if (str_starts_with($absolutePath, $baseDirReal.'/')) {
            $suffix = substr($absolutePath, strlen($baseDirReal));

            return '$baseDir . '.var_export($suffix, true);
        }

        return null;
    }

    /**
     * Build a constant `__DIR__ . '/...'` expression for autoload_static.php class properties.
     */
    protected function autoloadStaticPathExpression(string $absolutePath, string $vendorDir): ?string
    {
        $composerDir = realpath($vendorDir.'/composer') ?: ($vendorDir.'/composer');
        $absolutePath = realpath($absolutePath) ?: $absolutePath;

        $relative = $this->relativePath($composerDir, $absolutePath);
        if ($relative === null) {
            return null;
        }

        return '__DIR__ . '.var_export('/'.$relative, true);
    }

    /**
     * Relative path from a directory to a file (no leading slash).
     */
    protected function relativePath(string $fromDir, string $toFile): ?string
    {
        $fromDir = str_replace('\\', '/', realpath($fromDir) ?: $fromDir);
        $toFile = str_replace('\\', '/', realpath($toFile) ?: $toFile);

        $from = explode('/', rtrim($fromDir, '/'));
        $to = explode('/', $toFile);

        if ($from === [] || $to === [] || $from[0] !== $to[0]) {
            return null;
        }

        while ($from !== [] && $to !== [] && ($from[0] ?? null) === ($to[0] ?? null)) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', array_merge(array_fill(0, count($from), '..'), $to));
    }

    protected function rewriteClassmapFile(string $classmapFile, string $clientExpr, string $unpatchedExpr): void
    {
        $contents = file_get_contents($classmapFile);
        if ($contents === false) {
            return;
        }

        $contents = $this->upsertClassmapEntry($contents, 'GuzzleHttp\\Client', $clientExpr);
        $contents = $this->upsertClassmapEntry($contents, 'GuzzleHttp\\UnpatchedClient', $unpatchedExpr);

        if (! str_contains($contents, 'patched by context-logging')) {
            $replaceCount = 0;
            $contents = str_replace(
                '<?php',
                "<?php\n// patched by context-logging",
                $contents,
                $replaceCount
            );
        }

        file_put_contents($classmapFile, $contents);
    }

    protected function rewriteStaticClassmap(string $staticFile, string $clientExpr, string $unpatchedExpr): void
    {
        $contents = file_get_contents($staticFile);
        if ($contents === false) {
            return;
        }

        $contents = $this->upsertStaticClassmapEntry($contents, 'GuzzleHttp\\Client', $clientExpr);
        $contents = $this->upsertStaticClassmapEntry($contents, 'GuzzleHttp\\UnpatchedClient', $unpatchedExpr);

        file_put_contents($staticFile, $contents);
    }

    /**
     * @param  non-empty-string  $fqcn
     * @param  non-empty-string  $pathExpression  PHP expression evaluating to the file path
     */
    protected function upsertClassmapEntry(string $contents, string $fqcn, string $pathExpression): string
    {
        $key = var_export($fqcn, true);
        $entry = "{$key} => {$pathExpression},";

        $pattern = '/'.preg_quote($key, '/').'\\s*=>\\s*[^,\\n]+,/';
        $replaced = preg_replace($pattern, $entry, $contents, 1, $count);

        if (! is_string($replaced)) {
            return $contents;
        }

        if ($count > 0) {
            return $replaced;
        }

        return preg_replace(
            '/return\s+array\s*\(/',
            "return array(\n    {$entry}",
            $contents,
            1
        ) ?? $contents;
    }

    /**
     * @param  non-empty-string  $fqcn
     * @param  non-empty-string  $pathExpression
     */
    protected function upsertStaticClassmapEntry(string $contents, string $fqcn, string $pathExpression): string
    {
        $key = var_export($fqcn, true);
        $entry = "{$key} => {$pathExpression},";

        $pattern = '/'.preg_quote($key, '/').'\\s*=>\\s*[^,\\n]+,/';
        $replaced = preg_replace($pattern, $entry, $contents, 1, $count);

        if (! is_string($replaced)) {
            return $contents;
        }

        if ($count > 0) {
            return $replaced;
        }

        return preg_replace(
            '/(public\s+static\s+\$classMap\s*=\s*array\s*\()/',
            "$1\n        {$entry}",
            $contents,
            1
        ) ?? $contents;
    }

    protected function write(string $message): void
    {
        if ($this->io !== null) {
            $this->io->writeError('<info>'.$message.'</info>');

            return;
        }

        fwrite(STDERR, $message.PHP_EOL);
    }
}
