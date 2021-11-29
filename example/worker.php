<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Psl\Json;
use Psl\Shell;
use Psl\Str;

Really\worker(function(Really\Worker $worker, Psl\Network\SocketInterface $connection): void {
    $request = $connection->read();

    // execute a shell command that takes 1 second!
    Shell\execute('sleep', ['1']);

    $connection->writeAll(Json\encode([
        'worker' => $worker->getId(),
        'response' => Str\reverse($request),
    ]));

    $connection->close();
});
