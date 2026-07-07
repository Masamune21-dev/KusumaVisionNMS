<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramBotConfig;
use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Services\CData\CDataCliWriteService;
use App\Services\CData\CDataOltScanner;
use App\Services\Dashboard\DashboardStatsService;
use App\Services\Hioso\HiosoCliWriteService;
use App\Services\ZteRemoteOnuService;
use App\Services\ZteUncfgOnuService;
use App\Support\SmartOltSupport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds replies for inbound Telegram bot messages (slash commands) and callback
 * queries (inline-button presses).
 *
 * Actions are READ-ONLY (query the same cached/persisted data the web UI reads —
 * snmp_olts.last_test_result, alarm_events) so the bot stays consistent with the dashboard.
 * Pengecualian: /refresh men-scan ulang OLT C-Data (via {@see CDataOltScanner}) untuk
 * menyegarkan cache — tetap tidak mengubah konfigurasi OLT; /uncfg membaca live dari CLI OLT
 * ZTE (via {@see ZteUncfgOnuService}, read-only); dan tombol "Reboot ONU" di layar detail ONU
 * (dua langkah: konfirmasi dulu, gated `supports_reboot`, delegasi ke service write
 * per-family seperti pin peta di OnuMapController).
 *
 * Every reply is a {@see TelegramReply} (text + optional inline keyboard), so the same
 * screen renderers feed either a fresh sendMessage (command) or an in-place
 * editMessageText (button navigation).
 */
class TelegramCommandHandler
{
    /** Max ONU matches collected for a search (then paginated 8 per page). */
    private const SEARCH_LIMIT = 60;

    /** How long a search query stays resolvable behind its callback token. */
    private const SEARCH_TTL = 3600;

    public function __construct(
        private readonly DashboardStatsService $stats,
        private readonly TelegramOnuQueryService $onus,
        private readonly CDataOltScanner $scanner,
        private readonly ZteRemoteOnuService $zteWrite,
        private readonly CDataCliWriteService $cdataWrite,
        private readonly HiosoCliWriteService $hiosoWrite,
        private readonly ZteUncfgOnuService $uncfg,
    ) {}

