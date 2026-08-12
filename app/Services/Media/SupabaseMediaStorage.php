<?php

namespace App\Services\Media;

use App\Contracts\MediaStorage;
use App\Data\StoredObject;
use App\Support\RequestPerformanceMetrics;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SupabaseMediaStorage implements MediaStorage
{
    public function __construct(private readonly ?RequestPerformanceMetrics $metrics = null) {}

    public function provisionBuckets(): void
    {
        $mimeTypes = collect(config('media.limits'))->flatMap(fn (array $limit): array => $limit['mime_types'])->unique()->values()->all();
        foreach ([config('media.private_bucket') => false, config('media.public_bucket') => true] as $bucket => $public) {
            $payload = ['id' => $bucket, 'name' => $bucket, 'public' => $public, 'file_size_limit' => 50 * 1024 * 1024, 'allowed_mime_types' => $mimeTypes];
            $existing = $this->request()->get($this->endpoint('bucket/'.rawurlencode($bucket)));
            $response = $existing->successful()
                ? $this->request()->put($this->endpoint('bucket/'.rawurlencode($bucket)), $payload)
                : $this->request()->post($this->endpoint('bucket'), $payload);
            $this->ensureSuccessful($response->successful(), $response->body());
        }
    }

    public function createSignedUpload(string $bucket, string $path): array
    {
        $response = $this->timed(fn () => $this->request()->post($this->endpoint("object/upload/sign/{$this->encode($bucket, $path)}"), []));
        $this->ensureSuccessful($response->successful(), $response->body());
        $relativeUrl = $response->json('url');
        if (! is_string($relativeUrl) || ! str_contains($relativeUrl, 'token=')) {
            throw new RuntimeException('Supabase Storage did not return a signed upload token.');
        }
        $url = rtrim($this->url(), '/').'/storage/v1'.(str_starts_with($relativeUrl, '/') ? '' : '/').$relativeUrl;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['url' => $url, 'token' => (string) ($query['token'] ?? '')];
    }

    public function inspect(string $bucket, string $path, bool $includeBody = false): StoredObject
    {
        $encoded = $this->encode($bucket, $path);
        $response = $this->timed(fn () => $this->request()->get($this->endpoint("object/info/{$encoded}")));
        $this->ensureSuccessful($response->successful(), $response->body());
        $metadata = $response->json('metadata') ?? [];
        $mime = $response->json('content_type') ?? $response->json('contentType') ?? $metadata['mimetype'] ?? $metadata['contentType'] ?? null;
        $size = $response->json('size') ?? $metadata['size'] ?? null;
        if (! is_string($mime) || ! is_numeric($size)) {
            throw new RuntimeException('Supabase Storage returned incomplete object metadata.');
        }
        $body = null;
        if ($includeBody) {
            $download = $this->timed(fn () => $this->request()->get($this->endpoint("object/{$encoded}")));
            $this->ensureSuccessful($download->successful(), $download->body());
            $body = $download->body();
        }

        return new StoredObject($mime, (int) $size, $body);
    }

    public function createSignedDownload(string $bucket, string $path, int $expiresIn): string
    {
        $response = $this->timed(fn () => $this->request()->post($this->endpoint("object/sign/{$this->encode($bucket, $path)}"), ['expiresIn' => $expiresIn]));
        $this->ensureSuccessful($response->successful(), $response->body());
        $signed = $response->json('signedURL') ?? $response->json('signedUrl');
        if (! is_string($signed)) {
            throw new RuntimeException('Supabase Storage did not return a signed download URL.');
        }

        return rtrim($this->url(), '/').'/storage/v1'.(str_starts_with($signed, '/') ? '' : '/').$signed;
    }

    public function createSignedDownloads(string $bucket, array $paths, int $expiresIn): array
    {
        $paths = array_values(array_unique($paths));

        if ($paths === []) {
            return [];
        }

        $response = $this->timed(fn () => $this->request()->post(
            $this->endpoint('object/sign/'.rawurlencode($bucket)),
            ['expiresIn' => $expiresIn, 'paths' => $paths],
        ));
        $this->ensureSuccessful($response->successful(), $response->body());

        $signed = [];
        foreach ($response->json() ?? [] as $entry) {
            $path = $entry['path'] ?? null;
            $url = $entry['signedURL'] ?? $entry['signedUrl'] ?? null;
            if (is_string($path) && is_string($url)) {
                $signed[$path] = rtrim($this->url(), '/').'/storage/v1'.(str_starts_with($url, '/') ? '' : '/').$url;
            }
        }

        foreach ($paths as $path) {
            if (! isset($signed[$path])) {
                throw new RuntimeException("Supabase Storage did not return a signed download URL for {$path}.");
            }
        }

        return $signed;
    }

    public function copy(string $sourceBucket, string $sourcePath, string $destinationBucket, string $destinationPath): void
    {
        $response = $this->timed(fn () => $this->request()->post($this->endpoint('object/copy'), [
            'bucketId' => $sourceBucket,
            'sourceKey' => $sourcePath,
            'destinationBucket' => $destinationBucket,
            'destinationKey' => $destinationPath,
        ]));
        $this->ensureSuccessful($response->successful(), $response->body());
    }

    public function delete(string $bucket, string $path): void
    {
        $response = $this->timed(fn () => $this->request()->delete($this->endpoint('object/'.rawurlencode($bucket)), ['prefixes' => [$path]]));
        $this->ensureSuccessful($response->successful(), $response->body());
    }

    public function upload(string $bucket, string $path, string $body, string $mimeType): void
    {
        $response = $this->timed(fn () => $this->request()
            ->withBody($body, $mimeType)
            ->put($this->endpoint("object/{$this->encode($bucket, $path)}")));
        $this->ensureSuccessful($response->successful(), $response->body());
    }

    public function publicUrl(string $bucket, string $path): string
    {
        return $this->endpoint("object/public/{$this->encode($bucket, $path)}");
    }

    private function request(): PendingRequest
    {
        $key = config('services.supabase.secret_key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('SUPABASE_SECRET_KEY is not configured.');
        }

        $headers = ['apikey' => $key];
        if (! str_starts_with($key, 'sb_secret_')) {
            $headers['Authorization'] = "Bearer {$key}";
        }

        return Http::acceptJson()->withHeaders($headers)->timeout(20)->retry(2, 200, throw: false);
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->url(), '/').'/storage/v1/'.ltrim($path, '/');
    }

    private function url(): string
    {
        $url = config('services.supabase.url');
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('SUPABASE_URL must be a valid HTTPS project URL.');
        }

        return $url;
    }

    private function encode(string $bucket, string $path): string
    {
        return collect(explode('/', $bucket.'/'.$path))->map(fn (string $part): string => rawurlencode($part))->implode('/');
    }

    private function ensureSuccessful(bool $successful, string $body): void
    {
        if (! $successful) {
            throw new RuntimeException('Supabase Storage request failed: '.str($body)->limit(300));
        }
    }

    private function timed(callable $request): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $request();
        } finally {
            ($this->metrics ?? app(RequestPerformanceMetrics::class))
                ->recordStorageCall((hrtime(true) - $startedAt) / 1_000_000);
        }
    }
}
