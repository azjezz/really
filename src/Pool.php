<?php

declare(strict_types=1);

namespace Really;

use Exception;
use Psl;
use Psl\Result;
use Psl\Async;
use Psl\Filesystem;
use Psl\Json;
use Psl\Network;
use Psl\TCP;
use Psl\Type;
use Throwable;
use function pack;
use function serialize;
use function strlen;
use function unserialize;
use const SIGINT;
use const SIGTERM;

final class Pool
{
    private Network\ServerInterface $server;

    /**
     * @var array<int, WorkerProcess>
     */
    private array $workers = [];

    private Async\Sequence $connectionSequence;

    public function __construct(private string $script, int $workers_count = 8, int $concurrency_level = 100)
    {
        Psl\invariant(Filesystem\is_readable($this->script), '$script "%s" is not readable.', $this->script);
        Psl\invariant($workers_count >= 1, '$workers_count (%d) must be a positive integer.', $workers_count);
        Psl\invariant($concurrency_level >= 1, '$concurrency_level (%d) must be a positive integer.', $concurrency_level);

        $this->server = TCP\Server::create('127.0.0.1');
        $this->connectionSequence = new Async\Sequence($this->server->nextConnection(...));

        for ($i = 0; $i < $workers_count; $i++) {
            $this->workers[$i] = WorkerProcess::spawn($this->server, $this->script, $i, $concurrency_level);
        }

        $watchers[] = Async\Scheduler::onSignal(SIGTERM, $this->stop(...));
        $watchers[] = Async\Scheduler::onSignal(SIGINT, $this->stop(...));

        foreach ($watchers as $watcher) {
            Async\Scheduler::unreference($watcher);
        }
    }

    /**
     * @throws Throwable
     */
    public function stop(): void
    {
        $this->connectionSequence->cancel(new Exception('closed'));
        // stop server
        $this->server->close();

        // kill all the workers.
        foreach ($this->workers as $worker) {
            $worker->kill();
        }
    }

    /**
     * @template TPayloadType
     * @template TResult
     *
     * @param Payload\PayloadInterface<TPayloadType, TResult> $payload
     *
     * @return Async\Awaitable<Result\ResultInterface<TResult>>
     */
    public function dispatch(Payload\PayloadInterface $payload): Async\Awaitable
    {
        return Async\run(Async\reflect(function () use ($payload): mixed {
            $connection = $this->connectionSequence->waitFor(null);
            $connection->writeAll(Worker::MESSAGE_PING);

            $payload_serialized = serialize($payload);
            $payload_serialized_length = pack('L', strlen($payload_serialized));

            $connection->writeAll($payload_serialized_length . $payload_serialized);

            $response = $connection->readAll();
            $connection->close();
            if ($response === '') {
                throw new Exception('Connection with the worker has been interrupted ( could be the cause of calling $pool->stop() prematurely, which is invoked when SIGTERM, or SIGINT is received ).');
            }

            $response = Json\typed($response, Type\shape([
                'result' => Type\nullable(Type\string()),
                'exception' => Type\nullable(Type\string()),
            ]));

            if ($response['exception'] !== null) {
                throw unserialize($response['exception']);
            }

            /** @var TResult */
            return unserialize(Type\string()->assert($response['result']));
        }));
    }

    /**
     * @return list<WorkerProcess>
     */
    public function getWorkerProcesses(): array
    {
        return $this->workers;
    }
}
