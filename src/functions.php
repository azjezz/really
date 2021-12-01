<?php

namespace Really;

use Psl\Async;

function worker(\Closure $closure): void
{
    Async\main(static fn() => Worker::create()->run($closure));
}
