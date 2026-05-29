<?php

namespace App\Services\Telegram;

use App\Models\AlarmEvent;
use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Models\TelegramSetting;
use App\Services\Dashboard\DashboardStatsService;
use App\Support\SmartOltSupport;
use Illuminate\Support\Carbon;

/**
 * Builds replies for inbound Telegram bot commands.
 *
 * All commands are READ-ONLY: they query the same cached/persisted data the web UI
 * reads (snmp_olts.last_test_result, alarm_events, smartolt_onu_registrations) so the
 * bot answers stay consistent with the dashboard. No command writes to an OLT.
 */
class TelegramCommandHandler
{
    /** ONU matches returned for an /onu lookup before we ask the user to narrow it down. */
    private const MAX_ONU_MATCHES = 6;

    /** Active alarms listed by /alarm. */
    private const MAX_ALARMS = 10;

    public function __construct(private readonly DashboardStatsService $stats) {}

    /**
     * Resolve an inbound message to a reply (HTML), or null when it should be ignored.
     */
    public function handle(string $text, string $chatId, TelegramSetting $setting): ?string
    {
        $text = trim($text);

        if ($text === '' || $text[0] !== '/') {
            return null;
        }

        // "/status@MyBot arg" -> command "status", argument "arg".
        $parts = preg_split('/\s+/', $text, 2);
        $command = ltrim(strtolower((string) ($parts[0] ?? '')), '/');
        $command = explode('@', $command)[0];
        $argument = trim((string) ($parts[1] ?? ''));

        // Always-public commands (no allow-list needed).
        switch ($command) {
            case 'start':
            case 'help':
                return $this->help();
            case 'id':
            case 'chatid':
                return $this->chatId($chatId, $setting);
            case 'ping':
                return $this->ping();
        }

        // Everything else is data — restricted to the configured chat-id allow-list.
        if (! $setting->isChatAuthorized($chatId)) {
            return $this->accessDenied($chatId);
        }

        return match ($command) {
            'status' => $this->status(),
            'olt', 'olts' => $argument === '' ? $this->oltList() : $this->oltDetail($argument),
            'alarm', 'alarms' => $this->alarms(),
            'onu', 'cek' => $this->onu($argument),
            'prov', 'provisioning' => $this->provisioning(),
            default => $this->unknown(),
        };
    }

    private function help(): string
    {
        return "🤖 <b>KusumaVision NMS Bot</b>\n\n"
            ."Perintah tersedia:\n"
            ."/status — Ringkasan jaringan\n"
            ."/olt — Daftar OLT &amp; status\n"
            ."/olt <i>&lt;nama|id&gt;</i> — Detail satu OLT\n"
            ."/alarm — Alarm aktif terbaru\n"
            ."/onu <i>&lt;serial|nama&gt;</i> — Cari status ONU\n"
            ."/prov — Antrian provisioning\n"
            ."/id — Tampilkan chat ID Anda\n"
            .'/ping — Cek koneksi bot';
    }

    private function chatId(string $chatId, TelegramSetting $setting): string
    {
        $line = '🆔 Chat ID Anda: <code>'.$this->escape($chatId).'</code>';

        $line .= $setting->isChatAuthorized($chatId)
            ? "\n✅ Sudah terdaftar — Anda bisa memakai semua perintah."
            : "\nMinta admin menambahkan ID ini di Pengaturan → Bot Telegram agar bisa memakai perintah data.";

        return $line;
    }

    private function ping(): string
    {
        return "🏓 Pong! Bot aktif.\n🕒 ".$this->now();
    }

    private function accessDenied(string $chatId): string
    {
        return '⛔ Akses ditolak. Chat ID Anda (<code>'.$this->escape($chatId).'</code>) belum terdaftar.'
            ."\nMinta admin menambahkannya di Pengaturan → Bot Telegram.";
    }

    private function unknown(): string
    {
        return 'Perintah tidak dikenal. Ketik /help untuk daftar perintah.';
    }

    private function status(): string
    {
        $cards = $this->stats->statCards();
        $olt = $cards['olt'];
        $onu = $cards['onu'];
        $alarms = $cards['alarms'];

        return "📊 <b>Status Jaringan</b>\n🕒 ".$this->now()."\n\n"
            ."🖥️ <b>OLT</b>: {$olt['total']} (✅ {$olt['online']} online · ⚠️ {$olt['offline']} offline)\n"
            ."📶 <b>ONU</b>: {$onu['total']} total\n"
            ."   ✅ {$onu['online']} online · ❌ {$onu['offline']} offline · ⚠️ {$onu['warning']} redaman tinggi\n"
            .'   📈 Online: '.$cards['online_share']."%\n\n"
            ."🚨 <b>Alarm aktif</b>: {$alarms['total']}\n"
            ."   🔴 {$alarms['critical']} · 🟠 {$alarms['major']} · 🟡 {$alarms['minor']} · ⚪ {$alarms['warning']}";
    }

