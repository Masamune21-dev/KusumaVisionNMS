<?php

namespace App\Services\Genieacs;

use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Redaman ONU menurut TR-069 (`VirtualParameters.RXPower`, disimpan
 * `genieacs:match-onu`) — CADANGAN untuk ONU yang DDM-nya tak dilaporkan OLT.
 *
 * Kelas ini hanya menjawab "bacaan TR-069 segar apa yang ada untuk OLT ini".
 * KAPAN boleh dipakai diputuskan driver OLT, karena hanya driver yang tahu
 * status link saat ini: nilai TR-069 adalah bacaan terakhir sebelum ONU
 * berhenti melapor, jadi untuk ONU yang offline/LOS ia tetap menyimpan
 * redaman lama yang bagus. Aturannya (HiOSO, {@see \App\Services\Hioso\HiosoEponSnmpService}):
 * link ONU teramati Up di poll ini, RX OLT kosong, dan bacaan lebih baru dari
 * terakhir kali link ONU teramati putus.
 *
 * Membaca tabel lokal saja — tidak pernah memanggil GenieACS.
 */
class GenieacsRxReadings
{
    /**
     * Inform tiap 5 menit + sinkronisasi tiap 15 menit → bacaan ONU sehat
     * berumur ≤ ±20 menit saat dibaca. Lebih tua dari ini = ONU berhenti
     * melapor atau sinkronisasi macet; lebih baik kosong daripada angka basi.
     */
    public const MAX_AGE_MINUTES = 30;

    /**
     * @return array<string, array{dbm: float, at: Carbon}> ber-key "{slot}.{port}.{onuId}"
     */
    public function forOlt(SnmpOlt $olt): array
    {
        if (! $olt->exists) {
            return [];
        }

        try {
            return GenieacsDeviceMap::query()
                ->where('snmp_olt_id', $olt->id)
                ->whereNotNull('onu_id')
                ->whereNotNull('rx_power_dbm')
                ->where('rx_power_at', '>=', now()->subMinutes(self::MAX_AGE_MINUTES))
                ->get(['slot', 'port', 'onu_id', 'rx_power_dbm', 'rx_power_at'])
                ->mapWithKeys(fn (GenieacsDeviceMap $row) => [
                    "{$row->slot}.{$row->port}.{$row->onu_id}" => ['dbm' => $row->rx_power_dbm, 'at' => $row->rx_power_at],
                ])
                ->all();
        } catch (Throwable $e) {
            // Cadangan tak boleh menggagalkan polling OLT.
            report($e);

            return [];
        }
    }
}
