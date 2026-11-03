<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MaterialMonitorChange extends Notification
{
    use Queueable;

    /** @param list<string> $categories */
    public function __construct(
        public readonly string $scheduleId,
        public readonly string $beforeVersion,
        public readonly string $afterVersion,
        public readonly array $categories,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Material website evidence change detected')
            ->line('A private monitoring schedule detected a material evidence change.')
            ->line('Schedule: '.$this->scheduleId)
            ->line('Evidence versions: '.$this->beforeVersion.' and '.$this->afterVersion)
            ->line('Changed categories: '.implode(', ', $this->categories))
            ->line('No source URL, raw content, credentials, or private evidence is included in this notification.');
    }
}
