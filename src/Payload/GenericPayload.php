<?php

declare(strict_types=1);

namespace Really\Payload;

/**
 * @implements PayloadInterface<array<array-key, mixed>, mixed>
 */
final class GenericPayload implements PayloadInterface
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        public readonly array $data,
    ) {}

    public static function create(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function __serialize(): array
    {
        return $this->data;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->data = $data;
    }
}
