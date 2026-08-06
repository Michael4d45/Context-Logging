<?php

namespace Michael4d45\ContextLogging\Tinker;

use Michael4d45\ContextLogging\ContextStore;
use Psy\Configuration;
use Psy\Exception\BreakException;
use Psy\Shell;
use Throwable;

class ContextLoggingTinkerShell extends Shell
{
    public function __construct(
        protected ContextStore $contextStore,
        ?Configuration $config = null
    ) {
        parent::__construct($config);
    }

    protected function getDefaultLoopListeners(): array
    {
        return [
            ...parent::getDefaultLoopListeners(),
            new TinkerExecutionListener($this->contextStore),
        ];
    }

    /**
     * Non-interactive / piped runs call execute() without the ExecutionLoopClosure
     * finally block, so afterLoop never fires. Drive the listener emit here when
     * PsySH swallows the exception (throwExceptions=false).
     */
    public function execute(string $code, bool $throwExceptions = false)
    {
        if ($throwExceptions) {
            return parent::execute($code, true);
        }

        try {
            $result = parent::execute($code, true);
            $this->afterLoop();

            return $result;
        } catch (BreakException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->writeException($e);
            $this->afterLoop();

            return null;
        }
    }

    /**
     * PsySH catches statement exceptions and prints them; record them on the
     * active tinker lifecycle so afterLoop can emit a wide event.
     */
    public function writeException(Throwable $e)
    {
        $this->recordCaughtException($e);

        parent::writeException($e);
    }

    public function recordCaughtException(Throwable $e): void
    {
        if (
            ! $this->contextStore->hasLifecycleStarted()
            || $this->contextStore->hasBeenEmitted()
        ) {
            return;
        }

        $context = [
            'event' => 'ExecutionFailed',
            'exception' => $e->getMessage(),
            'exception_class' => $e::class,
        ];

        if (method_exists($e, 'errors')) {
            /** @var mixed $errors */
            $errors = $e->errors();
            if (is_array($errors)) {
                $context['errors'] = $errors;
            }
        }

        $this->contextStore->addEvent('error', 'tinker', $context);
    }
}
