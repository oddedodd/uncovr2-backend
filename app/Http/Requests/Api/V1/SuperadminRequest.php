<?php

namespace App\Http\Requests\Api\V1;

final class SuperadminRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    public function rules(): array
    {
        return [];
    }
}
