<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

class VerifyEmail extends BaseVerifyEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Retain compatibility with queued signed-link notifications without sending them.
        return false;
    }
}
