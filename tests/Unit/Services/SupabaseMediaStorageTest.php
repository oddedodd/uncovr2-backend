<?php

namespace Tests\Unit\Services;

use App\Services\Media\SupabaseMediaStorage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseMediaStorageTest extends TestCase
{
    public function test_private_and_public_buckets_are_reconciled_with_limits(): void
    {
        config()->set('services.supabase.url', 'https://project.supabase.co');
        config()->set('services.supabase.secret_key', 'sb_secret_backend');
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/bucket/uncovr-private-media')) {
                return Http::response(['id' => 'uncovr-private-media']);
            }
            if ($request->method() === 'GET') {
                return Http::response([], 404);
            }

            return Http::response(['ok' => true]);
        });

        (new SupabaseMediaStorage)->provisionBuckets();

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/bucket/uncovr-private-media')
            && $request['public'] === false
            && $request['file_size_limit'] === 50 * 1024 * 1024);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/bucket')
            && $request['id'] === 'uncovr-public-media'
            && $request['public'] === true
            && in_array('image/png', $request['allowed_mime_types'], true));
    }

    public function test_new_secret_key_is_only_sent_as_api_key_and_signed_upload_is_parsed(): void
    {
        config()->set('services.supabase.url', 'https://project.supabase.co');
        config()->set('services.supabase.secret_key', 'sb_secret_backend');
        Http::fake([
            'https://project.supabase.co/storage/v1/object/upload/sign/private/folder/image.png' => Http::response([
                'url' => '/object/upload/sign/private/folder/image.png?token=upload-token',
            ]),
        ]);

        $result = (new SupabaseMediaStorage)->createSignedUpload('private', 'folder/image.png');

        $this->assertSame('upload-token', $result['token']);
        $this->assertSame('https://project.supabase.co/storage/v1/object/upload/sign/private/folder/image.png?token=upload-token', $result['url']);
        Http::assertSent(fn ($request) => $request->hasHeader('apikey', 'sb_secret_backend') && ! $request->hasHeader('Authorization'));
    }

    public function test_batch_signing_uses_one_request_and_maps_every_path(): void
    {
        config()->set('services.supabase.url', 'https://project.supabase.co');
        config()->set('services.supabase.secret_key', 'sb_secret_backend');
        Http::fake([
            'https://project.supabase.co/storage/v1/object/sign/private' => Http::response([
                ['error' => null, 'path' => 'a.png', 'signedURL' => '/object/sign/private/a.png?token=one'],
                ['error' => null, 'path' => 'b.png', 'signedURL' => '/object/sign/private/b.png?token=two'],
            ]),
        ]);

        $signed = (new SupabaseMediaStorage)->createSignedDownloads('private', ['a.png', 'b.png'], 900);

        $this->assertSame([
            'a.png' => 'https://project.supabase.co/storage/v1/object/sign/private/a.png?token=one',
            'b.png' => 'https://project.supabase.co/storage/v1/object/sign/private/b.png?token=two',
        ], $signed);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['expiresIn'] === 900
            && $request['paths'] === ['a.png', 'b.png']);
    }

    public function test_batch_signing_fails_when_a_path_is_missing_from_the_response(): void
    {
        config()->set('services.supabase.url', 'https://project.supabase.co');
        config()->set('services.supabase.secret_key', 'sb_secret_backend');
        Http::fake(['*' => Http::response([
            ['error' => 'not found', 'path' => 'a.png', 'signedURL' => null],
        ])]);

        $this->expectExceptionMessage('did not return a signed download URL for a.png');

        (new SupabaseMediaStorage)->createSignedDownloads('private', ['a.png'], 900);
    }

    public function test_legacy_service_role_key_is_also_sent_as_bearer_token(): void
    {
        config()->set('services.supabase.url', 'https://project.supabase.co');
        config()->set('services.supabase.secret_key', 'legacy-jwt');
        Http::fake(['*' => Http::response(['signedURL' => '/object/sign/private/file.pdf?token=download'])]);

        (new SupabaseMediaStorage)->createSignedDownload('private', 'file.pdf', 60);

        Http::assertSent(fn ($request) => $request->hasHeader('apikey', 'legacy-jwt') && $request->hasHeader('Authorization', 'Bearer legacy-jwt'));
    }
}
