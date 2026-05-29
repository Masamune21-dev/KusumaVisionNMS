<?php

namespace App\Services\Telegram;

use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Models\TelegramSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramNotifier
{
    private const API_BASE = 'https://api.telegram.org';

    private const MAX_ITEMS_PER_MESSAGE = 10;

    private const SEVERITY_EMOJI = [
        AlarmEvent::SEVERITY_CRITICAL => '🔴',
        AlarmEvent::SEVERITY_MAJOR => '🟠',
        AlarmEvent::SEVERITY_MINOR => '🟡',
        AlarmEvent::SEVERITY_WARNING => '⚪',
    ];

    /**
     * Notify newly raised / cleared alarms for an OLT poll cycle.
     *
     * Never throws: a Telegram outage must not break alarm reconciliation.
     *
     * @param  array<int, AlarmEvent>  $raised
     * @param  array<int, AlarmEvent>  $cleared
     */
    public function notify(SnmpOlt $olt, array $raised = [], array $cleared = []): void
    {
        if ($raised === [] && $cleared === []) {
            return;
        }

        if ($olt->is_demo) {
            return;
        }

        $setting = TelegramSetting::instance();

        if (! $setting->isReady()) {
            return;
        }

        $sections = [];

        if ($setting->notify_on_raise) {
            $eligible = $this->filterBySeverity($raised, $setting->minSeverityRank());

            foreach ($eligible as $alarm) {
                $sections[] = $this->formatAlarm($alarm, raised: true);
            }
        }

        if ($setting->notify_on_clear) {
            foreach ($cleared as $alarm) {
                $sections[] = $this->formatAlarm($alarm, raised: false);
            }
        }

        if ($sections === []) {
            return;
        }

        // Telegram caps a message at 4096 chars, so a big batch is split into several
        // messages of at most MAX_ITEMS_PER_MESSAGE alarms each.
        $chunks = array_chunk($sections, self::MAX_ITEMS_PER_MESSAGE);
        $total = count($chunks);
        $timestamp = Carbon::now()->timezone(config('app.display_timezone', 'Asia/Jakarta'))->translatedFormat('d M Y H:i').' WIB';

        try {
            foreach ($chunks as $index => $chunk) {
                $header = '<b>KusumaVision NMS — Alarm</b>'
                    .($total > 1 ? ' ('.($index + 1).'/'.$total.')' : '')."\n"
                    .'OLT: <b>'.$this->escape((string) $olt->name).'</b>';

                $this->dispatch($setting, $header."\n\n".implode("\n\n", $chunk)."\n\n🕒 ".$timestamp);
            }
        } catch (Throwable $exception) {
            Log::warning('Telegram alarm notification failed', [
                'olt_id' => $olt->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Send a test message using the persisted settings.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function sendTest(): array
    {
        $setting = TelegramSetting::instance();

        if (! $setting->isConfigured()) {
            return ['ok' => false, 'error' => 'Bot token dan chat ID harus diisi terlebih dahulu.'];
        }

        $text = '✅ <b>KusumaVision NMS</b>'."\n"
            .'Tes notifikasi Telegram berhasil. Bot terhubung dengan benar.'
            ."\n\n🕒 ".Carbon::now()->timezone(config('app.display_timezone', 'Asia/Jakarta'))->translatedFormat('d M Y H:i').' WIB';

        try {
            return $this->dispatch($setting, $text);
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Push a message to every configured chat id and record the outcome.
     *
     * @return array{ok: bool, error: ?string}
     */
    private function dispatch(TelegramSetting $setting, string $text): array
    {
        $token = (string) $setting->bot_token;
        $error = null;

        foreach ($setting->chatIds() as $chatId) {
            $response = Http::asJson()
                ->timeout(10)
                ->post(self::API_BASE."/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful() || $response->json('ok') !== true) {
                $error = $response->json('description')
                    ?: 'HTTP '.$response->status().' dari Telegram.';
            }
        }

        $setting->forceFill([
            'last_sent_at' => Carbon::now(),
            'last_error' => $error,
        ])->save();

        return ['ok' => $error === null, 'error' => $error];
    }

    /**
     * @param  array<int, AlarmEvent>  $alarms
     * @return array<int, AlarmEvent>
     */
    private function filterBySeverity(array $alarms, int $minRank): array
    {
        return array_values(array_filter(
            $alarms,
            fn (AlarmEvent $alarm) => (TelegramSetting::SEVERITY_RANK[$alarm->severity] ?? 1) >= $minRank,
        ));
    }

    private function formatAlarm(AlarmEvent $alarm, bool $raised): string
    {
        $emoji = self::SEVERITY_EMOJI[$alarm->severity] ?? '⚪';
        $prefix = $raised ? $emoji : '✅';
        $tag = $raised ? strtoupper($alarm->severity) : 'CLEARED';

        // A cleared alarm reports the recovered state (online + latest RX), not the
        // original fault text it was raised with.
        $message = $raised
            ? (string) $alarm->message
            : (string) (data_get($alarm->meta, 'recovery.message') ?? $alarm->message);

        $line = $prefix.' <b>'.$this->escape($tag).'</b> · '.$this->escape((string) $alarm->type)
            ."\n".$this->escape($message);

        $customer = data_get($alarm->meta, 'customer_name');

        if (filled($customer)) {
            $line .= "\n👤 ".$this->escape((string) $customer);
        }

        return $line;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
