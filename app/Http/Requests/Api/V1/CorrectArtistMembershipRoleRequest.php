<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ArtistRole;
use App\Models\User;
use Illuminate\Validation\Rule;

final class CorrectArtistMembershipRoleRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'role' => ['required', 'string', Rule::enum(ArtistRole::class)],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirmation' => ['required', 'string', Rule::in([
                $target instanceof User ? $target->public_id : null,
            ])],
        ];
    }
}
