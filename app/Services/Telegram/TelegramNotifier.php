<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramBotConfig;
use App\Models\AlarmEvent;
use App\Models\PartnerTelegramBot;
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

    public const SEVERITY_EMOJI = [
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

        // Bot global (semua OLT) + tiap bot partner yang di-assign ke OLT ini.
        foreach ($this->configsFor($olt) as $config) {
            $this->notifyConfig($config, $olt, $raised, $cleared);
        }
    }

    /**
     * Semua bot yang harus menerima alarm OLT ini: global admin + bot partner ter-assign.
     *
     * @return array<int, TelegramBotConfig>
     */
    private function configsFor(SnmpOlt $olt): array
    {
        $configs = [];

        // Bot global admin (dipakai admin & operator) hanya bila saklar alarm OLT on
        // DAN OLT bukan milik privat partner (owner_user_id null). OLT privat partner
        // hanya memberi tahu bot partner pemiliknya (di bawah).
        $global = TelegramSetting::instance();
        if ($olt->owner_user_id === null && $olt->alarms_enabled && $global->isReady()) {
            $configs[] = $global;
        }

        // Bot partner hanya bila OLT di-assign ke partner tsb DAN saklar alarm partner utk OLT itu on.
        $partnerBots = PartnerTelegramBot::query()
            ->whereHas('user.partnerOlts', fn ($q) => $q->whereKey($olt->id)
                ->where('olt_user.alarms_enabled', true))
            ->get();

        foreach ($partnerBots as $bot) {
            if ($bot->isReady()) {
                $configs[] = $bot;
            }
        }

        return $configs;
    }

    /**
     * Kirim batch alarm ke satu bot dengan filter severity/jenis milik bot tsb.
     *
     * @param  array<int, AlarmEvent>  $raised
     * @param  array<int, AlarmEvent>  $cleared
     */
    private function notifyConfig(TelegramBotConfig $config, SnmpOlt $olt, array $raised, array $cleared): void
    {
        $sections = [];

        if ($config->notifyOnRaise()) {
            $eligible = $this->filterBySeverity($raised, $config->minSeverityRank());

            foreach ($eligible as $alarm) {
                if (! $config->shouldNotifyType($alarm->type)) {
                    continue;
                }

                $sections[] = $this->formatAlarm($alarm, raised: true);
            }
        }

        if ($config->notifyOnClear()) {
            foreach ($cleared as $alarm) {
                if (! $config->shouldNotifyType($alarm->type)) {
                    continue;
                }

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

                $this->dispatch($config, $header."\n\n".implode("\n\n", $chunk)."\n\n🕒 ".$timestamp);
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
    public function sendTest(?TelegramBotConfig $config = null): array
    {
        $config ??= TelegramSetting::instance();

        if (! $config->isConfigured()) {
            return ['ok' => false, 'error' => 'Bot token dan chat ID harus diisi terlebih dahulu.'];
        }

        $text = '✅ <b>KusumaVision NMS</b>'."\n"
            .'Tes notifikasi Telegram berhasil. Bot terhubung dengan benar.'
            ."\n\n🕒 ".Carbon::now()->timezone(config('app.display_timezone', 'Asia/Jakarta'))->translatedFormat('d M Y H:i').' WIB';

        try {
            return $this->dispatch($config, $text);
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Reply to a single chat (used by the inbound command webhook).
     *
     * Unlike dispatch(), this does not touch last_sent_at/last_error — those track
     * outbound alarm delivery health, not command replies. An optional inline
     * keyboard (list of button rows) drives the interactive menu.
     *
     * @param  array<int, array<int, array<string, string>>>|null  $keyboard
     * @return array{ok: bool, error: ?string}
     */
    public function sendTo(string $chatId, string $text, ?array $keyboard = null, ?TelegramBotConfig $config = null): array
    {
        $token = ($config ?? TelegramSetting::instance())->botToken();

        if ($token === '') {
            return ['ok' => false, 'error' => 'Bot token belum dikonfigurasi.'];
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        return $this->apiCall($token, 'sendMessage', $payload);
    }

    /**
     * Edit an existing message in place (used to navigate the interactive menu
     * without spamming new messages). Falls back to a fresh message when the
     * original can no longer be edited (e.g. older than 48h, or unchanged).
     *
     * @param  array<int, array<int, array<string, string>>>|null  $keyboard
     * @return array{ok: bool, error: ?string}
     */
    public function editMessage(string $chatId, int $messageId, string $text, ?array $keyboard = null, ?TelegramBotConfig $config = null): array
    {
        $token = ($config ?? TelegramSetting::instance())->botToken();

        if ($token === '') {
            return ['ok' => false, 'error' => 'Bot token belum dikonfigurasi.'];
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $result = $this->apiCall($token, 'editMessageText', $payload);

        if ($result['ok']) {
            return $result;
        }

        // "message is not modified" means the screen is already showing — treat as success.
        if (str_contains(strtolower((string) $result['error']), 'not modified')) {
            return ['ok' => true, 'error' => null];
        }

        // Anything else (message too old / deleted) — fall back to a new message.
        return $this->sendTo($chatId, $text, $keyboard, $config);
    }

    /**
     * Acknowledge a callback query so Telegram stops showing the button spinner.
     * Best-effort: failures are swallowed (the reply itself already went out).
     */
    public function answerCallback(string $callbackId, ?string $text = null, ?TelegramBotConfig $config = null): void
    {
        $token = ($config ?? TelegramSetting::instance())->botToken();

        if ($token === '') {
            return;
        }

        $payload = ['callback_query_id' => $callbackId];
        if ($text !== null) {
            $payload['text'] = $text;
        }

        try {
            Http::asJson()->timeout(10)->post(self::API_BASE."/bot{$token}/answerCallbackQuery", $payload);
        } catch (Throwable) {
            // The user already has their reply; a failed ack only leaves a brief spinner.
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error: ?string}
     */
    private function apiCall(string $token, string $method, array $payload): array
    {
        try {
            $response = Http::asJson()
                ->timeout(10)
                ->post(self::API_BASE."/bot{$token}/{$method}", $payload);
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            return [
                'ok' => false,
                'error' => $response->json('description') ?: 'HTTP '.$response->status().' dari Telegram.',
            ];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Push a message to every configured chat id and record the outcome.
     *
     * @return array{ok: bool, error: ?string}
     */
    private function dispatch(TelegramBotConfig $config, string $text): array
    {
        $token = $config->botToken();
        $error = null;

        foreach ($config->chatIds() as $chatId) {
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

        $config->recordDelivery($error);

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
