<?php

namespace App\Console\Commands;

use App\Services\Genieacs\GenieacsDeviceSyncService;
use Illuminate\Console\Command;

/**
 * Cocokkan katalog device GenieACS dengan inventori ONU NMS.
 *
 * Sengaja TIDAK dijadwalkan tiap menit: peta ONU tidak berubah secepat itu dan
 * server NMS sudah cukup sibuk oleh poller SNMP. Lihat `routes/console.php`.
 */
class GenieacsMatchOnuCommand extends Command
{
    protected $signature = 'genieacs:match-onu
                            {--dry-run : Hitung saja, jangan tulis ke database}';

    protected $description = 'Cocokkan device GenieACS (TR-069) dengan posisi ONU di NMS';

    public function handle(GenieacsDeviceSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Mode uji — tidak ada yang ditulis ke database.');
        }

        $result = $sync->sync($dryRun);

        if (! ($result['ok'] ?? false)) {
            // Belum dikonfigurasi bukan kegagalan — perintah ini terjadwal, dan
            // menandainya gagal tiap 15 menit hanya membuat log berisik pada
            // pemasangan yang memang belum memakai GenieACS.
            if (($result['error'] ?? null) === 'genieacs_not_configured') {
                $this->comment('GenieACS belum dikonfigurasi di Pengaturan — tidak ada yang dicocokkan.');

                return self::SUCCESS;
            }

            $this->error('Pencocokan gagal: '.($result['error'] ?? 'tidak diketahui'));

            return self::FAILURE;
        }

        $matched = $result['matched_serial'] + $result['matched_mac'] + $result['manual'];

        $this->table(['Keterangan', 'Jumlah'], [
            ['Device di GenieACS', $result['devices']],
            ['Cocok lewat serial', $result['matched_serial']],
            ['Cocok lewat MAC (±1)', $result['matched_mac']],
            ['Disematkan manual', $result['manual']],
            ['Pin manual basi (ONU-nya hilang)', $result['manual_stale'] ?? 0],
            ['Belum tercocok', $result['unmatched']],
            ['Dilepas karena diperebutkan', $result['conflicts']],
        ]);

        $persen = $result['devices'] > 0 ? round($matched / $result['devices'] * 100, 1) : 0.0;
        $this->info("Tercocok {$matched} dari {$result['devices']} device ({$persen}%) dalam {$result['duration_ms']} ms.");

        if (($result['manual_stale'] ?? 0) > 0) {
            // Bukan galat: justru penanda ada ONU yang diganti unit atau
            // dicabut sejak operator menyematkannya.
            $this->warn("{$result['manual_stale']} pin manual menunjuk ONU yang sudah tidak ada di inventori — pasangannya dilepas, pinnya disimpan supaya bisa ditinjau.");
        }

        if ($result['conflicts'] > 0) {
            $this->warn(
                "{$result['conflicts']} device dilepas karena memperebutkan posisi ONU yang sama. ".
                'Sengaja dikosongkan, bukan dipilih salah satu — tetapkan manual bila perlu.'
            );
        }

        return self::SUCCESS;
    }
}