    /**
     * Resolve an inbound text message to a reply, or null when it should be ignored.
     */
    public function handle(string $text, string $chatId, TelegramBotConfig $setting): ?TelegramReply
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
            case 'menu':
                return $this->mainMenu();
            case 'help':
                return $this->help();
            case 'id':
            case 'chatid':
                return TelegramReply::make($this->chatId($chatId, $setting));
            case 'ping':
                return TelegramReply::make($this->ping());
        }

        // Everything else is data — restricted to the configured chat-id allow-list.
        if (! $setting->isChatAuthorized($chatId)) {
            return TelegramReply::make($this->accessDenied($chatId));
        }

        return match ($command) {
            'status' => $this->statusScreen(),
            'olt', 'olts' => $argument === '' ? $this->oltListScreen() : $this->oltDetailByQuery($argument),
            'alarm', 'alarms' => $this->alarmsScreen(0),
            'onu', 'cek', 'search', 'cari' => $this->onuSearch($argument),
            'los' => $this->losScreen($this->resolveScope($argument), 0),
            'redaman', 'rx' => $this->rxScreen($this->resolveScope($argument), 0),
            'prov', 'provisioning' => $this->provisioningScreen(),
            'uncfg', 'unconfigured' => $this->uncfgScreen($this->resolveScope($argument)),
            'refresh', 'segarkan' => $this->refreshCdataScreen($argument),
            default => $this->unknown(),
        };
    }

    /**
     * Resolve a callback_data string (button press) to a reply. The caller must already
     * have authorized the chat; null = nothing to render (e.g. the page-indicator button).
     */
    public function handleCallback(string $data, string $chatId, TelegramBotConfig $setting): ?TelegramReply
    {
        if (! $setting->isChatAuthorized($chatId)) {
            return TelegramReply::make($this->accessDenied($chatId));
        }

        // Search screens carry a string token (not int), so resolve them before the
        // int-casting parser the numeric screens rely on.
        $raw = explode(':', trim($data));
        switch ($raw[0] ?? '') {
            case 'srh':
                return $this->searchHelpScreen();
            case 'sr':
                return $this->searchResultsScreen($raw[1] ?? '', (int) ($raw[2] ?? 0));
            case 'su':
                return $this->searchResultDetail($raw[1] ?? '', (int) ($raw[2] ?? 0), (int) ($raw[3] ?? 0), (int) ($raw[4] ?? 0), (int) ($raw[5] ?? 0), (int) ($raw[6] ?? 0));
        }

        [$screen, $a] = TelegramKeyboard::parse($data);

        return match ($screen) {
            'm' => $this->mainMenu(),
            'st' => $this->statusScreen(),
            'ol' => $this->oltListScreen(),
            'o' => $this->oltDetailScreen($a[0] ?? 0),
            'pl' => $this->portListScreen($a[0] ?? 0, $a[1] ?? 0),
            'on' => $this->portOnuScreen($a[0] ?? 0, $a[1] ?? 0, $a[2] ?? 0, $a[3] ?? 0, $a[4] ?? 0),
            'u' => $this->onuDetailScreen($a[0] ?? 0, $a[1] ?? 0, $a[2] ?? 0, $a[3] ?? 0, $a[4] ?? 0, $a[5] ?? 0, $a[6] ?? 0),
            'rb' => $this->onuRebootConfirmScreen($a[0] ?? 0, $a[1] ?? 0, $a[2] ?? 0, $a[3] ?? 0, $a[4] ?? 0, $a[5] ?? 0, $a[6] ?? 0),
            'rbx' => $this->onuRebootExecute($a[0] ?? 0, $a[1] ?? 0, $a[2] ?? 0, $a[3] ?? 0, $a[4] ?? 0, $a[5] ?? 0, $a[6] ?? 0),
            'los' => $this->losScreen($a[0] ?? 0, $a[1] ?? 0),
            'rx' => $this->rxScreen($a[0] ?? 0, $a[1] ?? 0),
            'al' => $this->alarmsScreen($a[0] ?? 0),
            'uc' => $this->uncfgScreen($a[0] ?? 0),
            'noop' => null,
            default => $this->mainMenu(),
        };
    }

    // --- public / unauthenticated replies ---

    private function help(): TelegramReply
    {
        $text = "🤖 <b>KusumaVision NMS — Bot Telegram</b>\n\n"
            ."Pantau OLT &amp; ONU FTTH langsung dari Telegram: status jaringan, ONU LOS, redaman, cari pelanggan, alarm, sampai refresh OLT C-Data. Cara termudah: tekan tombol di /menu.\n\n"

            ."📊 <b>Pantau jaringan</b>\n"
            ."/menu — Menu interaktif (tombol navigasi)\n"
            ."/status — Ringkasan: OLT &amp; ONU online/offline, redaman, alarm\n"
            ."/alarm — Alarm aktif terbaru (bisa di-scroll)\n\n"

            ."🖥️ <b>OLT &amp; port</b>\n"
            ."/olt — Daftar semua OLT\n"
            ."/olt <i>&lt;nama|id&gt;</i> — Detail 1 OLT + ringkasan port PON\n"
            ."   contoh: <code>/olt pati</code>\n\n"

            ."📶 <b>ONU &amp; pelanggan</b>\n"
            ."/search <i>&lt;nama|serial&gt;</i> — Cari ONU di semua OLT (alias: /onu, /cari, /cek)\n"
            ."   contoh: <code>/search budi</code> · <code>/onu ZTEGC12</code>\n"
            ."/los <i>[nama OLT]</i> — ONU yang LOS/putus (kosong = semua OLT)\n"
            ."/redaman <i>[nama OLT]</i> — ONU redaman tinggi (alias: /rx)\n"
            ."   contoh: <code>/redaman pati</code>\n"
            ."/uncfg <i>[nama OLT]</i> — ONU ZTE belum dikonfigurasi, live dari CLI OLT (bukan cache)\n"
            ."   contoh: <code>/uncfg</code> · <code>/uncfg pati</code>\n\n"

            ."🔄 <b>Aksi</b>\n"
            ."/refresh <i>[nama OLT]</i> — Scan ulang OLT C-Data agar menu/port tampil terbaru (alias: /segarkan). Kosong = semua OLT C-Data.\n"
            ."   contoh: <code>/refresh</code> · <code>/refresh fd1608</code>\n"
            ."Reboot ONU — buka detail ONU (dari menu, /search, /los, /redaman) lalu tekan tombol 🔄 <b>Reboot ONU</b>; ada konfirmasi sebelum dieksekusi.\n\n"

            ."📦 <b>Lainnya</b>\n"
            ."/prov — Ringkasan antrian provisioning\n"
            ."/id — Tampilkan chat ID Anda (untuk didaftarkan admin)\n"
            ."/ping — Cek bot aktif\n"
            ."/help — Bantuan ini\n\n"

            .'ℹ️ Perintah data hanya untuk chat terdaftar (admin: Pengaturan → Bot Telegram). Angka diambil dari cache polling — pakai /refresh untuk OLT C-Data agar terkini.';

        return TelegramReply::make($text, [[TelegramKeyboard::button('🏠 Buka Menu', TelegramKeyboard::menu())]]);
    }

    private function chatId(string $chatId, TelegramBotConfig $setting): string
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

    private function unknown(): TelegramReply
    {
        return TelegramReply::make(
            'Perintah tidak dikenal. Ketik /help untuk daftar perintah.',
            [[TelegramKeyboard::button('🏠 Menu', TelegramKeyboard::menu())]],
        );
    }

    // --- screens ---

    private function mainMenu(): TelegramReply
    {
        $cards = $this->stats->statCards();
        $olt = $cards['olt'];
        $onu = $cards['onu'];

        $text = "🤖 <b>KusumaVision NMS</b>\n🕒 ".$this->now()."\n\n"
            ."🖥️ OLT: {$olt['total']} (✅ {$olt['online']} · ⚠️ {$olt['offline']})\n"
            ."📶 ONU: {$onu['total']} — ✅ {$onu['online']} online · ❌ {$onu['offline']} offline\n"
            ."📉 Redaman bermasalah: {$onu['warning']}\n\n"
            .'Pilih menu di bawah 👇';

        $keyboard = [
            [TelegramKeyboard::button('📊 Status Jaringan', TelegramKeyboard::status())],
            [TelegramKeyboard::button('🖥️ Daftar OLT', TelegramKeyboard::oltList())],
            [
                TelegramKeyboard::button('🔴 ONU LOS', TelegramKeyboard::losList(0, 0)),
                TelegramKeyboard::button('📉 Redaman Tinggi', TelegramKeyboard::rxList(0, 0)),
            ],
            [
                TelegramKeyboard::button('🔎 Cari ONU', TelegramKeyboard::searchHelp()),
                TelegramKeyboard::button('🚨 Alarm Aktif', TelegramKeyboard::alarms(0)),
            ],
        ];

        return TelegramReply::make($text, $keyboard);
    }

    private function statusScreen(): TelegramReply
    {
        $cards = $this->stats->statCards();
        $olt = $cards['olt'];
        $onu = $cards['onu'];
        $alarms = $cards['alarms'];

        $text = "📊 <b>Status Jaringan</b>\n🕒 ".$this->now()."\n\n"
            ."🖥️ <b>OLT</b>: {$olt['total']} (✅ {$olt['online']} online · ⚠️ {$olt['offline']} offline)\n"
            ."📶 <b>ONU</b>: {$onu['total']} total\n"
            ."   ✅ {$onu['online']} online · ❌ {$onu['offline']} offline · ⚠️ {$onu['warning']} redaman tinggi\n"
            .'   📈 Online: '.$cards['online_share']."%\n\n"
            ."🚨 <b>Alarm aktif</b>: {$alarms['total']}\n"
            ."   🔴 {$alarms['critical']} · 🟠 {$alarms['major']} · 🟡 {$alarms['minor']} · ⚪ {$alarms['warning']}";

        $keyboard = [
            [
                TelegramKeyboard::button('🔴 ONU LOS', TelegramKeyboard::losList(0, 0)),
                TelegramKeyboard::button('📉 Redaman', TelegramKeyboard::rxList(0, 0)),
            ],
            [TelegramKeyboard::button('🖥️ Daftar OLT', TelegramKeyboard::oltList())],
            TelegramKeyboard::backRow(null),
        ];

        return TelegramReply::make($text, $keyboard);
    }

    private function oltListScreen(): TelegramReply
    {
        $olts = $this->onus->olts();

        if ($olts === []) {
            return TelegramReply::make('Belum ada OLT terdaftar.', [TelegramKeyboard::backRow(null)]);
        }

        $rows = [];
        foreach ($olts as $olt) {
            $icon = $olt['reachable'] ? '✅' : '⚠️';
            $label = $icon.' '.$this->truncate($olt['name'], 22).' ('.$olt['online'].'/'.$olt['total'].')';
            $rows[] = [TelegramKeyboard::button($label, TelegramKeyboard::oltDetail($olt['id']))];
        }
        $rows[] = TelegramKeyboard::backRow(null);

        return TelegramReply::make('🖥️ <b>Daftar OLT</b> ('.count($olts).')'."\nPilih OLT untuk lihat detail & port:", $rows);
    }

    private function oltDetailByQuery(string $query): TelegramReply
    {
        $olt = $this->resolveOlt($query);

        if ($olt === null) {
            return TelegramReply::make(
                'OLT "<b>'.$this->escape($query).'</b>" tidak ditemukan.',
                [TelegramKeyboard::backRow(TelegramKeyboard::oltList())],
            );
        }

        return $this->oltDetailScreen($olt->id);
    }

    private function oltDetailScreen(int $oltId): TelegramReply
    {
        $summary = $this->onus->oltSummary($oltId);
        $olt = $this->onus->findOlt($oltId);

        if ($summary === null || $olt === null) {
            return TelegramReply::make('OLT tidak ditemukan.', [TelegramKeyboard::backRow(TelegramKeyboard::oltList())]);
        }

        $text = '🖥️ <b>'.$this->escape($summary['name'])."</b>\n"
            .($summary['reachable'] ? '✅ Terjangkau' : '⚠️ Tidak terjangkau')."\n\n"
            .'IP: <code>'.$this->escape((string) $olt->ip)."</code>\n"
            .'📶 ONU: '.$summary['online'].'/'.$summary['total'].' online (❌ '.$summary['offline'].")\n"
            .'🔴 LOS: '.$summary['los'].' · 📉 Redaman: '.$summary['rx_alert']."\n"
            .'Polling: '.((bool) $olt->polling_enabled ? 'aktif' : 'nonaktif').', terakhir '.$this->relative($olt->last_polled_at?->toIso8601String());

        $keyboard = [
            [TelegramKeyboard::button('🔌 Pilih Port PON', TelegramKeyboard::portList($oltId, 0))],
            [
                TelegramKeyboard::button('🔴 LOS ('.$summary['los'].')', TelegramKeyboard::losList($oltId, 0)),
                TelegramKeyboard::button('📉 Redaman ('.$summary['rx_alert'].')', TelegramKeyboard::rxList($oltId, 0)),
            ],
            TelegramKeyboard::backRow(TelegramKeyboard::oltList()),
        ];

        return TelegramReply::make($text, $keyboard);
    }

    private function portListScreen(int $oltId, int $page): TelegramReply
    {
        $summary = $this->onus->oltSummary($oltId);
        if ($summary === null) {
            return TelegramReply::make('OLT tidak ditemukan.', [TelegramKeyboard::backRow(TelegramKeyboard::oltList())]);
        }

        $ports = $this->onus->ports($oltId);
        if ($ports === []) {
            return TelegramReply::make(
                '🔌 <b>'.$this->escape($summary['name'])."</b>\nBelum ada port/ONU di cache. Jalankan polling dulu.",
                [TelegramKeyboard::backRow(TelegramKeyboard::oltDetail($oltId))],
            );
        }

        // 12 ports per page (6 rows × 2 buttons).
        $perPage = 12;
        [$slice, $page, $totalPages] = $this->paginate($ports, $page, $perPage);

        $rows = [];
        foreach (array_chunk($slice, 2) as $pair) {
            $row = [];
            foreach ($pair as $p) {
                $flag = $p['offline'] > 0 ? ' 🔴'.$p['offline'] : '';
                $label = $p['label'].' ('.$p['online'].'/'.$p['total'].')'.$flag;
                $row[] = TelegramKeyboard::button($label, TelegramKeyboard::portOnus($oltId, $p['slot'], $p['port'], TelegramKeyboard::FILTER_ALL, 0));
            }
            $rows[] = $row;
        }

        if (($pager = TelegramKeyboard::pager($page, $totalPages, fn ($pg) => TelegramKeyboard::portList($oltId, $pg))) !== []) {
            $rows[] = $pager;
        }
        $rows[] = TelegramKeyboard::backRow(TelegramKeyboard::oltDetail($oltId));

        $text = '🔌 <b>'.$this->escape($summary['name'])."</b> — Pilih Port PON\n"
            .count($ports).' port · pilih untuk lihat daftar ONU:';

        return TelegramReply::make($text, $rows);
    }

    private function portOnuScreen(int $oltId, int $slot, int $port, int $filter, int $page): TelegramReply
    {
        $summary = $this->onus->oltSummary($oltId);
        $all = $this->onus->portOnus($oltId, $slot, $port);

        $filtered = match ($filter) {
            TelegramKeyboard::FILTER_LOS => array_values(array_filter($all, fn ($o) => ! $o['online'])),
            TelegramKeyboard::FILTER_RX => array_values(array_filter($all, fn ($o) => TelegramOnuQueryService::rxIsAlert($o['rx_power_dbm']))),
            default => $all,
        };

        $filterLabel = match ($filter) {
            TelegramKeyboard::FILTER_LOS => '🔴 LOS/Offline',
            TelegramKeyboard::FILTER_RX => '📉 Redaman tinggi',
            default => 'Semua',
        };

        $oltName = $summary['name'] ?? ('OLT '.$oltId);
        $header = '🔌 <b>'.$this->escape($oltName)." · PON {$slot}/{$port}</b>\n"
            ."Filter: {$filterLabel} · ".count($filtered).' dari '.count($all).' ONU';

        if ($filtered === []) {
            $rows = [
                $this->filterRow($oltId, $slot, $port, $filter),
                TelegramKeyboard::backRow(TelegramKeyboard::portList($oltId, 0)),
            ];

            return TelegramReply::make($header."\n\n<i>Tidak ada ONU pada filter ini.</i>", $rows);
        }

        [$slice, $page, $totalPages] = $this->paginate($filtered, $page, TelegramKeyboard::PAGE_SIZE);

        $rows = [];
        foreach ($slice as $onu) {
            $rows[] = [TelegramKeyboard::button(
                TelegramOnuQueryService::statusIcon($onu).' '.$this->onuButtonLabel($onu),
                TelegramKeyboard::onuDetail($oltId, $slot, $port, $onu['onu_id'], $filter, $oltId, $page),
            )];
        }

        $rows[] = $this->filterRow($oltId, $slot, $port, $filter);
        if (($pager = TelegramKeyboard::pager($page, $totalPages, fn ($pg) => TelegramKeyboard::portOnus($oltId, $slot, $port, $filter, $pg))) !== []) {
            $rows[] = $pager;
        }
        $rows[] = TelegramKeyboard::backRow(TelegramKeyboard::portList($oltId, 0));

        return TelegramReply::make($header, $rows);
    }

    private function onuDetailScreen(int $oltId, int $slot, int $port, int $onuId, int $src, int $scope, int $page): TelegramReply
    {
        $onu = $this->onus->onu($oltId, $slot, $port, $onuId);
        $back = $this->onuBack($oltId, $slot, $port, $src, $scope, $page);

        if ($onu === null) {
            return TelegramReply::make('ONU tidak ditemukan di cache.', [TelegramKeyboard::backRow($back)]);
        }

        $rows = $this->onuActionRows($oltId, $slot, $port, $onuId, $src, $scope, $page);
        $rows[] = TelegramKeyboard::backRow($back);

        return TelegramReply::make($this->onuDetailText($onu, $oltId), $rows);
    }

    /**
     * Reboot confirmation screen ("rb:" callback) — the button on the ONU detail never
     * reboots directly; it lands here first so a stray tap cannot restart a customer.
     */
    private function onuRebootConfirmScreen(int $oltId, int $slot, int $port, int $onuId, int $src, int $scope, int $page): TelegramReply
    {
        $onu = $this->onus->onu($oltId, $slot, $port, $onuId);
        $detail = TelegramKeyboard::onuDetail($oltId, $slot, $port, $onuId, $src, $scope, $page);

        if ($onu === null) {
            return TelegramReply::make('ONU tidak ditemukan di cache.', [TelegramKeyboard::backRow(null)]);
        }

        if (! $this->supportsReboot($oltId)) {
            return TelegramReply::make('⛔ Reboot ONU tidak didukung untuk OLT ini.', [TelegramKeyboard::backRow($detail)]);
        }

        $title = $onu['customer'] ?: ($onu['serial_number'] ?: ($onu['interface'] ?: ('ONU '.$onuId)));

        $text = "🔄 <b>Reboot ONU?</b>\n\n"
            .TelegramOnuQueryService::statusIcon($onu).' <b>'.$this->escape((string) $title)."</b>\n"
            .'OLT: '.$this->escape((string) $onu['olt_name'])."\n"
            .'Interface: '.$this->escape((string) ($onu['interface'] ?: "Slot {$slot} / PON {$port} / ONU {$onuId}"))."\n\n"
            .'⚠️ ONU akan restart dan pelanggan terputus ±30–60 detik.';

        $keyboard = [
            [TelegramKeyboard::button('✅ Ya, Reboot Sekarang', TelegramKeyboard::onuRebootExecute($oltId, $slot, $port, $onuId, $src, $scope, $page))],
            [TelegramKeyboard::button('❌ Batal', $detail)],
        ];

        return TelegramReply::make($text, $keyboard);
    }

    /**
     * Execute the reboot ("rbx:" callback, only reachable via the confirmation screen).
     * Mirrors OnuMapController::rebootPin(): capability-gated, branch per driver family.
     */
    private function onuRebootExecute(int $oltId, int $slot, int $port, int $onuId, int $src, int $scope, int $page): TelegramReply
    {
        $olt = $this->onus->findOlt($oltId);
        $detail = TelegramKeyboard::onuDetail($oltId, $slot, $port, $onuId, $src, $scope, $page);
        $back = [
            [TelegramKeyboard::button('📶 Detail ONU', $detail)],
            TelegramKeyboard::backRow($this->onuBack($oltId, $slot, $port, $src, $scope, $page)),
        ];

        if ($olt === null) {
            return TelegramReply::make('OLT tidak ditemukan.', [TelegramKeyboard::backRow(null)]);
        }

        if (! $this->supportsReboot($oltId)) {
            return TelegramReply::make('⛔ Reboot ONU tidak didukung untuk OLT ini.', $back);
        }

        $driver = $this->oltDriver($olt);
        $label = 'ONU '.$slot.'/'.$port.':'.$onuId.' — '.$olt->name;

        try {
            if (SmartOltSupport::isHioso($driver)) {
                $result = $this->hiosoWrite->reboot($olt, $port, $onuId);
            } elseif (SmartOltSupport::isCData($driver)) {
                $iface = $driver === SmartOltSupport::DRIVER_CDATA_EPON ? 'epon' : 'gpon';
                $result = $this->cdataWrite->reboot($olt, $iface, $slot, $port, $onuId);
            } else {
                $result = $this->zteWrite->reboot($olt, $slot, $port, $onuId);
            }

            $ok = (bool) ($result['ok'] ?? false);
            $text = $ok
                ? '✅ <b>Perintah reboot terkirim.</b>'."\n".$this->escape($label)."\nONU restart ±30–60 detik.\n🕒 ".$this->now()
                : '⚠️ <b>Reboot selesai dengan indikasi error.</b>'."\n".$this->escape($label)."\n".$this->escape($this->truncate((string) ($result['error'] ?? ''), 200));
        } catch (Throwable $e) {
            $text = '❌ <b>Reboot ONU gagal.</b>'."\n".$this->escape($label)."\n".$this->escape($this->truncate($e->getMessage(), 200));
        }

        return TelegramReply::make($text, $back);
    }

    private function losScreen(int $scope, int $page): TelegramReply
    {
        $onus = $this->onus->losOnus($scope);
        $scopeLabel = $this->scopeLabel($scope);
        $back = $scope > 0 ? TelegramKeyboard::oltDetail($scope) : null;

        if ($onus === []) {
            return TelegramReply::make(
                "🔴 <b>ONU LOS — {$scopeLabel}</b>\n\n✅ Tidak ada ONU yang putus saat ini.",
                [TelegramKeyboard::backRow($back)],
            );
        }

        [$slice, $page, $totalPages] = $this->paginate($onus, $page, TelegramKeyboard::PAGE_SIZE);

        $rows = [];
        foreach ($slice as $onu) {
            $cause = $onu['los_cause'] ? ((string) ($onu['last_down_cause'] ?: $onu['phase_state'])) : 'Offline';
            $label = TelegramOnuQueryService::statusIcon($onu).' '.$this->onuButtonLabel($onu, $scope === 0).' · '.$cause;
            $rows[] = [TelegramKeyboard::button(
                $this->truncate($label, 48),
                TelegramKeyboard::onuDetail($onu['olt_id'], $onu['slot'], $onu['port'], $onu['onu_id'], TelegramKeyboard::SRC_LOS_LIST, $scope, $page),
            )];
        }

        if (($pager = TelegramKeyboard::pager($page, $totalPages, fn ($pg) => TelegramKeyboard::losList($scope, $pg))) !== []) {
            $rows[] = $pager;
        }
        $rows[] = TelegramKeyboard::backRow($back);

        $text = "🔴 <b>ONU LOS — {$scopeLabel}</b> (".count($onus).")\n🔴 LOS/dying-gasp · ⚫ nonaktif/lainnya";

        return TelegramReply::make($text, $rows);
    }

    private function rxScreen(int $scope, int $page): TelegramReply
    {
        $onus = $this->onus->rxOnus($scope);
        $scopeLabel = $this->scopeLabel($scope);
        $back = $scope > 0 ? TelegramKeyboard::oltDetail($scope) : null;

        if ($onus === []) {
            return TelegramReply::make(
                "📉 <b>Redaman Tinggi — {$scopeLabel}</b>\n\n✅ Semua ONU dalam batas RX aman.",
                [TelegramKeyboard::backRow($back)],
            );
        }

        [$slice, $page, $totalPages] = $this->paginate($onus, $page, TelegramKeyboard::PAGE_SIZE);

        $rows = [];
        foreach ($slice as $onu) {
            $rx = round((float) $onu['rx_power_dbm'], 1).' dBm';
            $label = TelegramOnuQueryService::statusIcon($onu).' '.$this->onuButtonLabel($onu, $scope === 0).' · '.$rx;
            $rows[] = [TelegramKeyboard::button(
                $this->truncate($label, 48),
                TelegramKeyboard::onuDetail($onu['olt_id'], $onu['slot'], $onu['port'], $onu['onu_id'], TelegramKeyboard::SRC_RX_LIST, $scope, $page),
            )];
        }

        if (($pager = TelegramKeyboard::pager($page, $totalPages, fn ($pg) => TelegramKeyboard::rxList($scope, $pg))) !== []) {
            $rows[] = $pager;
        }
        $rows[] = TelegramKeyboard::backRow($back);

        $text = "📉 <b>Redaman Tinggi — {$scopeLabel}</b> (".count($onus).")\n"
            .'Ambang: 🟡 ≤ '.(int) TelegramOnuQueryService::RX_WARN_DBM.' · 🔴 ≤ '.(int) TelegramOnuQueryService::RX_CRIT_DBM.' dBm · urut terparah';

        return TelegramReply::make($text, $rows);
    }

    private function alarmsScreen(int $page): TelegramReply
    {
        $total = AlarmEvent::query()->where('status', AlarmEvent::STATUS_ACTIVE)->count();

        if ($total === 0) {
            return TelegramReply::make('✅ Tidak ada alarm aktif saat ini.', [TelegramKeyboard::backRow(null)]);
        }

        $totalPages = max(1, (int) ceil($total / TelegramKeyboard::PAGE_SIZE));
        $page = max(0, min($page, $totalPages - 1));

        $alarms = AlarmEvent::query()
            ->with('olt:id,name')
            ->where('status', AlarmEvent::STATUS_ACTIVE)
            ->orderByDesc('last_seen_at')
            ->offset($page * TelegramKeyboard::PAGE_SIZE)
            ->limit(TelegramKeyboard::PAGE_SIZE)
            ->get();

        $sections = ['🚨 <b>Alarm Aktif</b> ('.$total.')'];

        foreach ($alarms as $alarm) {
            $emoji = TelegramNotifier::SEVERITY_EMOJI[$alarm->severity] ?? '⚪';
            $section = $emoji.' <b>'.strtoupper((string) $alarm->severity).'</b> · '.$this->escape((string) $alarm->type);
            $section .= "\n".$this->escape($this->alarmLocation($alarm));
            $section .= "\n".$this->escape((string) $alarm->message);
            $section .= "\n🕒 ".$this->relative($alarm->last_seen_at?->toIso8601String());
            $sections[] = $section;
        }

        $rows = [];
        if (($pager = TelegramKeyboard::pager($page, $totalPages, fn ($pg) => TelegramKeyboard::alarms($pg))) !== []) {
            $rows[] = $pager;
        }
        $rows[] = TelegramKeyboard::backRow(null);

        return TelegramReply::make(implode("\n\n", $sections), $rows);
    }

    private function provisioningScreen(): TelegramReply
    {
        $summary = collect($this->stats->provisioningSummary())->keyBy('key');

        $text = "📦 <b>Provisioning</b>\n\n"
            .'⏳ Menunggu: '.($summary['pending']['count'] ?? 0)."\n"
            .'⚙️ Diproses: '.($summary['processing']['count'] ?? 0)."\n"
            .'✅ Berhasil: '.($summary['success']['count'] ?? 0)."\n"
            .'❌ Gagal: '.($summary['failed']['count'] ?? 0);

        return TelegramReply::make($text, [TelegramKeyboard::backRow(null)]);
    }

    /**
     * Scan ulang OLT C-Data lalu laporkan hasilnya supaya menu & daftar port menampilkan data terbaru.
     * `/refresh` (atau `/refresh oltcdata`) menyegarkan semua OLT C-Data; `/refresh <nama|id>`
     * menyegarkan satu OLT C-Data. Sinkron — EPON via SNMP cepat, GPON V3 via CLI ~10 detik/OLT.
     */
    private function refreshCdataScreen(string $argument): TelegramReply
    {
        $argument = trim($argument);
        $generic = $argument === '' || in_array(strtolower($argument), ['oltcdata', 'olt-cdata', 'cdata', 'c-data', 'all', 'semua'], true);

        $olts = SnmpOlt::query()->orderBy('name')->get()
            ->filter(fn (SnmpOlt $olt) => SmartOltSupport::isNonZte(SmartOltSupport::driverKey($olt)));

        if (! $generic) {
            $needle = strtolower($argument);
            $olts = $olts->filter(fn (SnmpOlt $olt) => ctype_digit($argument)
                ? $olt->id === (int) $argument
                : str_contains(strtolower((string) $olt->name), $needle));
        }

        $olts = $olts->values();

        if ($olts->isEmpty()) {
            return TelegramReply::make(
                $generic
                    ? 'Belum ada OLT C-Data terdaftar untuk di-refresh.'
                    : 'OLT C-Data "<b>'.$this->escape($argument).'</b>" tidak ditemukan.',
                [TelegramKeyboard::backRow(null)],
            );
        }

        $lines = ['🔄 <b>Refresh OLT C-Data</b>', ''];
        foreach ($olts as $olt) {
            try {
                $count = $this->scanner->scan($olt);
                $lines[] = '✅ '.$this->escape((string) $olt->name).' — '.$count.' ONU';
            } catch (Throwable $e) {
                $lines[] = '❌ '.$this->escape((string) $olt->name).' — gagal: '.$this->escape($this->truncate($e->getMessage(), 80));
            }
        }
        $lines[] = "\n🕒 ".$this->now();

        $keyboard = [
            [TelegramKeyboard::button('🖥️ Daftar OLT', TelegramKeyboard::oltList())],
            TelegramKeyboard::backRow(null),
        ];

        return TelegramReply::make(implode("\n", $lines), $keyboard);
    }

    /**
     * ONU ZTE yang belum dikonfigurasi (auto-discovery), diambil LANGSUNG dari CLI OLT
     * (`show gpon onu uncfg` via {@see ZteUncfgOnuService}) — sengaja bukan dari cache
     * polling supaya ONU yang baru dicolok teknisi langsung terlihat. Sinkron: telnet
     * beberapa detik per OLT (seperti /refresh). Scope 0 = semua OLT ZTE, selain itu 1 OLT.
     */
    private function uncfgScreen(int $scope): TelegramReply
    {
        $olts = SnmpOlt::query()->orderBy('name')->get()
            ->filter(fn (SnmpOlt $olt) => $this->oltDriver($olt) === SmartOltSupport::DRIVER_ZTE);

        if ($scope > 0) {
            $olts = $olts->where('id', $scope);
        }

        $olts = $olts->values();

        if ($olts->isEmpty()) {
            return TelegramReply::make(
                $scope > 0 ? 'OLT tersebut bukan OLT ZTE (discovery uncfg hanya untuk ZTE).' : 'Belum ada OLT ZTE terdaftar.',
                [TelegramKeyboard::backRow(null)],
            );
        }

        $sections = ["🔍 <b>ONU Uncfg — Belum Dikonfigurasi</b>\n📡 Live dari CLI OLT (bukan cache)"];
        $maxPerOlt = 15;

        foreach ($olts as $olt) {
            try {
                $result = $this->uncfg->fetch($olt);
            } catch (Throwable $e) {
                $sections[] = '❌ <b>'.$this->escape((string) $olt->name).'</b> — '.$this->escape($this->truncate($e->getMessage(), 120));

                continue;
            }

            if (! $result['ok']) {
                $sections[] = '❌ <b>'.$this->escape((string) $olt->name).'</b> — '.$this->escape($this->truncate((string) $result['error'], 120));

                continue;
            }

            if ($result['onus'] === []) {
                $sections[] = '✅ <b>'.$this->escape((string) $olt->name).'</b> — tidak ada ONU uncfg.';

                continue;
            }

            $lines = ['📶 <b>'.$this->escape((string) $olt->name).'</b> — '.count($result['onus']).' ONU menunggu registrasi:'];
            foreach (array_slice($result['onus'], 0, $maxPerOlt) as $onu) {
                $state = $onu['state'] ? ' · '.$this->escape((string) $onu['state']) : '';
                $lines[] = '• <code>'.$this->escape($onu['serial_number']).'</code> — PON '.$onu['slot'].'/'.$onu['port'].$state;
            }
            if (count($result['onus']) > $maxPerOlt) {
                $lines[] = '… dan '.(count($result['onus']) - $maxPerOlt).' ONU lagi.';
            }

            $sections[] = implode("\n", $lines);
        }

        $sections[] = '🕒 '.$this->now();

        $keyboard = [
            [TelegramKeyboard::button('🔄 Cek Ulang', TelegramKeyboard::uncfg($scope))],
            TelegramKeyboard::backRow(null),
        ];

        return TelegramReply::make(implode("\n\n", $sections), $keyboard);
    }

    private function searchHelpScreen(): TelegramReply
    {
        $text = "🔎 <b>Cari ONU (Global)</b>\n\n"
            ."Ketik salah satu di chat:\n"
            ."<code>/search budi</code> — cari sebagian nama pelanggan\n"
            ."<code>/search ZTEGC12</code> — cari sebagian serial\n\n"
            .'Pencarian mencakup <b>semua OLT</b>; hasil yang banyak bisa di-scroll dengan tombol ⬅️➡️.';

        return TelegramReply::make($text, [TelegramKeyboard::backRow(null)]);
    }

    private function onuSearch(string $query): TelegramReply
    {
        $query = trim($query);

        if ($query === '') {
            return TelegramReply::make(
                'Format: <code>/search &lt;nama|serial&gt;</code>'."\nContoh: <code>/search budi</code>",
                [TelegramKeyboard::backRow(null)],
            );
        }

        $matches = $this->runSearch($query);

        if ($matches === []) {
            return TelegramReply::make(
                '🔎 ONU "<b>'.$this->escape($query).'</b>" tidak ditemukan di cache OLT mana pun.',
                [TelegramKeyboard::backRow(null)],
            );
        }

        if (count($matches) === 1) {
            $m = $matches[0];

            $rows = $this->onuActionRows($m['olt_id'], $m['slot'], $m['port'], $m['onu_id'], TelegramKeyboard::SRC_MENU, 0, 0);
            $rows[] = TelegramKeyboard::backRow(null);

            return TelegramReply::make($this->onuDetailText($m, $m['olt_id']), $rows);
        }

        // Stash the query so the result pages (callbacks) can re-run it.
        $token = Str::random(10);
        Cache::put($this->searchKey($token), $query, self::SEARCH_TTL);

        return $this->renderSearchResults($token, $query, $matches, 0);
    }

    private function searchResultsScreen(string $token, int $page): TelegramReply
    {
        $query = Cache::get($this->searchKey($token));

        if (! is_string($query) || $query === '') {
            return TelegramReply::make(
                '🔎 Pencarian kedaluwarsa. Kirim ulang, mis. <code>/search budi</code>.',
                [TelegramKeyboard::backRow(null)],
            );
        }

        return $this->renderSearchResults($token, $query, $this->runSearch($query), $page);
    }

    private function searchResultDetail(string $token, int $page, int $oltId, int $slot, int $port, int $onuId): TelegramReply
    {
        $onu = $this->onus->onu($oltId, $slot, $port, $onuId);
        $back = TelegramKeyboard::searchResults($token, $page);

        if ($onu === null) {
            return TelegramReply::make('ONU tidak ditemukan di cache.', [TelegramKeyboard::backRow($back)]);
        }

        // Callback reboot hanya memuat argumen numerik, jadi konteks token pencarian tidak
        // ikut terbawa — dari alur reboot, "kembali" jatuh ke Menu (SRC_MENU).
        $rows = $this->onuActionRows($oltId, $slot, $port, $onuId, TelegramKeyboard::SRC_MENU, 0, 0);
        $rows[] = TelegramKeyboard::backRow($back);

        return TelegramReply::make($this->onuDetailText($onu, $oltId), $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     */
    private function renderSearchResults(string $token, string $query, array $matches, int $page): TelegramReply
    {
        if ($matches === []) {
            return TelegramReply::make(
                '🔎 ONU "<b>'.$this->escape($query).'</b>" tidak lagi ditemukan di cache.',
                [TelegramKeyboard::backRow(null)],
            );
        }

        [$slice, $page, $totalPages] = $this->paginate($matches, $page, TelegramKeyboard::PAGE_SIZE);

        $rows = [];
        foreach ($slice as $m) {
            $label = TelegramOnuQueryService::statusIcon($m).' '.$this->onuButtonLabel($m, true);
            $rows[] = [TelegramKeyboard::button(
                $this->truncate($label, 48),
                TelegramKeyboard::searchDetail($token, $page, $m['olt_id'], $m['slot'], $m['port'], $m['onu_id']),
            )];
        }

        if (($pager = TelegramKeyboard::pager($page, $totalPages, fn ($pg) => TelegramKeyboard::searchResults($token, $pg))) !== []) {
            $rows[] = $pager;
        }
        $rows[] = TelegramKeyboard::backRow(null);

        $count = count($matches);
        $shown = $count >= self::SEARCH_LIMIT ? self::SEARCH_LIMIT.'+' : (string) $count;
        $text = '🔎 <b>'.$shown.' ONU cocok</b> dengan "'.$this->escape($query).'"'."\nPilih untuk lihat detail:";

        return TelegramReply::make($text, $rows);
    }

    private function searchKey(string $token): string
    {
        return 'tg:search:'.$token;
    }

    // --- helpers ---

    /**
     * @param  array<string, mixed>  $onu
     */
    private function onuDetailText(array $onu, int $oltId): string
    {
        $title = $onu['serial_number'] ?: ($onu['name'] ?: ($onu['interface'] ?: 'ONU'));
        $online = (bool) $onu['online'];
        $phase = (string) ($onu['phase_state'] ?? 'Unknown');

        $lines = [
            TelegramOnuQueryService::statusIcon($onu).' <b>'.$this->escape((string) $title).'</b>',
            '',
            'OLT: '.$this->escape((string) $onu['olt_name']),
            'Interface: '.$this->escape((string) ($onu['interface'] ?: '-')).' (Slot '.$onu['slot'].' / PON '.$onu['port'].')',
            'Status: '.($online ? '✅ Online' : '❌ Offline').' ('.$this->escape($phase).')',
        ];

        if (! $online && filled($onu['last_down_cause'] ?? null)) {
            $lines[] = 'Penyebab down: '.$this->escape((string) $onu['last_down_cause']);
        }

        $lines[] = 'RX Power: '.TelegramOnuQueryService::rxLine($onu);

        $customer = $this->onus->customerFor($oltId, $onu);
        $lines[] = '👤 Pelanggan: '.($customer !== null ? $this->escape($customer) : '-');

        return implode("\n", $lines);
    }

    /**
     * Compact label for an ONU button: customer / serial / interface, optionally prefixed
     * with a short OLT tag for cross-OLT lists.
     *
     * @param  array<string, mixed>  $onu
     */
    private function onuButtonLabel(array $onu, bool $withOlt = false): string
    {
        $base = (string) ($onu['customer'] ?: ($onu['serial_number'] ?: ($onu['interface'] ?: ('ONU '.$onu['onu_id']))));
        $base = $this->truncate($base, 22);

        if ($withOlt) {
            return '['.$this->truncate((string) $onu['olt_name'], 10).'] '.$base;
        }

        return $base;
    }

    /**
     * Filter toggle row for the per-port ONU list (current filter marked with ✓).
     *
     * @return array<int, array<string, string>>
     */
    private function filterRow(int $oltId, int $slot, int $port, int $current): array
    {
        $mark = fn (int $f, string $label) => ($f === $current ? '• ' : '').$label;

        return [
            TelegramKeyboard::button($mark(TelegramKeyboard::FILTER_ALL, 'Semua'), TelegramKeyboard::portOnus($oltId, $slot, $port, TelegramKeyboard::FILTER_ALL, 0)),
            TelegramKeyboard::button($mark(TelegramKeyboard::FILTER_LOS, '🔴 LOS'), TelegramKeyboard::portOnus($oltId, $slot, $port, TelegramKeyboard::FILTER_LOS, 0)),
            TelegramKeyboard::button($mark(TelegramKeyboard::FILTER_RX, '📉 Redaman'), TelegramKeyboard::portOnus($oltId, $slot, $port, TelegramKeyboard::FILTER_RX, 0)),
        ];
    }

    /**
     * Action-button rows shown on an ONU detail screen (currently just Reboot when the
     * OLT's driver supports it). The back-context args are forwarded so the confirmation
     * screen's "Batal" returns to the exact same detail view.
     *
     * @return array<int, array<int, array<string, string>>>
     */
    private function onuActionRows(int $oltId, int $slot, int $port, int $onuId, int $src, int $scope, int $page): array
    {
        if (! $this->supportsReboot($oltId)) {
            return [];
        }

        return [[TelegramKeyboard::button(
            '🔄 Reboot ONU',
            TelegramKeyboard::onuReboot($oltId, $slot, $port, $onuId, $src, $scope, $page),
        )]];
    }

    private function supportsReboot(int $oltId): bool
    {
        $olt = $this->onus->findOlt($oltId);

        if ($olt === null) {
            return false;
        }

        return (bool) (SmartOltSupport::capabilities($this->oltDriver($olt), $olt)['supports_reboot'] ?? false);
    }

    private function oltDriver(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
    }

    private function onuBack(int $oltId, int $slot, int $port, int $src, int $scope, int $page): ?string
    {
        return match ($src) {
            TelegramKeyboard::SRC_LOS_LIST => TelegramKeyboard::losList($scope, $page),
            TelegramKeyboard::SRC_RX_LIST => TelegramKeyboard::rxList($scope, $page),
            TelegramKeyboard::SRC_MENU => TelegramKeyboard::menu(),
            default => TelegramKeyboard::portOnus($oltId, $slot, $port, $src, $page),
        };
    }

    /**
     * Resolve an /olt or /los or /redaman argument to an OLT scope id (0 = all OLTs).
     */
    private function resolveScope(string $query): int
    {
        if ($query === '') {
            return 0;
        }

        return $this->resolveOlt($query)?->id ?? 0;
    }

    private function resolveOlt(string $query): ?SnmpOlt
    {
        $needle = strtolower($query);

        return SnmpOlt::query()
            ->when(ctype_digit($query), fn ($q) => $q->where('id', (int) $query))
            ->when(! ctype_digit($query), fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"]))
            ->orderBy('name')
            ->first();
    }

    private function scopeLabel(int $scope): string
    {
        if ($scope === 0) {
            return 'Semua OLT';
        }

        return $this->escape((string) ($this->onus->oltSummary($scope)['name'] ?? ('OLT '.$scope)));
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return array{0: array<int, T>, 1: int, 2: int}
     */
    private function paginate(array $items, int $page, int $perPage): array
    {
        $totalPages = max(1, (int) ceil(count($items) / $perPage));
        $page = max(0, min($page, $totalPages - 1));
        $slice = array_slice($items, $page * $perPage, $perPage);

        return [$slice, $page, $totalPages];
    }

    /**
     * Find ONUs across every OLT's cached port_onus by serial / name / customer / interface.
     *
     * @return array<int, array<string, mixed>>
     */
    private function runSearch(string $query): array
    {
        $needle = strtolower(trim($query));
        $matches = [];

        foreach ($this->onus->allOnus() as $onu) {
            $haystack = strtolower(implode(' ', [
                (string) ($onu['serial_number'] ?? ''),
                (string) ($onu['name'] ?? ''),
                (string) ($onu['customer'] ?? ''),
                (string) ($onu['interface'] ?? ''),
            ]));

            if (trim($haystack) === '' || ! str_contains($haystack, $needle)) {
                continue;
            }

            $matches[] = $onu;

            if (count($matches) >= self::SEARCH_LIMIT) {
                break;
            }
        }

        return $matches;
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

    private function truncate(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
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
