<?php

namespace Really;

function worker(\Closure $closure): void
{
    Worker::create()->run($closure);
}