    private function oltList(): string
    {
        $olts = $this->stats->oltStatuses();

        if ($olts === []) {
            return 'Belum ada OLT terdaftar.';
        }

        $lines = ['🖥️ <b>Daftar OLT</b> ('.count($olts).')', ''];

        foreach ($olts as $olt) {
            $icon = $olt['reachable'] ? '✅' : '⚠️';
            $lines[] = $icon.' <b>'.$this->escape((string) $olt['name']).'</b>';

            if ($olt['reachable']) {
                $portsTotal = $olt['ports_up'] + $olt['ports_down'];
                $lines[] = "   ONU {$olt['onu_online']}/{$olt['onu_total']} online · Port up {$olt['ports_up']}/{$portsTotal} · poll ".$this->relative($olt['last_polled_at']);
            } else {
                $lines[] = '   Tidak terjangkau · poll '.$this->relative($olt['last_polled_at']);
            }
        }

        return implode("\n", $lines);
    }

    private function oltDetail(string $query): string
    {
        $needle = strtolower($query);

        $olt = SnmpOlt::query()
            ->when(ctype_digit($query), fn ($q) => $q->where('id', (int) $query))
            ->when(! ctype_digit($query), fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"]))
            ->orderBy('name')
            ->first();

        if ($olt === null) {
            return 'OLT "<b>'.$this->escape($query).'</b>" tidak ditemukan.';
        }

        $row = collect($this->stats->oltStatuses())->firstWhere('id', $olt->id) ?? [];
        $result = $olt->last_test_result ?? [];
        $reachable = (bool) ($result['ok'] ?? false);
        $portsUp = $row['ports_up'] ?? 0;
        $portsDown = $row['ports_down'] ?? 0;

        return '🖥️ <b>'.$this->escape((string) $olt->name)."</b>\n"
            .($reachable ? '✅ Terjangkau' : '⚠️ Tidak terjangkau')."\n\n"
            .'IP: <code>'.$this->escape((string) $olt->ip)."</code>\n"
            .'Vendor: '.$this->escape((string) ($olt->vendor ?: '-'))."\n"
            .'ONU: '.($row['onu_online'] ?? 0).'/'.($row['onu_total'] ?? 0)." online\n"
            .'Port: up '.$portsUp.' · down '.$portsDown."\n"
            .'Polling: '.((bool) $olt->polling_enabled ? 'aktif' : 'nonaktif').', terakhir '.$this->relative($olt->last_polled_at?->toIso8601String());
    }

    private function alarms(): string
    {
        $alarms = AlarmEvent::query()
            ->with('olt:id,name')
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->orderByDesc('last_seen_at')
            ->limit(self::MAX_ALARMS)
            ->get();

        if ($alarms->isEmpty()) {
            return '✅ Tidak ada alarm aktif saat ini.';
        }

        $total = AlarmEvent::query()->where('status', AlarmEvent::STATUS_ACTIVE)->count();
        $header = '🚨 <b>Alarm Aktif</b> ('.$total.')';
        if ($total > $alarms->count()) {
            $header .= ' — menampilkan '.$alarms->count().' terbaru';
        }

        $sections = [$header];

        foreach ($alarms as $alarm) {
            $emoji = TelegramNotifier::SEVERITY_EMOJI[$alarm->severity] ?? '⚪';
            $section = $emoji.' <b>'.strtoupper((string) $alarm->severity).'</b> · '.$this->escape((string) $alarm->type);
            $section .= "\n".$this->escape($this->alarmLocation($alarm));
            $section .= "\n".$this->escape((string) $alarm->message);

            if (($customer = $this->alarmCustomer($alarm)) !== null) {
                $section .= "\n👤 ".$this->escape($customer);
            }

            $section .= "\n🕒 ".$this->relative($alarm->last_seen_at?->toIso8601String());
            $sections[] = $section;
        }

        return implode("\n\n", $sections);
    }

    private function onu(string $query): string
    {
        if ($query === '') {
            return 'Format: <code>/onu &lt;serial|nama&gt;</code>'."\nContoh: <code>/onu ZTEGC1234567</code>";
        }

        $matches = $this->searchOnus($query);

        if ($matches === []) {
            return '🔎 ONU "<b>'.$this->escape($query).'</b>" tidak ditemukan di cache OLT mana pun.';
        }

        if (count($matches) === 1) {
            return $this->onuDetail($matches[0]);
        }

        $lines = ['🔎 <b>'.count($matches).' ONU cocok</b> dengan "'.$this->escape($query).'":', ''];
        foreach ($matches as $m) {
            $icon = $m['online'] ? '✅' : '❌';
            $label = $m['serial_number'] ?: ($m['name'] ?: $m['interface']);
            $lines[] = $icon.' <code>'.$this->escape((string) $label).'</code> — '.$this->escape((string) $m['olt_name']).' · '.$this->escape((string) $m['interface']);
        }
        $lines[] = '';
        $lines[] = 'Persempit dengan serial lengkap untuk detail.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $onu
     */
    private function onuDetail(array $onu): string
    {
        $title = $onu['serial_number'] ?: ($onu['name'] ?: ($onu['interface'] ?: 'ONU'));
        $online = (bool) $onu['online'];
        $phase = (string) ($onu['phase_state'] ?? 'Unknown');

        $lines = [
            '🔎 <b>'.$this->escape((string) $title).'</b>',
            '',
            'OLT: '.$this->escape((string) $onu['olt_name']),
            'Interface: '.$this->escape((string) ($onu['interface'] ?: '-')).' (Slot '.$onu['slot'].' / PON '.$onu['port'].')',
            'Status: '.($online ? '✅ Online' : '❌ Offline').' ('.$this->escape($phase).')',
        ];

        if (! $online && filled($onu['last_down_cause'] ?? null)) {
            $lines[] = 'Penyebab down: '.$this->escape((string) $onu['last_down_cause']);
        }

        $rx = $onu['rx_power_label'] ?: ($onu['rx_power_dbm'] !== null ? $onu['rx_power_dbm'].' dBm' : null);
        $lines[] = 'RX Power: '.($rx !== null ? $this->escape((string) $rx) : '-');
        $lines[] = '👤 Pelanggan: '.($onu['customer'] !== null ? $this->escape((string) $onu['customer']) : '-');

        return implode("\n", $lines);
    }

    /**
     * Find ONUs across every OLT's cached port_onus by serial / name / interface.
     * Mirrors DashboardSearchController::searchOnusInCachedResults.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchOnus(string $query): array
    {
        $needle = strtolower($query);
        $matches = [];

        $olts = SnmpOlt::query()->get(['id', 'name', 'last_test_result']);

        foreach ($olts as $olt) {
            foreach (data_get($olt->last_test_result ?? [], 'port_onus', []) as $port) {
                foreach (($port['onus'] ?? []) as $onu) {
                    $serial = strtolower((string) ($onu['serial_number'] ?? ''));
                    $name = strtolower((string) ($onu['name'] ?? ''));
                    $interface = strtolower((string) ($onu['interface'] ?? ''));

                    if ($serial === '' && $name === '' && $interface === '') {
                        continue;
                    }
                    if (! str_contains($serial, $needle) && ! str_contains($name, $needle) && ! str_contains($interface, $needle)) {
                        continue;
                    }

                    $matches[] = [
                        'olt_id' => $olt->id,
                        'olt_name' => $olt->name,
                        'slot' => (int) ($onu['slot'] ?? 0),
                        'port' => (int) ($onu['port'] ?? 0),
                        'interface' => $onu['interface'] ?? null,
                        'serial_number' => $onu['serial_number'] ?? null,
                        'name' => $onu['name'] ?? null,
                        'online' => (bool) ($onu['online'] ?? false),
                        'phase_state' => $onu['phase_state'] ?? 'Unknown',
                        'last_down_cause' => $onu['last_down_cause'] ?? null,
                        'rx_power_dbm' => $onu['rx_power_dbm'] ?? null,
                        'rx_power_label' => $onu['rx_power_label'] ?? null,
                        'customer' => $this->resolveCustomer($olt, $onu),
                    ];

                    if (count($matches) >= self::MAX_ONU_MATCHES) {
                        return $matches;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $onu
     */
    private function resolveCustomer(SnmpOlt $olt, array $onu): ?string
    {
        if (($name = SmartOltSupport::customerNameFromOnu($onu)) !== null) {
            return $name;
        }

        $serial = (string) ($onu['serial_number'] ?? '');
        if ($serial === '') {
            return null;
        }

        $registration = SmartOltOnuRegistration::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('serial_number', $serial)
            ->orderByDesc('created_at')
            ->first(['customer_name', 'serial_number']);

        return $registration
            ? SmartOltSupport::cleanCustomerName($registration->customer_name, $serial)
            : null;
    }

    private function provisioning(): string
    {
        $summary = collect($this->stats->provisioningSummary())->keyBy('key');

        return "📦 <b>Provisioning</b>\n\n"
            .'⏳ Menunggu: '.($summary['pending']['count'] ?? 0)."\n"
            .'⚙️ Diproses: '.($summary['processing']['count'] ?? 0)."\n"
            .'✅ Berhasil: '.($summary['success']['count'] ?? 0)."\n"
            .'❌ Gagal: '.($summary['failed']['count'] ?? 0);
    }

    private function alarmLocation(AlarmEvent $alarm): string
    {
        $parts = [];
        if ($alarm->olt) {
            $parts[] = (string) $alarm->olt->name;
        }
        if ($alarm->slot !== null) {
            $parts[] = 'Slot '.$alarm->slot;
        }
        if ($alarm->port !== null) {
            $parts[] = 'PON '.$alarm->port;
        }

        return $parts === [] ? '-' : implode(' / ', $parts);
    }

    private function alarmCustomer(AlarmEvent $alarm): ?string
    {
        return SmartOltSupport::cleanCustomerName(
            data_get($alarm->meta, 'customer_name'),
            (string) ($alarm->serial_number ?? ''),
        );
    }

    private function now(): string
    {
        return Carbon::now()
            ->timezone(config('app.display_timezone', 'Asia/Jakarta'))
            ->translatedFormat('d M Y H:i').' WIB';
    }

    private function relative(?string $iso): string
    {
        if (blank($iso)) {
            return 'belum pernah';
        }

        return Carbon::parse($iso)->locale('id')->diffForHumans();
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
