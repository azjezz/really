<?php

/**
 * @noinspection ForgottenDebugOutputInspection
 * @noinspection PhpUnhandledExceptionInspection
 */

use Psl\Async;
use Psl\Str;
use Really\Payload;

require __DIR__ . '/../vendor/autoload.php';

Async\main(static function (): int {


    $time = time();

    $pool = new Really\Pool(__DIR__ . '/worker-http.php');

    $awaitables = [];
    // 20 requests per payload.
    // send 50 payloads.
    for ($i = 0; $i < 50; $i++) {
        $awaitables[] = $pool->dispatch(Payload\GenericPayload::create([
            'host' => 'example.com',
            'path' => '/',
            'requests' => 20,
        ]));
    }

    foreach (Async\all($awaitables) as $wrapper) {
        /**
         * @var array{worker: int, responses: list<string>} $result
         */
        $result = $wrapper->getResult();

        foreach ($result['responses'] as $response) {
            $header_line = Str\split($response, "\n")[0] ?? '';

            echo "[worker={$result['worker']}] {$header_line}\n";
        }
    }

    $pool->stop();

    var_dump(time() - $time); // sent 1000 http requests.

    return 0;
});
