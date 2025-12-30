<?php

namespace App\Notifications;

use App\Models\BlocklistMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BlocklistMonitorNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public BlocklistMonitor $monitor,
        public array $checkResult
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $blocklists = implode(', ', $this->checkResult['blocklists'] ?? []);
        $target = $this->monitor->target;
        $type = $this->monitor->type === 'domain' ? 'Domenas' : 'IP adresas';

        return (new MailMessage)
            ->subject("⚠️ {$type} {$target} pateko į blocklistą")
            ->greeting('Sveiki!')
            ->line("Jūsų stebimas {$type} **{$target}** buvo aptiktas blocklistose.")
            ->line("**Monitorius:** {$this->monitor->name}")
            ->line("**Rastas blocklistose:** {$blocklists}")
            ->line("Prašome imtis veiksmų, kad pašalintumėte {$type} iš blocklistų.")
            ->action('Peržiūrėti monitorių', url('/admin/monitors'))
            ->line('Jei turite klausimų, susisiekite su pagalba.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'monitor_id' => $this->monitor->id,
            'monitor_name' => $this->monitor->name,
            'target' => $this->monitor->target,
            'type' => $this->monitor->type,
            'blocklists' => $this->checkResult['blocklists'] ?? [],
        ];
    }
}
