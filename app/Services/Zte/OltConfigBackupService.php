<?php

namespace App\Services\Zte;

use App\Models\OltConfigBackup;
use App\Models\SnmpOlt;
use App\Services\ZteCliProvisioningExecutor;
use App\Support\CliOutputSanitizer;
use App\Support\SmartOltSupport;

/**
 * Backup running-config OLT ZTE via CLI (`show running-config`). Menyimpan tiap
 * snapshot sebagai satu {@see OltConfigBackup} (isi terenkripsi). Versi identik
 * beruntun di-dedup (sha256) supaya riwayat hanya berisi perubahan nyata.
 */
class OltConfigBackupService
{
    public function __construct(private readonly ZteCliProvisioningExecutor $executor) {}

    /**
     * Ambil & simpan satu versi backup. Bila isi sama dengan backup OK terakhir → tak
     * membuat baris baru (changed=false). Kegagalan CLI dicatat sebagai baris status=failed
     * agar tetap terlihat di riwayat.
     *
     * @return array{ok:bool, changed:bool, backup:?OltConfigBackup, error:?string}
     */
    public function capture(SnmpOlt $olt, string $trigger = OltConfigBackup::TRIGGER_MANUAL, ?int $userId = null): array
    {
        if (SmartOltSupport::driverKey($olt) !== SmartOltSupport::DRIVER_ZTE) {
            return ['ok' => false, 'changed' => false, 'backup' => null, 'error' => 'Backup config saat ini hanya untuk OLT ZTE.'];
        }

        $result = $this->executor->execute($olt, "terminal length 0\nshow running-config");
        $config = $this->stripEcho(CliOutputSanitizer::clean((string) ($result['output'] ?? '')));

        if (! ($result['ok'] ?? false) || $this->looksEmpty($config)) {
            $error = CliOutputSanitizer::clean((string) ($result['error'] ?? '')) ?: 'Config kosong / gagal diambil dari OLT.';
            $backup = OltConfigBackup::create([
                'snmp_olt_id' => $olt->id,
                'content' => null,
                'size_bytes' => 0,
                'sha256' => null,
                'trigger' => $trigger,
                'status' => OltConfigBackup::STATUS_FAILED,
                'error' => $error,
                'created_by' => $userId,
                'captured_at' => now(),
            ]);

            return ['ok' => false, 'changed' => false, 'backup' => $backup, 'error' => $error];
        }

        $hash = hash('sha256', $config);

        $latest = OltConfigBackup::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('status', OltConfigBackup::STATUS_OK)
            ->latest('captured_at')
            ->first();

        // Tak berubah sejak backup OK terakhir → jangan gandakan versi.
        if ($latest && $latest->sha256 === $hash) {
            return ['ok' => true, 'changed' => false, 'backup' => $latest, 'error' => null];
        }

        $backup = OltConfigBackup::create([
            'snmp_olt_id' => $olt->id,
            'content' => $config,
            'size_bytes' => strlen($config),
            'sha256' => $hash,
            'trigger' => $trigger,
            'status' => OltConfigBackup::STATUS_OK,
            'error' => null,
            'created_by' => $userId,
            'captured_at' => now(),
        ]);

        return ['ok' => true, 'changed' => true, 'backup' => $backup, 'error' => null];
    }

    /**
     * Buang echo perintah yang kita kirim agar hash dedup stabil & isi bersih.
     */
    private function stripEcho(string $raw): string
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (in_array($trimmed, ['terminal length 0', 'ter len 0', 'show running-config'], true)) {
                continue;
            }

            $out[] = rtrim($line);
        }

        return trim(implode("\n", $out));
    }

    private function looksEmpty(string $config): bool
    {
        return strlen(trim($config)) < 40;
    }
}
