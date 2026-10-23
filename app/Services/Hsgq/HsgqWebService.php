<?php

namespace App\Services\Hsgq;

use App\Models\SnmpOlt;
use App\Support\AuditLogger;
use App\Support\SmartOltSupport;
use RuntimeException;
use Throwable;

/**
 * Aksi ONU HSGQ lewat API JSON web OLT: reboot, ganti nama/deskripsi, simpan konfigurasi.
 *
 * ⚠️ BELUM TERVERIFIKASI DI PERANGKAT. Payload direproduksi dari klien pihak ketiga yang dipakai nyata
 * (`plugnowplay/HSGQtelegram`, dikonfirmasi bot EPON lain) — lihat `docs/SMARTOLT_HSGQ_GUIDE.md` §2.2:
 *   - GPON `POST /gponont_mgmt?form=info` `{identifier, flags, ont_name, ont_description}`; flags 4 =
 *     reboot, 8 = set nama/deskripsi. `identifier` diambil dari `GET /ontinfo_table`.
 *   - EPON `POST /onumgmt?form=config` `{port_id, onu_id, flags, fec_mode, onu_name, onu_desc}`; flags
 *     1 = reboot, 8 = set nama/deskripsi.
 *   - `POST /system_save {}` menyimpan konfigurasi (tanpa itu perubahan hilang saat OLT reboot).
 *
 * Dua pengaman karena belum diuji:
 *   - ONU target dicari di tabel web lewat **SN (GPON) / MAC (EPON)** dari snapshot SNMP, bukan lewat
 *     nomor port/ONU — basis penomoran `port_id`/`ont_id` web vs indeks SNMP belum diketahui.
 *   - Nama & deskripsi yang sedang berlaku dikirim ulang pada reboot, dan `fec_mode` EPON diambil dari
 *     `base-info` ONU bila ada (bot selalu mengirim `fec_mode: 1`, yang bisa mengubah mode FEC).
 * Setiap aksi tercatat di Log Audit.
 */
class HsgqWebService
{
    private const GPON_REBOOT = 4;

    private const EPON_REBOOT = 1;

    private const SET_INFO = 8;

    /** Nilai `fec_mode` yang dikirim bot pihak ketiga bila base-info tak memuatnya. */
    private const DEFAULT_FEC_MODE = 1;

    public const NAME_MAX = 32;

    public function __construct(private readonly HsgqWebClient $client) {}

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function reboot(SnmpOlt $olt, int $slot, int $port, int $onuId): array
    {
        return $this->run($olt, 'onu.rebooted', $slot, $port, $onuId, function (array $row) use ($olt): array {
            return $this->isEpon($olt)
                ? $this->client->post($olt, '/onumgmt?form=config', [
                    'port_id' => $row['port_id'],
                    'onu_id' => $row['onu_id'],
                    'flags' => self::EPON_REBOOT,
                    'fec_mode' => $this->eponFecMode($olt, $row),
                    'onu_name' => $row['name'],
                    'onu_desc' => $row['description'],
                ])
                : $this->client->post($olt, '/gponont_mgmt?form=info', [
                    'identifier' => $row['identifier'],
                    'flags' => self::GPON_REBOOT,
                    'ont_name' => $row['name'],
                    'ont_description' => $row['description'],
                ]);
        });
    }

