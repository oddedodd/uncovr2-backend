<?php

namespace App\Services\Privacy;

use App\Models\ConsentRecord;
use App\Models\User;
use Illuminate\Http\Request;

final class ConsentRecorder
{
    public function record(User $user, string $purpose, bool $granted, string $version, string $source, Request $request): ConsentRecord
    {
        $ip = $request->ip();

        return $user->consentRecords()->create([
            'purpose' => $purpose, 'granted' => $granted, 'policy_version' => $version, 'source' => $source,
            'ip_address_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null, 'recorded_at' => now(),
        ]);
    }

    public function recordRegistration(User $user, Request $request): void
    {
        $this->record($user, 'terms', true, config('privacy.terms_version'), 'registration', $request);
        $this->record($user, 'privacy', true, config('privacy.privacy_version'), 'registration', $request);
        foreach (['marketing_email', 'marketing_push'] as $purpose) {
            if ($request->has("consents.{$purpose}")) {
                $this->record($user, $purpose, $request->boolean("consents.{$purpose}"), config('privacy.privacy_version'), 'registration', $request);
            }
        }
    }
}
