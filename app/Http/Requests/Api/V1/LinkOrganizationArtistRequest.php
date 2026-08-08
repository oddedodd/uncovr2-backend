<?php

namespace App\Http\Requests\Api\V1;

final class LinkOrganizationArtistRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'artist_id' => ['required', 'ulid', 'exists:artists,public_id'],
            'relationship_type' => ['sometimes', 'string', 'in:managing_label,distributor'],
        ];
    }
}
