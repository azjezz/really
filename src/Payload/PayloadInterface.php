<?php

declare(strict_types=1);

namespace Really\Payload;

/**
 * @template TPayloadType
 * @template TResult
 */
interface PayloadInterface
{
    /**
     * @return TPayloadType
     */
    public function __serialize(): array;

    /**
     * @param TPayloadType $data
     */
    public function __unserialize(array $data): void;
}
