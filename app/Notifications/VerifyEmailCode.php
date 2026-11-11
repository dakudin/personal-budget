<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] public readonly string $code,
        public readonly string $email,
        #[\SensitiveParameter] public readonly string $codeHash,
        public readonly CarbonImmutable $expiresAt,
        public readonly int $expiresInMinutes,
    ) {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $user = $notifiable->fresh();

        return $user && ! $user->hasVerifiedEmail()
            && $user->email === $this->email
            && hash_equals($this->codeHash, (string) $user->email_verification_code_hash)
            && $user->email_verification_code_attempts < 5
            && $user->email_verification_code_expires_at?->isFuture()
            && $this->expiresAt->isFuture();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Підтвердження email — Особистий бюджет')
            ->greeting('Вітаємо!')
            ->line('Підтвердьте email '.$this->email.' для вашого облікового запису.')
            ->line('Ваш код підтвердження:')
            ->line('**'.$this->code.'**')
            ->line('Введіть цей код у формі підтвердження email.')
            ->line('Код діє '.$this->expiresInMinutes.' хвилин від моменту запиту. Повторне надсилання скасовує попередній код.')
            ->line('Якщо ви не створювали обліковий запис, проігноруйте цей лист.')
            ->salutation('З повагою, Personal Budget');
    }
}
