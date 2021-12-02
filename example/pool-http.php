<?php

/**
 * @noinspection ForgottenDebugOutputInspection
 * @noinspection PhpUnhandledExceptionInspection
 */

use Psl\Async;
use Psl\Str;
use Really\Payload;

require __DIR__ . '/../vendor/autoload.php';

$time = time();

$pool = new Really\Pool(__DIR__ . '/worker-http.php', workers_count: 16, concurrency_level: 50);
$awaitables = [];
for ($i = 0; $i < 2; $i++) {
    $awaitables[] = $pool->dispatch(Payload\GenericPayload::create([
        'host' => 'example.com',
        'path' => '/',
        'requests' => 1,
    ]));
}

foreach (Async\Awaitable::iterate($awaitables) as $awaitable) {
    /**
     * @var array{worker: int, responses: list<string>} $result
     */

    $result = $awaitable->await();
    if ($result->isFailed()) {
        echo "[job={$i}] failed: {$result->getException()->getMessage()}\n";
    } else {
        $result = $awaitable->await()->getResult();
        foreach ($result['responses'] as $response) {
            $header_line = Str\split($response, "\n")[0] ?? '';
            echo "[worker={$result['worker']}] {$header_line}\n";
        }
    }
}

echo 'Done: ' . (time() - $time) . 's' . PHP_EOL;

$pool->stop();

return 0;
