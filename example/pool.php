<?php

/**
 * @noinspection ForgottenDebugOutputInspection
 * @noinspection PhpUnhandledExceptionInspection
 */

use Psl\Async;
use Really\Payload;

require __DIR__ . '/../vendor/autoload.php';

$time = time();

$pool = new Really\Pool(__DIR__ . '/worker.php');

$awaitables = [];
for ($i = 0; $i < 1000; $i++) {
    $awaitables[] = $pool->dispatch(Payload\GenericPayload::create([
        'message' => 'olleh',
        'duration' => '2'
    ]));
}

foreach (Async\all($awaitables) as $wrapper) {
    /**
     * @var array{worker: int, response: string} $result
     */
    $result = $wrapper->getResult();

    echo "[worker={$result['worker']}]: {$result['response']}\n";
}

$pool->stop();

var_dump(time() - $time); // executed 1000 jobs.
