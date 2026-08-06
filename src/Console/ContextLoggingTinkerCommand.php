<?php

namespace Michael4d45\ContextLogging\Console;

use Illuminate\Support\Env;
use Laravel\Tinker\ClassAliasAutoloader;
use Laravel\Tinker\Console\TinkerCommand;
use Michael4d45\ContextLogging\ContextLogEmitter;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Tinker\ContextLoggingTinkerShell;
use Psy\Configuration;
use Psy\VersionUpdater\Checker;
use Throwable;

class ContextLoggingTinkerCommand extends TinkerCommand
{
    public function handle()
    {
        $this->getApplication()->setCatchExceptions(false);

        $config = Configuration::fromInput($this->input);
        $config->setUpdateCheck(Checker::NEVER);

        $appConfig = $this->getLaravel()->make('config');
        $config->setTrustProject($appConfig->get('tinker.trust_project'));

        $config->getPresenter()->addCasters(
            $this->getCasters()
        );

        if ($this->option('execute')) {
            $config->setRawOutput(true);
        }

        // PsySH's ProcessForker breaks Laravel's logger after pcntl_fork, so
        // interactive wide-events never reach laravel.log. Prefer a single
        // process so ContextLogEmitter can write reliably.
        $config->setUsePcntl(false);

        $contextStore = $this->getLaravel()->make(ContextStore::class);

        $shell = new ContextLoggingTinkerShell($contextStore, $config);
        $shell->addCommands($this->getCommands());
        $shell->setIncludes($this->argument('include'));

        $path = Env::get('COMPOSER_VENDOR_DIR', $this->getLaravel()->basePath().DIRECTORY_SEPARATOR.'vendor');
        $path .= '/composer/autoload_classmap.php';

        $loader = ClassAliasAutoloader::register(
            $shell,
            $path,
            $appConfig->get('tinker.alias', []),
            $appConfig->get('tinker.dont_alias', [])
        );

        if ($code = $this->option('execute')) {
            try {
                $contextStore->initialize();
                $contextStore->addContexts([
                    'run_id' => (string) \Illuminate\Support\Str::uuid(),
                    'timestamp' => now()->toISOString(),
                    'command' => 'tinker',
                    'source' => 'tinker',
                    'mode' => 'execute',
                    'input' => $code,
                ]);

                $shell->setOutput($this->output);
                $shell->execute($code, true);
                ContextLogEmitter::emit($contextStore, null, 'Tinker execution completed');
            } catch (Throwable $e) {
                $shell->recordCaughtException($e);
                ContextLogEmitter::emit($contextStore, null, 'Tinker execution failed');
                $shell->writeException($e);

                return 1;
            } finally {
                $contextStore->clear();
                $loader->unregister();
            }

            return 0;
        }

        try {
            return $shell->run();
        } finally {
            $loader->unregister();
        }
    }
}