<?php

use Psl\{Async, Network, Json};

require __DIR__ . '/../vendor/autoload.php';

$pool = new Really\Pool(__DIR__ . '/worker.php');
$time = time();
$awaitables = [];
for ($i = 0; $i <= 800; $i++) {
    $awaitables[] = $pool->dispatch(static function (Network\SocketInterface $connection): void {
        $connection->writeAll('olleh');
        $response = $connection->readAll();

        ['worker' => $worker, 'response' => $response] = Json\decode($response);

        echo "[worker=$worker]: $response\n";

        $connection->close();
    });
}

$pool->stop();

Async\all($awaitables);

var_dump(time() - $time); // executed 800 jobs.
