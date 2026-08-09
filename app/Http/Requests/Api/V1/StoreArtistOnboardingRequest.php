<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ArtistRole;
use Illuminate\Validation\Rule;

final class StoreArtistOnboardingRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'artist' => ['required', 'array:name,biography,website_url'],
            'artist.name' => ['required', 'string', 'min:1', 'max:150'],
            'artist.biography' => ['nullable', 'string', 'max:10000'],
            'artist.website_url' => ['nullable', 'url:http,https', 'max:2048'],
            'administrator' => ['required', 'array:email'],
            'administrator.email' => [
                'required', 'string', 'email:rfc', 'max:254',
                Rule::notIn([$this->user()?->email]),
            ],
            'relationship_type' => ['sometimes', 'string', 'in:managing_label,distributor'],
            'creator_role' => ['sometimes', 'nullable', Rule::enum(ArtistRole::class)],
            'confirmation' => ['required', 'accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('administrator.email')) {
            $administrator = (array) $this->input('administrator');
            $administrator['email'] = strtolower(trim((string) ($administrator['email'] ?? '')));
            $this->merge(['administrator' => $administrator]);
        }
    }
}
