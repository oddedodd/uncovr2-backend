<?php

namespace Tests\Unit\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class HasPublicIdTest extends TestCase
{
    public function test_it_generates_a_lowercase_ulid_without_replacing_the_internal_key(): void
    {
        $model = new PublicModelFixture;
        $model->setUniqueIds();

        $this->assertTrue(Str::isUlid($model->public_id));
        $this->assertSame(strtolower($model->public_id), $model->public_id);
        $this->assertSame(['public_id'], $model->uniqueIds());
        $this->assertSame('public_id', $model->getRouteKeyName());
        $this->assertTrue($model->getIncrementing());
        $this->assertSame('int', $model->getKeyType());
    }

    public function test_internal_keys_are_hidden_when_a_model_is_serialized(): void
    {
        $model = new PublicModelFixture;
        $model->forceFill([
            'id' => 123,
            'public_id' => '01k00000000000000000000000',
            'name' => 'Example',
        ]);

        $this->assertSame([
            'public_id' => '01k00000000000000000000000',
            'name' => 'Example',
        ], $model->toArray());
    }

    public function test_invalid_public_route_identifiers_are_rejected_before_querying(): void
    {
        $this->expectException(ModelNotFoundException::class);

        (new PublicModelFixture)->resolveRouteBindingQuery(null, '123');
    }
}

class PublicModelFixture extends Model
{
    use HasPublicId;

    protected $guarded = [];
}
