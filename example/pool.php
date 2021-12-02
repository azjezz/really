<?php

/**
 * @noinspection ForgottenDebugOutputInspection
 * @noinspection PhpUnhandledExceptionInspection
 */

use Psl\Async;
use Really\Payload;

require __DIR__ . '/../vendor/autoload.php';

Async\main(static function(): int {
    $pool = new Really\Pool(__DIR__ . '/worker.php', 8, 120);

    $time = time();

    $awaitables = [];
    for ($i = 0; $i < 800; $i++) {
        $awaitables[] = $pool->dispatch(Payload\GenericPayload::create([
            'message' => 'olleh',
            'duration' => '2'
        ]));
    }

    foreach (Async\Awaitable::iterate($awaitables) as $i => $awaitable) {
        /**
         * @var array{worker: int, response: string} $result
         */
        $result = $awaitable->await();
        if ($result->isFailed()) {
            // todo figure out which worker is failing.
            echo "[worker=n/a][job={$i}] failed: {$result->getException()->getMessage()}\n";
        } else {
            $result = $result->getResult();

            echo "[worker={$result['worker']}][job={$i}]: {$result['response']}\n";
        }
    }

    echo 'Done: ' . (time() - $time) . 's' . PHP_EOL;

    $pool->stop();

    return 0;
});
