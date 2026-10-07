<?php

namespace App\Services\Genieacs;

use App\Models\GenieacsDeviceMap;

/**
 * Lookup ringan "ONU ini sudah terhubung ke ACS atau belum".
 *
 * Dipakai tabel ONU di semua family. Membaca HANYA tabel lokal
 * `genieacs_device_map` — tidak pernah memanggil GenieACS saat merender
 * halaman. Katalognya disegarkan terpisah oleh `genieacs:match-onu`.
 */
class GenieacsMapService
{
    /**
     * Batas "masih hidup" untuk lencana di tabel ONU.
     *
     * DUA JAM, bukan dua kali interval inform (600 detik) seperti dugaan awal.
     * Alasannya bukan soal perangkat melainkan soal kesegaran data kita sendiri:
     * `last_inform_at` hanya disegarkan oleh `genieacs:match-onu` yang berjalan
     * tiap 15 menit, jadi stempel yang tersimpan bisa tertinggal sampai 15 menit
     * dari kenyataan. Ambang 10 menit membuat SELURUH armada (1.462 dari 1.462)
     * selalu tampak "lama tak inform" — terukur 22 Sep 2026.
     *
     * Dua jam = 24 kali interval inform terlewat. Cukup longgar untuk menyerap
     * jeda scheduler, cukup ketat untuk tetap menandai ONU yang benar-benar
     * berhenti melapor. Kesegaran sesungguhnya (detik ini) ada di panel
     * perangkat terhubung, yang memang memanggil ACS saat dibuka.
     */
    public const ONLINE_THRESHOLD_SECONDS = 7200;

    /**
     * Peta device ACS untuk sekumpulan OLT, ber-key "oltId.slot.port.onuId".
     *
     * Satu query untuk seluruh permintaan — jangan panggil per ONU di dalam
     * loop (pola yang sama dengan lookup ODP di OnuInventoryService).
     *
     * @param  array<int, int>  $oltIds
     * @return array<string, array<string, mixed>>
     */
    public function forOlts(array $oltIds, ?int $slot = null, ?int $port = null): array
    {
        if ($oltIds === []) {
            return [];
        }

        $threshold = now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS);

        return GenieacsDeviceMap::query()
            ->whereIn('snmp_olt_id', $oltIds)
            ->whereNotNull('onu_id')
            ->when($slot !== null, fn ($query) => $query->where('slot', $slot)->where('port', $port))
            ->get(['device_id', 'snmp_olt_id', 'slot', 'port', 'onu_id', 'match_method', 'last_inform_at', 'product_class', 'pppoe_username', 'tr069_ip'])
            ->mapWithKeys(fn (GenieacsDeviceMap $row) => [
                "{$row->snmp_olt_id}.{$row->slot}.{$row->port}.{$row->onu_id}" => [
                    'device_id' => $row->device_id,
                    'match_method' => $row->match_method,
                    'product_class' => $row->product_class,
                    // Nama secret PPPoE & IP manajemen TR-069 dari ACS, disegarkan
                    // bersama baris ini oleh `genieacs:match-onu` (tiap 15 menit).
                    'pppoe_username' => $row->pppoe_username,
                    'ip' => $row->tr069_ip,
                    'last_inform_at' => $row->last_inform_at?->toIso8601String(),
                    'online' => $row->last_inform_at !== null && $row->last_inform_at->greaterThan($threshold),
                ],
            ])
            ->all();
    }

    /**
     * Versi satu port, ber-key `onu_id` saja — bentuk yang dipakai halaman
     * ONU per port supaya frontend cukup melakukan lookup angka.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forPort(int $oltId, int $slot, int $port): array
    {
        $map = [];

        foreach ($this->forOlts([$oltId], $slot, $port) as $key => $value) {
            $map[(int) substr($key, strrpos($key, '.') + 1)] = $value;
        }

        return $map;
    }
}
