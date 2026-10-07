<?php

namespace App\Services\Genieacs;

use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use App\Services\OnuInventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Menyematkan pasangan ONU↔device GenieACS secara manual.
 *
 * KENAPA PERLU: pencocokan otomatis hanya mengenali serial yang sama persis dan
 * MAC ±1. Sebagian ONU — terutama ZTE yang tak melaporkan serial GPON lewat
 * TR-069 — tidak punya satu pun jembatan itu. Untuk ONU seperti itu satu-satunya
 * yang tahu pasangannya adalah teknisi yang memasangnya.
 *
 * YANG DISIMPAN ADALAH IDENTITAS, BUKAN POSISI. Ini bedanya dengan rancangan
 * pertama, dan bedanya penting:
 *
 *  - ONU dipindah ke port lain → pin yang menyimpan posisi tertinggal di port
 *    lama dan menunjuk pelanggan yang salah. Pin yang menyimpan serial ikut
 *    berpindah sendiri, karena posisinya diturunkan ulang tiap sinkronisasi.
 *  - ONU diganti unit baru → serial lama lenyap dari inventori, pinnya ditandai
 *    basi (`manual_stale`) dan pasangannya dilepas, bukan diam-diam menunjuk
 *    ONU baru milik pelanggan yang sama sekali berbeda.
 *
 * Urutan identitas: serial → MAC → posisi. Posisi hanya dipakai bila ONU tak
 * melaporkan keduanya, dan memang tidak tahan pindah port — itu batas yang
 * disadari, bukan kelalaian.
 */
class GenieacsManualPinService
{
    public function __construct(
        private readonly OnuInventoryService $inventory,
        private readonly GenieacsOnuMatcher $matcher,
    ) {}

    /**
     * Daftar device ACS untuk pemilih manual.
     *
     * Dibaca dari tabel lokal saja — tak memanggil NBI, jadi aman dipanggil
     * saat pengguna mengetik. Kolom `pppoe_username` & `tr069_ip` ada di sini
     * justru untuk ini: nama secret PPPoE itulah yang dikenali teknisi, bukan
     * device id GenieACS yang berupa string panjang.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(?string $term, int $limit = 25): array
    {
        $term = trim((string) $term);

        $rows = GenieacsDeviceMap::query()
            // Yang sudah berpasangan hanya tampil bila OLT-nya terlihat oleh
            // pengguna ini (PartnerOltScope) — operator ber-penugasan tak ikut
            // melihat pelanggan OLT yang bukan bagiannya.
            ->where(fn ($q) => $q->whereNull('snmp_olt_id')
                ->orWhereIn('snmp_olt_id', SnmpOlt::query()->select('id')))
            ->when($term !== '', function ($query) use ($term) {
                // `LOWER(kolom) LIKE ?` — bukan `ilike` — karena test berjalan di
                // sqlite sedangkan produksi PostgreSQL; teknisi mengetik
                // "budi" sementara serial tersimpan huruf besar.
                $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $term)).'%';

                $query->where(function ($q) use ($like, $term) {
                    foreach (['pppoe_username', 'serial_number', 'device_id', 'tr069_ip'] as $column) {
                        $q->orWhereRaw("LOWER({$column}) LIKE ?", [$like]);
                    }

                    // MAC dicari tanpa pemisah: yang tersimpan "d05faf0012e6",
                    // yang diketik bisa "D0:5F:AF". Potongan pendek diabaikan
                    // supaya tidak mencocokkan seluruh tabel.
                    $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $term));

                    if (strlen($hex) >= 4) {
                        $q->orWhere('pon_mac', 'like', "%{$hex}%");
                    }
                });
            })
            // Yang belum berpasangan didahulukan: itu yang dicari saat menyemat.
            ->orderByRaw('CASE WHEN onu_id IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('last_inform_at')
            ->limit(max(1, min($limit, 100)))
            ->get();

        $threshold = now()->subSeconds(GenieacsMapService::ONLINE_THRESHOLD_SECONDS);
        $oltNames = SnmpOlt::query()
            ->whereIn('id', $rows->pluck('snmp_olt_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return $rows->map(fn (GenieacsDeviceMap $row) => [
            'device_id' => $row->device_id,
            'serial_number' => $row->serial_number,
            'pon_mac' => $row->pon_mac,
            'pppoe_username' => $row->pppoe_username,
            'tr069_ip' => $row->tr069_ip,
            'product_class' => $row->product_class,
            'manufacturer' => $row->manufacturer,
            'last_inform_at' => $row->last_inform_at?->toIso8601String(),
            'online' => $row->last_inform_at !== null && $row->last_inform_at->greaterThan($threshold),
            'match_method' => $row->match_method,
            'manual_stale' => (bool) $row->manual_stale,
            // Sudah dipakai ONU lain atau belum — supaya operator tidak
            // memindahkan pasangan pelanggan lain tanpa sadar.
            'linked_to' => $row->isMatched() ? [
                'olt_id' => $row->snmp_olt_id,
                'olt_name' => $oltNames[$row->snmp_olt_id] ?? null,
                'slot' => $row->slot,
                'port' => $row->port,
                'onu_id' => $row->onu_id,
            ] : null,
        ])->all();
    }

    /**
     * Sematkan satu device ACS ke satu ONU.
     *
     * @return array<string, mixed>
     */
    public function pin(string $deviceId, SnmpOlt $olt, int $slot, int $port, int $onuId, ?int $userId): array
    {
        // Katalog ACS hanya dipasangkan ke OLT global non-demo — OLT privat
        // partner dan OLT demo tidak memakai ACS di Pengaturan.
        if (! GenieacsDeviceSyncService::isEligibleOlt($olt)) {
            return ['ok' => false, 'error' => 'olt_not_eligible'];
        }

        $device = GenieacsDeviceMap::query()->where('device_id', $deviceId)->first();

        if (! $device) {
            return ['ok' => false, 'error' => 'device_not_found'];
        }

        // Device yang sedang dipegang OLT yang tak terlihat oleh pengguna ini
        // tidak boleh "diambil": menyematkannya ke ONU sendiri sama dengan
        // mendapat akses baca & ubah WiFi ke ONU pelanggan orang lain.
        if ($device->snmp_olt_id !== null && ! SnmpOlt::query()->whereKey($device->snmp_olt_id)->exists()) {
            return ['ok' => false, 'error' => 'device_not_found'];
        }

        $onu = $this->inventory->findOne($olt, $slot, $port, $onuId);

        if (! $onu) {
            return ['ok' => false, 'error' => 'onu_not_found'];
        }

        [$refType, $ref] = $this->identityOf($onu, $olt->id, $slot, $port, $onuId);

        DB::transaction(function () use ($device, $olt, $slot, $port, $onuId, $refType, $ref, $userId) {
            // Satu posisi ONU hanya boleh dipegang satu device. Kalau posisi ini
            // sedang dipegang device lain — hasil pencocokan otomatis yang meleset,
            // dan justru itu alasan operator menyemat manual — device itu dilepas
            // lebih dulu supaya tak ada dua device menunjuk satu pelanggan.
            GenieacsDeviceMap::query()
                ->where('snmp_olt_id', $olt->id)
                ->where('slot', $slot)
                ->where('port', $port)
                ->where('onu_id', $onuId)
                ->where('device_id', '!=', $device->device_id)
                ->update([
                    'snmp_olt_id' => null, 'slot' => null, 'port' => null, 'onu_id' => null,
                    'match_method' => null, 'matched_at' => null,
                    'manual_ref_type' => null, 'manual_ref' => null, 'manual_stale' => false,
                    'manual_by' => null, 'manual_at' => null,
                    'updated_at' => now(),
                ]);

            $device->forceFill([
                'snmp_olt_id' => $olt->id,
                'slot' => $slot,
                'port' => $port,
                'onu_id' => $onuId,
                'match_method' => GenieacsDeviceMap::METHOD_MANUAL,
                'manual_ref_type' => $refType,
                'manual_ref' => $ref,
                'manual_stale' => false,
                'manual_by' => $userId,
                'manual_at' => now(),
                'matched_at' => now(),
            ])->save();
        });

        return [
            'ok' => true,
            'device_id' => $device->device_id,
            'manual_ref_type' => $refType,
            'manual_ref' => $ref,
        ];
    }

