<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordToken extends Notification
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly int $expiresInMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Скидання пароля — Особистий бюджет')
            ->greeting('Вітаємо!')
            ->line('Ми отримали запит на скидання пароля вашого облікового запису.')
            ->line('Скопіюйте цей одноразовий токен для відновлення пароля:')
            ->line('`'.$this->token.'`')
            ->line('Токен діє '.$this->expiresInMinutes.' хвилин і може бути використаний лише один раз.')
            ->line('Передайте email, token, password та password_confirmation у форму скідання паролю.')
            ->line('Якщо ви не надсилали запит, проігноруйте цей лист.')
            ->salutation('З повагою, Personal Budget');
    }
}
