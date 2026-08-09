<?php

namespace Tests\Fakes;

use App\Contracts\MediaStorage;
use App\Data\StoredObject;
use RuntimeException;

final class FakeMediaStorage implements MediaStorage
{
    /** @var array<string, StoredObject> */
    public array $objects = [];

    public array $deleted = [];

    public array $copies = [];

    public bool $provisioned = false;

    public function provisionBuckets(): void
    {
        $this->provisioned = true;
    }

    public function createSignedUpload(string $bucket, string $path): array
    {
        return ['url' => "https://storage.test/upload/{$bucket}/{$path}?token=signed-token", 'token' => 'signed-token'];
    }

    public function inspect(string $bucket, string $path, bool $includeBody = false): StoredObject
    {
        return $this->objects["{$bucket}/{$path}"] ?? throw new RuntimeException('Object not found.');
    }

    public function createSignedDownload(string $bucket, string $path, int $expiresIn): string
    {
        return "https://storage.test/download/{$bucket}/{$path}?expires={$expiresIn}";
    }

    public function upload(string $bucket, string $path, string $body, string $mimeType): void
    {
        $this->objects["{$bucket}/{$path}"] = new StoredObject($mimeType, strlen($body), $body, hash('sha256', $body));
    }

    public function copy(string $sourceBucket, string $sourcePath, string $destinationBucket, string $destinationPath): void
    {
        $this->copies[] = compact('sourceBucket', 'sourcePath', 'destinationBucket', 'destinationPath');
    }

    public function delete(string $bucket, string $path): void
    {
        $this->deleted[] = "{$bucket}/{$path}";
    }

    public function publicUrl(string $bucket, string $path): string
    {
        return "https://storage.test/public/{$bucket}/{$path}";
    }
}