    /**
     * Lepas pasangan sebuah ONU.
     *
     * Device-nya dikembalikan ke pencocokan otomatis — dicoba sekali di sini
     * supaya operator langsung melihat hasilnya, tidak menunggu penjadwal 15
     * menit. Kalau otomatisnya tak menemukan apa pun, barisnya memang jadi
     * tak berpasangan; itu hasil yang benar, bukan kegagalan.
     *
     * @return array<string, mixed>
     */
    public function unpin(int $oltId, int $slot, int $port, int $onuId): array
    {
        $device = GenieacsDeviceMap::query()
            ->where('snmp_olt_id', $oltId)
            ->where('slot', $slot)
            ->where('port', $port)
            ->where('onu_id', $onuId)
            ->first();

        if (! $device) {
            return ['ok' => false, 'error' => 'not_linked'];
        }

        $hit = $this->matcher->match(
            ['serial' => $device->serial_number, 'pon_mac' => $device->pon_mac],
            $this->matcher->buildIndex($this->inventory->collect(GenieacsDeviceSyncService::eligibleOlts())['onus']),
        );

        $device->forceFill([
            'snmp_olt_id' => $hit['position']['snmp_olt_id'] ?? null,
            'slot' => $hit['position']['slot'] ?? null,
            'port' => $hit['position']['port'] ?? null,
            'onu_id' => $hit['position']['onu_id'] ?? null,
            'match_method' => $hit['method'] ?? null,
            'matched_at' => $hit ? now() : null,
            'manual_ref_type' => null,
            'manual_ref' => null,
            'manual_stale' => false,
            'manual_by' => null,
            'manual_at' => null,
        ])->save();

        return [
            'ok' => true,
            'device_id' => $device->device_id,
            // Pencocokan otomatis bisa saja langsung memasangkannya kembali ke
            // ONU yang sama; operator berhak tahu itu, supaya tidak mengira
            // pelepasannya gagal.
            'rematched_to' => $hit ? $hit['position'] : null,
            'rematch_method' => $hit['method'] ?? null,
        ];
    }

    /**
     * Identitas terkuat yang dimiliki sebuah ONU, untuk disematkan.
     *
     * @param  array<string, mixed>  $onu
     * @return array{0: string, 1: string}
     */
    private function identityOf(array $onu, int $oltId, int $slot, int $port, int $onuId): array
    {
        $raw = (string) ($onu['serial_number'] ?? '');
        $serial = GenieacsOnuMatcher::normalizeSerial($raw);

        // OLT EPON menaruh MAC di kolom serial — itu MAC, bukan serial vendor.
        if ($serial !== null && ! str_contains($serial, ':')) {
            return [GenieacsDeviceMap::REF_SERIAL, $serial];
        }

        $mac = GenieacsOnuMatcher::normalizeMac($serial ?? ($onu['mac'] ?? null));

        if ($mac !== null) {
            return [GenieacsDeviceMap::REF_MAC, $mac];
        }

        return [GenieacsDeviceMap::REF_POSITION, GenieacsOnuMatcher::positionKey([
            'snmp_olt_id' => $oltId,
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
        ])];
    }
}
