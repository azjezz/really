<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Psl\Shell;
use Psl\Str;
use Really\Payload;

Really\worker(function(Payload\GenericPayload $payload, Really\Worker $worker): array {
    /** @var string $message */
    $message = $payload->data['message'];
    /** @var string $duration */
    $duration = $payload->data['duration'];

    // execute a shell command that takes $duration second(s)
    Shell\execute('sleep', [$duration]);

    return [
        'worker' => $worker->getId(),
        'response' => Str\reverse($message),
    ];
});
