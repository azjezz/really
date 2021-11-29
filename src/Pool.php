<?php

declare(strict_types=1);

namespace Really;

use Closure;
use Psl;
use Psl\Filesystem;
use Psl\Network;
use Psl\Async;
use Psl\Unix;

final class Pool
{
    private Network\ServerInterface $server;

    /**
     * @var array<int, WorkerProcess>
     */
    private array $workers;

    public function __construct(private string $script, int $workers_count = 8, int $concurrency_level = 100)
    {
        Psl\invariant(Filesystem\is_readable($this->script), '$script "%s" is not readable.', $this->script);
        Psl\invariant($workers_count >= 1, '$workers_count (%d) must be a positive integer.', $workers_count);
        Psl\invariant($concurrency_level >= 1, '$concurrency_level (%d) must be a positive integer.', $concurrency_level);

        $this->server = Unix\Server::create(Filesystem\create_temporary_file() . '.sock');

        $workers = [];
        for ($i = 0; $i < $workers_count; $i++) {
            $workers[$i] = WorkerProcess::spawn($this->server, $this->script, $i, $concurrency_level);
        }

        $this->workers = $workers;
    }

    /**
     * @template T
     *
     * @param Closure(): T $closure
     *
     * @return Async\Awaitable<T>
     */
    public function dispatch(Closure $closure): Async\Awaitable
    {
        $connection = $this->server->nextConnection();
        $connection->writeAll(Worker::MESSAGE_PING);
        $connection->readAll(Worker::MESSAGE_PONG_LENGTH);

        return Async\run(static fn() => $closure($connection));
    }

    /**
     * @return list<WorkerProcess>
     */
    public function getWorkerProcesses(): array
    {
        return $this->workers;
    }

    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            $worker->kill();
        }

        $this->server->stopListening();
    }
}
