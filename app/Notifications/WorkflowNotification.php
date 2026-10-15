<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly string $url,
        public readonly string $kind = 'workflow',
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('impact.email_notifications') && filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'kind' => $this->kind,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message)
            ->action('Open AASRAA Monitoring & Evaluation', $this->url);
    }
}