    /**
     * Ganti nama (dan deskripsi bila diberikan; null = pertahankan deskripsi di OLT).
     *
     * @return array{ok: bool, error: ?string, name?: string, description?: string}
     */
    public function rename(SnmpOlt $olt, int $slot, int $port, int $onuId, ?string $name, ?string $description = null): array
    {
        $label = self::sanitize($name);
        if ($label === '') {
            return ['ok' => false, 'error' => __('hsgq.onu_name_required')];
        }

        $desc = $description === null ? null : self::sanitize($description);
        $applied = null;

        $result = $this->run($olt, 'onu.renamed', $slot, $port, $onuId, function (array $row) use ($olt, $label, $desc, &$applied): array {
            $applied = $desc ?? $row['description'];

            return $this->isEpon($olt)
                ? $this->client->post($olt, '/onumgmt?form=config', [
                    'port_id' => $row['port_id'],
                    'onu_id' => $row['onu_id'],
                    'flags' => self::SET_INFO,
                    'fec_mode' => $this->eponFecMode($olt, $row),
                    'onu_name' => $label,
                    'onu_desc' => $applied,
                ])
                : $this->client->post($olt, '/gponont_mgmt?form=info', [
                    'identifier' => $row['identifier'],
                    'flags' => self::SET_INFO,
                    'ont_name' => $label,
                    'ont_description' => $applied,
                ]);
        }, ['name' => $label]);

        return $result['ok'] ? [...$result, 'name' => $label, 'description' => (string) $applied] : $result;
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function saveConfig(SnmpOlt $olt): array
    {
        try {
            $json = $this->client->post($olt, '/system_save', []);
            $ok = HsgqWebClient::succeeded($json);
            $error = $ok ? null : (HsgqWebClient::message($json) ?: __('hsgq.web_rejected'));
        } catch (Throwable $exception) {
            $ok = false;
            $error = $exception->getMessage();
        }

        $this->audit($olt, $ok ? 'olt.config_saved' : 'olt.config_save_failed', [], $error,
            sprintf('Simpan konfigurasi OLT HSGQ %s lewat web: %s', $olt->name, $ok ? 'berhasil' : 'gagal'));

        return ['ok' => $ok, 'error' => $error];
    }

    /**
     * Tabel ONU dari web (GPON `/ontinfo_table`, EPON `/onutable`), dinormalkan.
     *
     * @return list<array<string, mixed>>
     */
    public function onuTable(SnmpOlt $olt): array
    {
        $epon = $this->isEpon($olt);
        $json = $this->client->get($olt, $epon ? '/onutable' : '/ontinfo_table');
        $rows = is_array($json['data'] ?? null) ? $json['data'] : [];

        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $identity = $epon
                ? HsgqValue::mac((string) ($row['macaddr'] ?? ''))
                : self::normalizeSerial((string) ($row['ont_sn'] ?? ''));

            $normalized[] = [
                'identity' => $identity !== null && $identity !== '' ? self::identityKey($identity) : null,
                'identifier' => $row['identifier'] ?? null,
                'port_id' => $row['port_id'] ?? null,
                'onu_id' => $row[$epon ? 'onu_id' : 'ont_id'] ?? null,
                'name' => (string) ($row[$epon ? 'onu_name' : 'ont_name'] ?? ''),
                'description' => (string) ($row[$epon ? 'onu_desc' : 'ont_description'] ?? ''),
                'keys' => array_keys($row),
            ];
        }

        return $normalized;
    }

    /**
     * Laporan diagnostik jalur web (tanpa sandi/token/SN): login, info board, nama field tabel ONU,
     * dan — untuk EPON — ada tidaknya `fec_mode` di base-info.
     *
     * @return array<string, mixed>
     */
    public function diagnose(SnmpOlt $olt): array
    {
        if (! $olt->web_port) {
            return ['configured' => false];
        }

        $report = ['configured' => true, 'login' => false, 'errors' => []];

        try {
            $this->client->login($olt);
            $report['login'] = true;

            $system = $this->client->get($olt, '/board?info=system')['data'] ?? [];
            $report['board'] = is_array($system) ? [
                'keys' => array_keys($system),
                'product_name' => $system['product_name'] ?? null,
                'fw_ver' => $system['fw_ver'] ?? null,
                'device_type' => $system['device_type'] ?? null,
                'ponports' => $system['ponports'] ?? null,
            ] : null;

            $table = $this->onuTable($olt);
            $report['onu_table'] = [
                'endpoint' => $this->isEpon($olt) ? '/onutable' : '/ontinfo_table',
                'rows' => count($table),
                'keys' => $table[0]['keys'] ?? [],
                'sample' => isset($table[0]) ? [
                    'identity' => HsgqValue::mask($table[0]['identity']),
                    'port_id' => $table[0]['port_id'],
                    'onu_id' => $table[0]['onu_id'],
                    'has_identifier' => $table[0]['identifier'] !== null,
                ] : null,
            ];

            if ($this->isEpon($olt) && isset($table[0])) {
                $base = $this->baseInfo($olt, $table[0]);
                $report['epon_base_info'] = ['keys' => array_keys($base), 'fec_mode' => $base['fec_mode'] ?? null];
            }
        } catch (Throwable $exception) {
            $report['errors'][] = $exception->getMessage();
        }

        return $report;
    }

    /** Nama/deskripsi aman untuk firmware OLT: huruf, angka, spasi, `_ - . @ # ( ) /`; maks 32. */
    public static function sanitize(?string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9 _.\-@#()\/]/', '_', trim((string) $value)) ?? '';

        return mb_substr(trim($value), 0, self::NAME_MAX);
    }

