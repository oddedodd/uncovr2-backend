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

    public function test_legacy_service_role_key_is_also_sent_as_bearer_token(): void
    {
        config()->set('services.supabase.url', 'https://project.supabase.co');
        config()->set('services.supabase.secret_key', 'legacy-jwt');
        Http::fake(['*' => Http::response(['signedURL' => '/object/sign/private/file.pdf?token=download'])]);

        (new SupabaseMediaStorage)->createSignedDownload('private', 'file.pdf', 60);

        Http::assertSent(fn ($request) => $request->hasHeader('apikey', 'legacy-jwt') && $request->hasHeader('Authorization', 'Bearer legacy-jwt'));
    }
}
