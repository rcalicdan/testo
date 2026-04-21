<?php

declare(strict_types=1);

namespace Testo\Application\Internal\Runner;

use Psr\EventDispatcher\EventDispatcherInterface;
use Hibla\Parallel\Internals\WorkerContext;

use function Hibla\emit;

/**
 * Wraps the real EventDispatcher to beam events back to the parent process.
 *
 * When tests are executed inside a Hibla Parallel worker process, this dispatcher
 * intercepts events (like TestStarted, TestFinished) and emits them over the
 * IPC channel so the parent process can render them to the console in real-time.
 *
 * @internal
 * @psalm-internal Testo\Application
 */
final readonly class EmittingEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private EventDispatcherInterface $realDispatcher,
    ) {}

    #[\Override]
    public function dispatch(object $event): object
    {
        if (WorkerContext::isWorker()) {
            emit($event);
        }

        return $this->realDispatcher->dispatch($event);
    }
}
