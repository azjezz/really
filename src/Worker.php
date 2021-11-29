<?php

declare(strict_types=1);

namespace Really;

use Closure;
use Psl;
use Psl\Async;
use Psl\Env;
use Psl\Network;
use Psl\Str;
use Psl\TCP;
use Psl\Vec;
use Psl\Unix;
use function count;
use function parse_url;

final class Worker
{
    public const MESSAGE_PING = 'ping';
    public const MESSAGE_PING_LENGTH = 4;

    public const MESSAGE_PONG = 'pong';
    public const MESSAGE_PONG_LENGTH = 4;

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

    public function getId(): int
    {
        return $this->id;
    }

    public function run(Closure $closure): void
    {
        $server_address = $this->getServerAddress();
        $pending = [];
        while (true) {
            if ($server_address->scheme === Network\SocketScheme::TCP) {
                $connection = TCP\connect($server_address->host, $server_address->port);
            } else {
                $connection = Unix\connect($server_address->host);
            }

            $connection->readAll(self::MESSAGE_PING_LENGTH);
            $connection->writeAll(self::MESSAGE_PONG);

            $pending[] = Async\run(fn() => $closure($this, $connection));
            while(count($pending) >= $this->concurrency_level) {
                [$completed, $pending] = Vec\partition($pending, fn(Async\Awaitable $awaitable): bool => $awaitable->isComplete());

                Async\all($completed);
                Async\later();
            }
        }
    }

    public function getServerAddress(): Network\Address
    {
        return $this->server_address;
    }

    public function getConcurrencyLevel(): int
    {
        return $this->concurrency_level;
    }
}
