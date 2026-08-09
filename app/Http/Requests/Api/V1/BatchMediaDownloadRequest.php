<?php

namespace App\Http\Requests\Api\V1;

final class BatchMediaDownloadRequest extends StrictFormRequest
{
    public function rules(): array
    {
        return [
            'media_ids' => ['required', 'array', 'min:1', 'max:'.config('media.batch_download_limit')],
            'media_ids.*' => ['required', 'ulid', 'distinct'],
        ];
    }
}
