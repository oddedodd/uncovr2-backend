<?php

namespace Tests\Feature\Domain;

use Tests\Feature\Domain\Concerns\BuildsReleaseDomain;
use Tests\TestCase;

class ReleasePaginationTest extends TestCase
{
    use BuildsReleaseDomain;

    public function test_release_collection_uses_opaque_cursor_pagination_without_duplicates(): void
    {
        $admin = $this->domainUser('admin@example.com');
        $organization = $this->domainOrganization($admin);
        $artist = $this->domainArtist($admin);
        $this->linkArtist($organization, $artist, $admin);
        foreach (['First', 'Second', 'Third'] as $title) {
            $this->createOrganizationRelease($admin, $organization, $artist, ['title' => $title]);
        }

        $first = $this->getApi('/releases?page%5Bsize%5D=2')->assertOk()->assertJsonCount(2, 'data');
        $cursor = $first->json('meta.pagination.next_cursor');
        $this->assertIsString($cursor);
        $second = $this->getApi('/releases?page%5Bsize%5D=2&page%5Bafter%5D='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data');

        $ids = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')];
        $this->assertCount(3, array_unique($ids));
        $this->assertFalse($second->json('meta.pagination.has_more'));
    }

    public function test_invalid_page_parameters_are_rejected(): void
    {
        $user = $this->domainUser('user@example.com');
        $this->actAsDomain($user);
        $this->assertApiError($this->getApi('/releases?page%5Bsize%5D=0'), 422, 'validation_failed');
        $this->assertApiError($this->getApi('/releases?page%5Bafter%5D=invalid'), 422, 'validation_failed');
        $this->assertApiError($this->getApi('/releases?page%5Bafter%5D=a&page%5Bbefore%5D=b'), 422, 'validation_failed');
        $this->assertApiError($this->getApi('/releases?unknown=value'), 422, 'validation_failed');
    }
}
