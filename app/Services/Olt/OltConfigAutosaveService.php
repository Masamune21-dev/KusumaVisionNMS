<?php

namespace App\Services\Olt;

use App\Console\Commands\AutosaveOltConfigsCommand;
use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Services\CData\CDataCliWriteService;
use App\Services\Hioso\HiosoCliWriteService;
use App\Services\Hsgq\HsgqWebService;
use App\Services\ZteCliProvisioningExecutor;
use App\Support\CliOutputSanitizer;
use App\Support\SmartOltSupport;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Auto save konfigurasi OLT — simpan running-config ke memori OLT tanpa menekan tombol Save.
 * Saklar per-OLT `snmp_olts.config_autosave_enabled`, dijalankan {@see AutosaveOltConfigsCommand}:
 *
 * - jalur perubahan (tiap 5 menit): OLT yang diubah lewat NMS ({@see SnmpOlt::markConfigChanged()})
 *   disimpan setelah tenang {@see self::SETTLE_MINUTES} menit, paling lambat
 *   {@see self::MAX_DIRTY_MINUTES} menit sejak perubahan tertua yang belum tersimpan;
 * - jalur harian (`--all`): semua OLT ber-saklar nyala, jaring pengaman perubahan lewat telnet.
 *
 * Perintah per family sama dengan tombol Save manual (ZTE `write`, C-Data `config`→`save`,
 * HiOSO `write`, HSGQ `/system_save`). Ditunda tanpa menyentuh OLT selama ada port PON yang
 * dimatikan dari NMS: "Matikan Port" sengaja tanpa `write` (keputusan user — port menyala lagi bila
 * OLT reboot), dan save apa pun akan mengabadikan `shutdown`-nya.
 */
class OltConfigAutosaveService
{
    public const SETTLE_MINUTES = 10;

    public const MAX_DIRTY_MINUTES = 60;

    /** Jalur perubahan tak mencoba lagi OLT yang baru gagal (mis. tak terjangkau) selama ini. */
    public const RETRY_AFTER_FAILURE_MINUTES = 30;

    public const DEFER_PORT_DISABLED = 'port_disabled';

    public function __construct(
        private readonly ZteCliProvisioningExecutor $zte,
        private readonly CDataCliWriteService $cdata,
        private readonly HiosoCliWriteService $hioso,
        private readonly HsgqWebService $hsgq,
    ) {}

    /**
     * NMS bisa menyimpan config OLT ini: family ber-capability `supports_config_save` dan jalur
     * aksesnya terisi — sama dengan syarat tombol Save di daftar OLT (HSGQ lewat port web, lainnya
     * telnet), ditambah akun CLI (HSGQ: akun web = akun CLI).
     */
    public function supports(SnmpOlt $olt): bool
    {
        if ($olt->is_demo) {
            return false;
        }

        $driver = $this->driverOf($olt);
        if (! (SmartOltSupport::capabilities($driver, $olt)['supports_config_save'] ?? false)) {
            return false;
        }

        $transportReady = SmartOltSupport::isHsgq($driver)
            ? (bool) $olt->web_port
            : $olt->cli_transport === 'telnet';

        return $transportReady && filled($olt->cli_username) && filled($olt->cli_password);
    }

    /** Jalur perubahan: ada perubahan belum tersimpan yang sudah waktunya disimpan. */
    public function isDue(SnmpOlt $olt, ?CarbonInterface $now = null): bool
    {
        if (! $olt->hasUnsavedConfig()) {
            return false;
        }

        $now ??= now();

        if ($olt->config_autosave_status === SnmpOlt::AUTOSAVE_FAILED
            && $olt->config_autosave_at?->gt($now->copy()->subMinutes(self::RETRY_AFTER_FAILURE_MINUTES))) {
            return false;
        }

        $settled = $olt->config_changed_at === null
            || $olt->config_changed_at->lte($now->copy()->subMinutes(self::SETTLE_MINUTES));

        return $settled || $olt->config_dirty_since->lte($now->copy()->subMinutes(self::MAX_DIRTY_MINUTES));
    }