    /**
     * SN GPON dari web → bentuk SNMP/CLI `HWTCA9089620`. Menerima juga bentuk 16 hex
     * (`48575443A9089620`, 4 byte vendor ASCII + 4 byte) dan berpemisah (`HWTC-A9089620`).
     */
    public static function normalizeSerial(string $serial): string
    {
        $clean = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $serial) ?? '');

        if (preg_match('/^[0-9A-F]{16}$/', $clean)) {
            $vendor = (string) hex2bin(substr($clean, 0, 8));
            if (preg_match('/^[A-Z0-9]{4}$/i', $vendor)) {
                return strtoupper($vendor).substr($clean, 8);
            }
        }

        return $clean;
    }

    /** Kunci pembanding SN/MAC: huruf besar tanpa pemisah. */
    public static function identityKey(string $identity): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $identity) ?? '');
    }

    /**
     * Cari ONU di tabel web lewat SN/MAC snapshot, jalankan aksi, catat audit.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $action
     * @param  array<string, mixed>  $extra
     * @return array{ok: bool, error: ?string}
     */
    private function run(SnmpOlt $olt, string $event, int $slot, int $port, int $onuId, callable $action, array $extra = []): array
    {
        $cached = $this->cachedOnu($olt, $slot, $port, $onuId);
        $identity = (string) ($cached['serial_number'] ?? '');

        try {
            if ($identity === '') {
                throw new RuntimeException(__('hsgq.onu_not_in_cache'));
            }

            $key = self::identityKey($identity);
            $row = collect($this->onuTable($olt))->first(fn (array $r): bool => $r['identity'] === $key);
            if ($row === null) {
                throw new RuntimeException(__('hsgq.onu_not_on_web', ['identity' => $identity]));
            }

            if (! $this->isEpon($olt) && $row['identifier'] === null) {
                throw new RuntimeException(__('hsgq.onu_identifier_missing'));
            }

            $json = $action($row);
            $ok = HsgqWebClient::succeeded($json);
            $error = $ok ? null : (HsgqWebClient::message($json) ?: __('hsgq.web_rejected'));
        } catch (Throwable $exception) {
            $ok = false;
            $error = $exception->getMessage();
        }

        $this->audit(
            $olt,
            $ok ? $event : str_replace(['rebooted', 'renamed'], ['reboot_failed', 'rename_failed'], $event),
            ['slot' => $slot, 'port' => $port, 'onu_id' => $onuId, 'serial_number' => $identity ?: null, ...$extra],
            $error,
            sprintf(
                '%s ONU %s di OLT HSGQ %s lewat web: %s',
                $event === 'onu.rebooted' ? 'Reboot' : 'Ganti nama',
                sprintf('PON%02d/%d', $port, $onuId).($identity !== '' ? ' '.$identity : ''),
                $olt->name,
                $ok ? 'berhasil' : 'gagal',
            ),
        );

        return ['ok' => $ok, 'error' => $error];
    }

    /**
     * `fec_mode` ONU EPON yang sedang berlaku dari base-info; tak ada → nilai bawaan bot pihak ketiga.
     *
     * @param  array<string, mixed>  $row
     */
    private function eponFecMode(SnmpOlt $olt, array $row): int
    {
        $base = $this->baseInfo($olt, $row);

        return is_numeric($base['fec_mode'] ?? null) ? (int) $base['fec_mode'] : self::DEFAULT_FEC_MODE;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function baseInfo(SnmpOlt $olt, array $row): array
    {
        $data = $this->client->get($olt, sprintf(
            '/onumgmt?form=base-info&port_id=%d&onu_id=%d',
            (int) $row['port_id'],
            (int) $row['onu_id'],
        ))['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    private function isEpon(SnmpOlt $olt): bool
    {
        return SmartOltSupport::hsgqPonType($olt) === 'epon';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cachedOnu(SnmpOlt $olt, int $slot, int $port, int $onuId): ?array
    {
        foreach (data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []) as $onu) {
            if (is_array($onu) && (int) ($onu['onu_id'] ?? -1) === $onuId) {
                return $onu;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(SnmpOlt $olt, string $event, array $properties, ?string $error, string $description): void
    {
        AuditLogger::log(
            event: $event,
            auditable: $olt,
            properties: [...$properties, 'via' => 'hsgq_web', 'unverified_driver' => true, 'error' => $error],
            description: $description,
        );
    }
}
