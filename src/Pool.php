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

    private Async\Awaitable $lastConnection;


    public function __construct(private string $script, int $workers_count = 8, int $concurrency_level = 100)
    {
        Psl\invariant(Filesystem\is_readable($this->script), '$script "%s" is not readable.', $this->script);
        Psl\invariant($workers_count >= 1, '$workers_count (%d) must be a positive integer.', $workers_count);
        Psl\invariant($concurrency_level >= 1, '$concurrency_level (%d) must be a positive integer.', $concurrency_level);

        $this->server = TCP\Server::create('127.0.0.1');

        for ($i = 0; $i < $workers_count; $i++) {
            $this->workers[$i] = WorkerProcess::spawn($this->server, $this->script, $i, $concurrency_level);
        }

        $watchers[] = Async\Scheduler::onSignal(SIGTERM, $this->stop(...));
        $watchers[] = Async\Scheduler::onSignal(SIGINT, $this->stop(...));

        foreach ($watchers as $watcher) {
            Async\Scheduler::unreference($watcher);
        }

        $this->lastConnection = Async\Awaitable::complete(null);
    }

    /**
     * @throws Throwable
     */
    public function stop(): void
    {
        $this->closing = true;

        $this->lastConnection->ignore();

        // stop server
        $this->server->stopListening();

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
        if ($this->closing) {
            return Async\Awaitable::complete(new Result\Failure(new \Exception('Closed!')));
        }

        $this->lastConnection->await();

        $this->lastConnection = Async\run(Async\reflect($this->server->nextConnection(...)));

        return $this
            ->lastConnection
            ->then(
                static function (Result\ResultInterface $result) use($payload): Result\ResultInterface {
                    if ($result->isFailed()) {
                        return $result;
                    }

                    $connection = $result->getResult();
                    $connection->writeAll(Worker::MESSAGE_PING);

                    $payload_serialized = serialize($payload);

                    $payload_serialized_length = pack('L', strlen($payload_serialized));

                    $connection->writeAll($payload_serialized_length.$payload_serialized);

                    $response = $connection->readAll();
                    if ($response === '') {
                        $connection->close();

                        return new Result\Failure(new \Exception('Connection with the worker has been interrupted ( could be the cause of calling $pool->stop() prematurely, which is invoked when SIGTERM, or SIGINT is received ).'));
                    }
                    try {
                        $response = Json\typed($response, Type\shape([
                            'result' => Type\nullable(Type\string()),
                            'exception' => Type\nullable(Type\string()),
                        ]));
                    } catch(Json\Exception\DecodeException $e) {
                        return new Result\Failure($e);
                    } finally {
                        $connection->close();
                    }

                    if ($response['exception'] !== null) {
                        /** @var \Throwable */
                        $exception = unserialize($response['exception']);

                        return new Result\Failure($exception);
                    }

                    return new Result\Success(unserialize(Type\string()->assert($response['result'])));
                },
                static fn (Throwable $throwable): Result\ResultInterface => new Result\Failure($throwable)
            )
        ;
    }

    /**
     * @return list<WorkerProcess>
     */
    public function getWorkerProcesses(): array
    {
        return $this->workers;
    }
}
