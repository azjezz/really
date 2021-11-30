<?php

use Psl\{Async, Network, Json};

require __DIR__ . '/../vendor/autoload.php';

$pool = new Really\Pool(__DIR__ . '/worker-http.php', 5, 2);
$time = time();
$awaitables = [];
for ($i = 0; $i < 10; $i++) {
    $awaitables[] = $pool->dispatch(static function (Network\SocketInterface $connection) use($i): void {
        $connection->writeAll('olleh');
        $response = $connection->readAll();

        ['worker' => $worker, 'response' => $response] = Json\decode($response);

        echo "[worker=$worker][job=$i]: $response\n";

        $connection->close();
    });
}

Async\all($awaitables);

$pool->stop();

var_dump(time() - $time); // executed 800 jobs.
