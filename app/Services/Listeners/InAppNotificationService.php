<?php

namespace App\Services\Listeners;

use App\Models\ListenerNotification;
use App\Models\User;

final class InAppNotificationService
{
    public function create(User $user, string $type, string $title, string $body, array $data = [], ?string $topic = null, bool $required = false): ?ListenerNotification
    {
        if (! $required && $topic !== null) {
            $preference = $user->notificationPreferences()->where('topic', $topic)->first();
            if ($preference && ! $preference->in_app_enabled) {
                return null;
            }
        }

        return $user->listenerNotifications()->create(compact('type', 'title', 'body', 'data'));
    }
}
