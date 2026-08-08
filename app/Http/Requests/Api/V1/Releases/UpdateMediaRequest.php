<?php

namespace App\Http\Requests\Api\V1\Releases;

use App\Http\Requests\Api\V1\StrictFormRequest;

final class UpdateMediaRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return ['original_filename' => ['sometimes', 'string', 'max:255'], 'mime_type' => ['sometimes', 'string', 'max:100'], 'byte_size' => ['sometimes', 'nullable', 'integer', 'min:0'], 'width' => ['sometimes', 'nullable', 'integer', 'min:1'], 'height' => ['sometimes', 'nullable', 'integer', 'min:1'], 'metadata' => ['sometimes', 'nullable', 'array'], 'owner_type' => ['prohibited'], 'owner_id' => ['prohibited'], 'storage_disk' => ['prohibited'], 'storage_key' => ['prohibited'], 'status' => ['prohibited']];
    }
}
