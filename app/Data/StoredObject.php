<?php

namespace App\Data;

final readonly class StoredObject
{
    public function __construct(
        public string $mimeType,
        public int $byteSize,
        public ?string $body = null,
        public ?string $checksum = null,
    ) {}
}
