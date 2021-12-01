<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Psl\TCP;
use Really\Payload;
use Psl\Async;

function fetch(string $host, string $path): string
{
    $client = TCP\connect($host, 80);
    $client->writeAll("GET {$path} HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
    $response = $client->readAll();
    $client->close();

    return $response;
}

Really\worker(function(Payload\GenericPayload $payload, Really\Worker $worker): array {
    $host = $payload->data['host'];
    $path = $payload->data['path'];
    $requests = $payload->data['requests'];

    $responses = [];
    for($i = 0; $i <= $requests; $i++) {
      $responses[$i] = Async\run(fn() => fetch($host, $path));
    }

    return [
        'worker' => $worker->getId(),
        'responses' => Async\all($responses),
    ];
});
