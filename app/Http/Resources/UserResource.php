<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'email' => $this->email,
            'display_name' => $this->profile?->display_name,
            'is_superadmin' => $this->is_superadmin,
            'status' => $this->status->value,
            'suspended_at' => $this->suspended_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'suspension_reason' => $this->suspension_reason,
            'email_verified_at' => $this->email_verified_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'deletion_requested_at' => $this->deletion_requested_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'anonymized_at' => $this->anonymized_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updated_at' => $this->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
