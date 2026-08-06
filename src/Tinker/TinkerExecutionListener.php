<?php

namespace Michael4d45\ContextLogging\Tinker;

use Michael4d45\ContextLogging\ContextLogEmitter;
use Michael4d45\ContextLogging\ContextStore;
use Psy\ExecutionLoop\AbstractListener;
use Psy\Shell;

class TinkerExecutionListener extends AbstractListener
{
    protected bool $executionActive = false;

    public function __construct(
        protected ContextStore $contextStore
    ) {}

    public static function isSupported(): bool
    {
        return true;
    }

    public function onExecute(Shell $shell, string $code)
    {
        if (trim($code) === '') {
            return null;
        }

        // --execute pre-initializes the store; keep that mode/input across the
        // Shell::execute() onExecute hook instead of rewriting as interactive.
        $preserveExecute = $this->contextStore->hasLifecycleStarted()
            && $this->contextStore->getContext('source') === 'tinker'
            && $this->contextStore->getContext('mode') === 'execute'
            && ! $this->executionActive;

        $executeInput = $preserveExecute ? $this->contextStore->getContext('input') : null;

        $this->executionActive = true;
        $this->contextStore->initialize();
        $this->contextStore->addContexts([
            'run_id' => (string) \Illuminate\Support\Str::uuid(),
            'timestamp' => now()->toISOString(),
            'command' => 'tinker',
            'source' => 'tinker',
            'mode' => $preserveExecute ? 'execute' : 'interactive',
            'input' => is_string($executeInput) && $executeInput !== '' ? $executeInput : $code,
        ]);

        return null;
    }

    public function afterLoop(Shell $shell)
    {
        if (!$this->executionActive) {
            return;
        }

        $failed = ! $shell->getLastExecSuccess();
        $message = $failed ? 'Tinker execution failed' : 'Tinker execution completed';

        // Ensure a wide event always lands for evaluated input (even when the
        // statement produced no Log::* calls and did not throw).
        if (! $this->contextStore->hasEvents()) {
            $this->contextStore->addEvent(
                $failed ? 'error' : 'info',
                'tinker',
                ['event' => $failed ? 'ExecutionFailed' : 'ExecutionCompleted'],
            );
        }

        ContextLogEmitter::emit($this->contextStore, null, $message);
        $this->contextStore->clear();
        $this->executionActive = false;
    }
}