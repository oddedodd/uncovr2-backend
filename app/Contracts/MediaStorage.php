<?php

namespace App\Contracts;

use App\Data\StoredObject;

interface MediaStorage
{
    public function provisionBuckets(): void;

    /** @return array{url: string, token: string} */
    public function createSignedUpload(string $bucket, string $path): array;

    public function inspect(string $bucket, string $path, bool $includeBody = false): StoredObject;

    public function createSignedDownload(string $bucket, string $path, int $expiresIn): string;

    public function upload(string $bucket, string $path, string $body, string $mimeType): void;

    public function copy(string $sourceBucket, string $sourcePath, string $destinationBucket, string $destinationPath): void;

    public function delete(string $bucket, string $path): void;

    public function publicUrl(string $bucket, string $path): string;
}
