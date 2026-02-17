<?php

namespace App\Services;

use App\Models\User;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Laravel\Firebase\Facades\Firebase;

class FirebaseService
{
    public function sendNotification(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->fcmTokens->pluck('token')->toArray();

        if (empty($tokens)) return;

        $notification = Notification::create($title, $body);

        $messages = array_map(function ($token) use ($notification, $data) {
            return CloudMessage::withTarget('token', $token)
                ->withNotification($notification)
                ->withData($data);
        }, $tokens);

        Firebase::messaging()->sendAll($messages);
    }
}