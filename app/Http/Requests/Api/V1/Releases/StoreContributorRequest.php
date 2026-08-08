<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreContributorRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['owner_type' => ['required', Rule::in(['organization', 'artist'])], 'owner_id' => ['required', 'ulid'], 'display_name' => ['required', 'string', 'min:1', 'max:200'], 'legal_name' => ['nullable', 'string', 'max:200'], 'email' => ['nullable', 'email:rfc', 'max:254'], 'website_url' => ['nullable', 'url:http,https', 'max:2048']];
    }
}
