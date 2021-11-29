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

Async\main(function() {
    $responses = [];
    for($i = 0; $i <= 1000; $i++) {
      $responses[] = Async\run(fn() => fetch('example.com', '/'));
    }

    Async\all($responses);

    return 0;
});
