<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramWebhookManager;
use Illuminate\Console\Command;

class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {action=set : set|info|delete}';

    protected $description = 'Register, inspect, or remove the Telegram bot webhook for inbound commands';

    public function handle(TelegramWebhookManager $manager): int
    {
        $action = strtolower((string) $this->argument('action'));

        $result = match ($action) {
            'set' => $manager->register(),
            'info' => $manager->info(),
            'delete' => $manager->delete(),
            default => null,
        };

        if ($result === null) {
            $this->error("Aksi tidak dikenal: {$action}. Gunakan: set | info | delete.");

            return self::INVALID;
        }

        if (! $result['ok']) {
            $this->error('Gagal: '.$result['message']);

            return self::FAILURE;
        }

        if ($action === 'info') {
            foreach (($result['info'] ?? []) as $key => $value) {
                $this->line('  '.str_pad((string) $key, 24).' : '.(is_scalar($value) ? (string) $value : json_encode($value)));
            }

            return self::SUCCESS;
        }

        $this->info($result['message'].(isset($result['url']) ? ' → '.$result['url'] : ''));

        return self::SUCCESS;
    }
}
