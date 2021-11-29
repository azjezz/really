<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Psl\Json;
use Psl\Shell;
use Psl\Str;
use Psl\TCP;
use Psl\Async;

function fetch(string $host, string $path): string
{
    $client = TCP\connect($host, 80);
    $client->writeAll("GET {$path} HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
    $response = $client->readAll();
    $client->close();

    return $response;
}

Really\worker(function(Really\Worker $worker, Psl\Network\SocketInterface $connection): void {
    $request = $connection->read();

    $responses = [];
    for($i = 0; $i <= 100; $i++) {
      $responses[] = Async\run(fn() => fetch('example.com', '/'));
    }

    Async\all($responses);

    $connection->writeAll(Json\encode([
        'worker' => $worker->getId(),
        'response' => Str\reverse($request),
    ]));

    $connection->close();
});