    /** Alasan menunda (kode `DEFER_*`), atau null bila boleh disimpan sekarang. */
    public function deferReason(SnmpOlt $olt): ?string
    {
        $portDisabled = AlarmEvent::query()
            ->withoutGlobalScopes()
            ->where('snmp_olt_id', $olt->id)
            ->where('type', AlarmEvent::TYPE_PORT_DISABLED)
            ->whereIn('status', [AlarmEvent::STATUS_ACTIVE, AlarmEvent::STATUS_PENDING])
            ->exists();

        return $portDisabled ? self::DEFER_PORT_DISABLED : null;
    }

    /**
     * Satu putaran auto save untuk satu OLT. `$force` = jalur harian (simpan walau tak ada
     * perubahan tercatat). Mengembalikan status yang dicatat (`SnmpOlt::AUTOSAVE_*`), atau null
     * bila OLT dilewati (saklar mati, tak didukung, belum waktunya, atau sedang disimpan proses lain).
     */
    public function run(SnmpOlt $olt, bool $force = false): ?string
    {
        $lock = Cache::lock('olt-autosave:'.$olt->id, 300);
        if (! $lock->get()) {
            return null;
        }

        try {
            // Baca ulang: saklar/penanda bisa berubah sejak daftar OLT diambil perintahnya.
            $olt->refresh();

            if (! $olt->config_autosave_enabled || ! $this->supports($olt)) {
                return null;
            }

            if (! $force && ! $this->isDue($olt)) {
                return null;
            }

            if ($reason = $this->deferReason($olt)) {
                $this->record($olt, SnmpOlt::AUTOSAVE_DEFERRED, $reason);

                return SnmpOlt::AUTOSAVE_DEFERRED;
            }

            $result = $this->save($olt);

            if (! $result['ok']) {
                Log::warning('Auto save config OLT gagal', ['olt_id' => $olt->id, 'olt' => $olt->name, 'error' => $result['error']]);
            }

            $status = $result['ok'] ? SnmpOlt::AUTOSAVE_OK : SnmpOlt::AUTOSAVE_FAILED;
            $this->record($olt, $status, $result['error']);

            return $status;
        } finally {
            $lock->release();
        }
    }

    /**
     * Simpan running-config ke memori OLT lewat jalur family-nya, lalu catat `config_saved_at`.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function save(SnmpOlt $olt): array
    {
        $startedAt = now();
        $driver = $this->driverOf($olt);

        try {
            $result = match (true) {
                $driver === SmartOltSupport::DRIVER_ZTE => $this->zte->saveConfig($olt),
                SmartOltSupport::isCData($driver) => $this->cdata->saveConfig($olt),
                SmartOltSupport::isHioso($driver) => $this->hioso->saveConfig($olt),
                SmartOltSupport::isHsgq($driver) => $this->hsgq->saveConfig($olt),
                default => throw new RuntimeException(__('olt.autosave_unsupported')),
            };
            $ok = (bool) ($result['ok'] ?? false);
            $error = $result['error'] ?? null;
        } catch (Throwable $exception) {
            $ok = false;
            $error = $exception->getMessage();
        }

        if ($ok) {
            SnmpOlt::markConfigSaved($olt->id, $startedAt);
        }

        return [
            'ok' => $ok,
            'error' => $ok ? null : mb_substr(CliOutputSanitizer::clean((string) $error), 0, 500),
        ];
    }

    private function record(SnmpOlt $olt, string $status, ?string $error): void
    {
        // Query polos: jejak auto save tak boleh menggeser `updated_at` atau menulis audit.
        DB::table('snmp_olts')->where('id', $olt->id)->update([
            'config_autosave_at' => now(),
            'config_autosave_status' => $status,
            'config_autosave_error' => $error,
        ]);
    }

    private function driverOf(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
    }
}
