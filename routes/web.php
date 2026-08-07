<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (app()->isLocal()) {
    Route::view('/dev/mail/verify-email', 'mail.auth.verify-email', [
        'displayName' => 'Ada Artist',
        'verificationUrl' => 'https://api.example.com/api/v1/auth/verify-email/example',
        'expiresInMinutes' => 60,
    ])->name('dev.mail.verify-email');

    Route::view('/dev/mail/reset-password', 'mail.auth.reset-password', [
        'displayName' => 'Ada Artist',
        'resetUrl' => 'http://localhost:3000/reset-password?token=example&email=ada@example.com',
        'expiresInMinutes' => 60,
    ])->name('dev.mail.reset-password');
}
