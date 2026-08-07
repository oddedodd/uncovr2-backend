<?php

namespace App\Services\Auth;

final class RefreshTokenGenerator
{
    public function generate(): string
    {
        $entropy = random_bytes(config('authentication.refresh_token_bytes'));

        return config('authentication.refresh_token_prefix')
            .rtrim(strtr(base64_encode($entropy), '+/', '-_'), '=');
    }
}
