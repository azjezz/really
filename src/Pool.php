<?php

declare(strict_types=1);

namespace Really;

use Psl;
use Psl\Async;
use Psl\Filesystem;
use Psl\Json;
use Psl\Network;
use Psl\Result;
use Psl\TCP;
use Psl\Type;
use Psl\Vec;
use Throwable;
use function pack;
use function serialize;
use function strlen;
use function unserialize;
use const SIGINT;
use const SIGTERM;

final class Pool
{
    private bool $closing = false;

    private Network\ServerInterface $server;

    /**
     * @var array<int, WorkerProcess>
     */
    private array $workers = [];

    /**
     * @var list<Async\Awaitable<Result\ResultInterface<mixed>>>
     */
    private array $jobs = [];

    private Async\Awaitable $last;

    public function __construct(private string $script, int $workers_count = 8, int $concurrency_level = 100)
    {
        posix_setsid();

        Psl\invariant(Filesystem\is_readable($this->script), '$script "%s" is not readable.', $this->script);
        Psl\invariant($workers_count >= 1, '$workers_count (%d) must be a positive integer.', $workers_count);
        Psl\invariant($concurrency_level >= 1, '$concurrency_level (%d) must be a positive integer.', $concurrency_level);

        $this->server = TCP\Server::create('127.0.0.1');

        for ($i = 0; $i < $workers_count; $i++) {
            $this->workers[$i] = WorkerProcess::spawn($this->server, $this->script, $i, $concurrency_level);
        }

        $watchers[] = Async\Scheduler::onSignal(SIGTERM, fn() => $this->stop());
        $watchers[] = Async\Scheduler::onSignal(SIGINT, fn() => $this->stop());

        foreach ($watchers as $watcher) {
            Async\Scheduler::unreference($watcher);
        }

        $this->last = Async\Awaitable::complete(null);
    }

    /**
     * @throws Throwable
     */
    public function stop(): void
    {
        $this->closing = true;

        // wait for all the jobs before killing the workers, and stopping the server.
        Async\all(Vec\map($this->jobs, static fn($awaitable) => $awaitable->ignore()));

        // stop network server
        $this->server->stopListening();

        // kill the workers.
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
        $this->last->await();
        if ($this->closing) {
            return Async\Awaitable::complete(new Result\Failure(new \Exception('Closed!')));
        }

        $this->jobs = Vec\filter(
            $this->jobs,
            static fn(Async\Awaitable $awaitable): bool => !$awaitable->isComplete()
        );

        try {
            $this->last->then(fn() => null, fn() => null)->await();

            $this->last = Async\run(fn() => $this->server->nextConnection());

            $connection = $this->last->await();
        } catch (Network\Exception\AlreadyStoppedException $e) {
            return Async\Awaitable::complete(new Result\Failure($e));
        }

        $connection->writeAll(Worker::MESSAGE_PING);

        /** @var Async\Awaitable<Result\ResultInterface<TResult>> */
        return Async\run(static function () use ($payload, $connection): Result\ResultInterface {
            try {
                $serialized_payload = serialize($payload);
                $serialized_payload_length = pack('L', strlen($serialized_payload));

                $connection->writeAll($serialized_payload_length);
                $connection->writeAll($serialized_payload);

                $response = $connection->readAll();
                $response = Json\typed($response, Type\shape([
                    'result' => Type\nullable(Type\string()),
                    'exception' => Type\nullable(Type\string()),
                ]));

                if ($response['exception'] !== null) {
                    /** @var \Throwable */
                    $exception = unserialize($response['exception']);

                    return new Result\Failure($exception);
                }

                $result = unserialize(Type\string()->assert($response['result']));

                return new Result\Success($result);
            } finally {
                $connection->close();
            }
        });
    }

    /**
     * @return list<WorkerProcess>
     */
    public function getWorkerProcesses(): array
    {
        return $this->workers;
    }
}
