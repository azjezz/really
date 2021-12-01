<?php

declare(strict_types=1);

namespace Really;

use Psl;
use Psl\Async;
use Psl\Env;
use Psl\Json;
use Psl\Network;
use Psl\Str;
use Psl\TCP;
use Psl\Unix;
use Psl\Vec;
use Throwable;
use function count;
use function parse_url;
use function serialize;
use function strlen;
use function unpack;
use function unserialize;

final class Worker
{
    public const MESSAGE_PING = 'ping';
    public const MESSAGE_LENGTH = 4;

    public function __construct(
        private int             $id,
        private int             $concurrency_level,
        private Network\Address $server_address,
    )
    {
    }

    public static function create(): Worker
    {
        $server_address = Env\get_var('REALLY_SERVER');
        Psl\invariant($server_address !== null, '"REALLY_SERVER" environment variable is missing.');

        $worker_id = Env\get_var('REALLY_IDENTIFIER');
        Psl\invariant($worker_id !== null, '"REALLY_IDENTIFIER" environment variable is missing.');
        $worker_id = Str\to_int($worker_id);
        Psl\invariant($worker_id !== null, '"REALLY_IDENTIFIER" environment variable contains an invalid value.');

        $concurrency_level = Env\get_var('REALLY_CONCURRENCY_LEVEL');
        Psl\invariant($concurrency_level !== null, '"REALLY_CONCURRENCY_LEVEL" environment variable is missing.');
        $concurrency_level = Str\to_int($concurrency_level);
        Psl\invariant($concurrency_level !== null, '"REALLY_CONCURRENCY_LEVEL" environment variable contains an invalid value.');

        if (Str\starts_with($server_address, 'unix://')) {
            $sock = Str\strip_prefix($server_address, 'unix://');
            $address = Network\Address::unix($sock);
        } else {
            $address_components = parse_url($server_address);
            $address = Network\Address::tcp($address_components['host'], $address_components['port']);
        }

        return new self($worker_id, $concurrency_level, $address);
    }

    /**
     * @template TPayloadType
     * @template TResult
     * @template TPayloadInstance of Payload\PayloadInterface<TPayloadType, TResult>
     *
     * @psalm-param (callable(TPayloadInstance, Worker): TResult) $handler
     *
     * @throws Throwable
     */
    public function run(callable $handler): never
    {
        $connect = function (): ?Network\SocketInterface {
            $server_address = $this->getServerAddress();

            try {
                if ($server_address->scheme === Network\SocketScheme::TCP) {
                    return TCP\connect($server_address->host, $server_address->port);
                }

                return Unix\connect($server_address->host);
            } catch (Network\Exception\ExceptionInterface) {
                return null;
            }
        };

        $pending = [];

        while ($connection = $connect()) {
            Psl\invariant('ping' === $connection->read(self::MESSAGE_LENGTH), 'did not receive ping.');

            $pending[] = Async\run(function () use ($connection, $handler): void {
                try {
                    $packed_serialized_payload_length = $connection->read(self::MESSAGE_LENGTH);
                    Psl\invariant(4 === strlen($packed_serialized_payload_length), 'incorrect length.');
                    $serialized_payload_length = unpack('L', $packed_serialized_payload_length)[1];
                    $serialized_payload = $connection->readAll($serialized_payload_length);
                    $payload = unserialize($serialized_payload);
                } catch (Throwable) {
                    // should be handled better.
                    $connection->close();

                    return;
                }

                try {
                    $result = [
                        'result' => serialize($handler($payload, $this)),
                        'exception' => null,
                    ];
                } catch (Throwable $throwable) {
                    echo $throwable->getMessage();
                    $result = [
                        'exception' => serialize($throwable),
                        'result' => null,
                    ];
                }

                $result_encoded = Json\encode($result);

                $connection->writeAll($result_encoded);
                $connection->close();
            })->ignore();

            while (count($pending) >= $this->concurrency_level) {
                [$completed, $pending] = Vec\partition(
                    $pending,
                    static fn(Async\Awaitable $awaitable): bool => $awaitable->isComplete()
                );

                Async\all($completed);
            }
        }

        exit(0);
    }

    public function getServerAddress(): Network\Address
    {
        return $this->server_address;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getConcurrencyLevel(): int
    {
        return $this->concurrency_level;
    }
}
