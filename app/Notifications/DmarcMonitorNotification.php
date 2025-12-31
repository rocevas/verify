<?php

namespace App\Notifications;

use App\Models\DmarcMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DmarcMonitorNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public DmarcMonitor $monitor,
        public array $checkResult
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $domain = $this->monitor->domain;
        $message = $this->checkResult['message'] ?? 'DMARC problema aptikta';
        $issueType = $this->checkResult['issue_type'] ?? 'unknown';

        return (new MailMessage)
            ->subject("⚠️ DMARC problema aptikta domenui {$domain}")
            ->greeting('Sveiki!')
            ->line("Jūsų stebimas DMARC domenui **{$domain}** turi problemų.")
            ->line("**Problema:** {$message}")
            ->line("**Tipas:** {$issueType}")
            ->line("Prašome patikrinti ir ištaisyti DMARC konfigūraciją.")
            ->action('Peržiūrėti monitorių', url('/admin/dmarc-monitors'))
            ->line('Jei turite klausimų, susisiekite su pagalba.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'monitor_id' => $this->monitor->id,
            'domain' => $this->monitor->domain,
            'issue_type' => $this->checkResult['issue_type'] ?? 'unknown',
            'message' => $this->checkResult['message'] ?? 'DMARC issue detected',
        ];
    }
}
