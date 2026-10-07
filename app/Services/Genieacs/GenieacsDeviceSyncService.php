<?php

namespace App\Services\Genieacs;

use App\Models\AcsSetting;
use App\Models\GenieacsCredential;
use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use App\Services\OnuInventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menarik katalog device GenieACS lalu menyimpan hasil pencocokannya ke
 * `genieacs_device_map`.
 *
 * KENAPA HASILNYA DISIMPAN, bukan dihitung saat halaman dirender: tabel ONU
 * dibuka sering, sedangkan pencocokan butuh seluruh katalog ACS. Dengan
 * menyimpannya, halaman ONU cukup satu join dan TIDAK PERNAH memanggil ACS.
 *
 * KENAPA WAJIB PAKAI PROJECTION: diukur pada armada nyata, menarik 2.189 device
 * dengan projection ringan = 688 KB / 0,25 detik. Tanpa projection, seluruh
 * pohon parameter ikut terbawa — sekitar 124 MB dan 19 detik, 180 kali lebih
 * berat. Jangan pernah melepas konstanta {@see self::PROJECTION}.
 */
class GenieacsDeviceSyncService
{
    /**
     * Hanya jalur yang benar-benar dipakai. Tiap jalur tambahan berbiaya nyata:
     * diukur 22 Sep 2026 pada 2.192 device, dasar 761 KB/0,25 dtk dan SETIAP
     * field ekstra menambah ~290 KB. `pppoeUsername` & `IPTR069` diikutkan
     * karena itulah identitas yang dikenali teknisi saat menyematkan pasangan
     * manual (nama secret PPPoE & IP manajemen), keduanya terisi 100%.
     * Kembarannya — `pppoeUsername2` dan `ManagementServer.ConnectionRequestURL`
     * — sengaja TIDAK diambil: terbukti identik di seluruh 2.192 device, jadi
     * hanya menambah 580 KB tanpa informasi baru.
     */
    private const PROJECTION = '_id,_deviceId,_lastInform,VirtualParameters.PonMac'
        .',VirtualParameters.pppoeUsername,VirtualParameters.IPTR069';

    /** Katalog penuh boleh lebih lambat dari request web biasa. */
    private const REQUEST_TIMEOUT = 120;

    private const CONNECT_TIMEOUT = 10;

    public function __construct(
        private readonly OnuInventoryService $inventory,
        private readonly GenieacsOnuMatcher $matcher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(bool $dryRun = false): array
    {
        $startedAt = microtime(true);

        $client = GenieacsCredential::client(self::REQUEST_TIMEOUT, self::CONNECT_TIMEOUT);

        if (! $client) {
            return $this->failure('genieacs_not_configured');
        }

        $response = $client->getDevices([], 0, 0, self::PROJECTION);

        if (! ($response['success'] ?? false)) {
            return $this->failure($response['error'] ?? 'nbi_unreachable');
        }

        $devices = array_values(array_filter(
            array_map(fn ($raw) => $this->readDevice($raw), $response['data'] ?? []),
        ));

        // Indeks dari OLT yang memakai ACS ini SAJA, dibaca tanpa global scope
        // (self::eligibleOlts). Kalau diambil dari OLT yang terlihat oleh pemicu,
        // tarik-ulang dari akun ter-scope melepas semua pasangan OLT lain —
        // padahal hasilnya ditulis ke seluruh katalog. Dan OLT privat milik
        // partner tidak pernah ikut: MAC ±1 yang kebetulan cocok akan memberi
        // partner akses baca & ubah WiFi ONU pelanggan yang bukan miliknya.
        $index = $this->matcher->buildIndex($this->inventory->collect(self::eligibleOlts())['onus']);
        $manual = $this->manualRows();

        [$rows, $stats] = $this->resolve($devices, $index, $manual);

        if (! $dryRun) {
            $this->persist($rows, array_column($devices, 'device_id'));
        }

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ...$stats,
        ];
    }

    /**
     * OLT yang ONU-nya boleh dipasangkan dengan katalog ACS: OLT global (tanpa
     * pemilik partner) yang bukan demo. ACS di Pengaturan milik staf Pusat, jadi
     * OLT privat partner dan OLT demo tidak pernah ikut.
     *
     * Dibaca TANPA `PartnerOltScope`/`DemoScope` lalu disaring eksplisit, supaya
     * hasil pencocokan sama persis dari mana pun sinkronisasi dipicu (konsol,
     * atau tombol tarik-ulang dari akun mana pun).
     *
     * @return Collection<int, SnmpOlt>
     */
    public static function eligibleOlts(): Collection
    {
        return SnmpOlt::query()
            ->withoutGlobalScopes()
            ->whereNull('owner_user_id')
            ->where('is_demo', false)
            ->orderBy('name')
            ->get();
    }

    /**
     * OLT ini memakai katalog ACS? Lihat {@see self::eligibleOlts()}. Aturannya
     * satu dengan target CWMP ({@see AcsSetting::servesOlt()}).
     */
    public static function isEligibleOlt(SnmpOlt $olt): bool
    {
        return AcsSetting::servesOlt($olt);
    }

    /**
     * Ambil hanya field yang dipakai dari satu dokumen device.
     *
     * @return array<string, mixed>|null
     */
    private function readDevice(mixed $raw): ?array
    {
        if (! is_array($raw) || blank($raw['_id'] ?? null)) {
            return null;
        }

        $deviceId = (string) $raw['_id'];

        return [
            'device_id' => $deviceId,
            'serial' => data_get($raw, '_deviceId._SerialNumber'),
            'manufacturer' => data_get($raw, '_deviceId._Manufacturer'),
            'product_class' => data_get($raw, '_deviceId._ProductClass'),
            'pon_mac' => data_get($raw, 'VirtualParameters.PonMac._value'),
            'pppoe_username' => data_get($raw, 'VirtualParameters.pppoeUsername._value'),
            'tr069_ip' => data_get($raw, 'VirtualParameters.IPTR069._value'),
            'last_inform' => data_get($raw, '_lastInform'),
        ];
    }

    /**
     * Baris yang sudah disematkan operator — tidak boleh ditimpa pencocokan otomatis.
     *
     * @return array<string, GenieacsDeviceMap>
     */
    private function manualRows(): array
    {
        return GenieacsDeviceMap::query()
            ->where('match_method', GenieacsDeviceMap::METHOD_MANUAL)
            ->get()
            ->keyBy('device_id')
            ->all();
    }

    /**
     * Tentukan posisi tiap device, lalu buang posisi yang diperebutkan.
     *
     * @param  array<int, array<string, mixed>>  $devices
     * @param  array{serial: array<string, ?array>, mac: array<string, ?array>}  $index
     * @param  array<string, GenieacsDeviceMap>  $manual
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, int>}
     */
    private function resolve(array $devices, array $index, array $manual): array
    {
        $rows = [];
        $claims = [];
        $stats = [
            'devices' => count($devices),
            'matched_serial' => 0,
            'matched_mac' => 0,
            'manual' => 0,
            'manual_stale' => 0,
            'unmatched' => 0,
            'conflicts' => 0,
        ];

        foreach ($devices as $device) {
            $row = [
                'device_id' => $device['device_id'],
                'serial_number' => GenieacsOnuMatcher::normalizeSerial($device['serial']),
                'pon_mac' => GenieacsOnuMatcher::normalizeMac($device['pon_mac']),
                'manufacturer' => $this->trimTo($device['manufacturer'], 64),
                'product_class' => $this->trimTo($device['product_class'], 64),
                'pppoe_username' => $this->trimTo($device['pppoe_username'], 100),
                'tr069_ip' => $this->trimTo($device['tr069_ip'], 45),
                'snmp_olt_id' => null,
                'slot' => null,
                'port' => null,
                'onu_id' => null,
                'match_method' => null,
                'manual_ref_type' => null,
                'manual_ref' => null,
                'manual_stale' => false,
                'manual_by' => null,
                'manual_at' => null,
                'matched_at' => null,
                'last_inform_at' => $this->toTimestamp($device['last_inform']),
            ];

            $existing = $manual[$device['device_id']] ?? null;

            if ($existing) {
                // Penetapan operator selalu menang, bahkan atas kecocokan serial.
                //
                // Yang disematkan adalah IDENTITAS ONU, bukan posisinya —
                // posisinya diturunkan ulang di sini setiap sinkronisasi. Jadi
                // ONU yang dipindah ke port lain ikut berpindah sendiri, tanpa
                // operator perlu menyemat ulang.
                [$refType, $ref] = $this->pinRef($existing);
                $position = $ref !== null ? $this->matcher->resolveRef($refType, $ref, $index) : null;

                $row = [...$row,
                    'match_method' => GenieacsDeviceMap::METHOD_MANUAL,
                    'manual_ref_type' => $refType,
                    'manual_ref' => $ref,
                    'manual_by' => $existing->manual_by,
                    'manual_at' => $existing->manual_at,
                    'matched_at' => $existing->matched_at,
                ];

                if ($position !== null) {
                    $row = [...$row, ...$position];
                    $stats['manual']++;
                } else {
                    // Identitasnya tak ada lagi di inventori ONU — biasanya ONU
                    // diganti unit baru atau dicabut. Pasangannya dilepas supaya
                    // tidak menampilkan perangkat pelanggan yang salah, tapi
                    // pinnya DISIMPAN dan ditandai basi: justru itu penanda
                    // bagi operator bahwa ada ONU yang berganti.
                    $row['manual_stale'] = true;
                    $stats['manual_stale']++;
                }
            } else {
                $hit = $this->matcher->match(
                    ['serial' => $device['serial'], 'pon_mac' => $device['pon_mac']],
                    $index,
                );

                if ($hit) {
                    $row = [...$row, ...$hit['position'],
                        'match_method' => $hit['method'],
                        'matched_at' => now(),
                    ];
                    $stats[$hit['method'] === 'serial' ? 'matched_serial' : 'matched_mac']++;
                } else {
                    $stats['unmatched']++;
                }
            }

            if ($key = $this->positionKey($row)) {
                $claims[$key][] = count($rows);
            }

            $rows[] = $row;
        }

        // Satu posisi ONU diklaim lebih dari satu device → keduanya dilepas,
        // kecuali klaim manual. Lebih baik kosong daripada menampilkan
        // perangkat milik pelanggan lain.
        foreach ($claims as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            foreach ($indexes as $position) {
                if ($rows[$position]['match_method'] === GenieacsDeviceMap::METHOD_MANUAL) {
                    continue;
                }

                $method = $rows[$position]['match_method'];
                if ($method === 'serial') {
                    $stats['matched_serial']--;
                } elseif ($method === 'mac') {
                    $stats['matched_mac']--;
                }

                $rows[$position] = [...$rows[$position],
                    'snmp_olt_id' => null,
                    'slot' => null,
                    'port' => null,
                    'onu_id' => null,
                    'match_method' => null,
                    'matched_at' => null,
                ];

                $stats['unmatched']++;
                $stats['conflicts']++;
            }
        }

        return [$rows, $stats];
    }

    /**
     * Identitas yang dipakai menurunkan posisi sebuah pin manual.
     *
     * Baris yang disematkan sebelum pin berbasis identitas ada (hanya menyimpan
     * posisi) tetap dihormati: posisinya dipakai sebagai referensi, jadi tak ada
     * pasangan yang hilang saat fitur ini dipasang.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function pinRef(GenieacsDeviceMap $row): array
    {
        if (filled($row->manual_ref)) {
            return [$row->manual_ref_type ?: GenieacsDeviceMap::REF_SERIAL, (string) $row->manual_ref];
        }

        if ($row->isMatched()) {
            return [GenieacsDeviceMap::REF_POSITION, GenieacsOnuMatcher::positionKey([
                'snmp_olt_id' => (int) $row->snmp_olt_id,
                'slot' => (int) $row->slot,
                'port' => (int) $row->port,
                'onu_id' => (int) $row->onu_id,
            ])];
        }

        return [null, null];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $seenDeviceIds
     */
    private function persist(array $rows, array $seenDeviceIds): void
    {
        DB::transaction(function () use ($rows, $seenDeviceIds) {
            // Operator bisa menyemat ATAU melepas pasangan justru selagi
            // sinkronisasi ini berjalan — daftar pin manual tadi dibaca sebelum
            // katalog ACS ditarik. Kalau baris seperti itu ikut ditimpa, aksi
            // yang baru saja dilakukan lenyap tanpa jejak: pin baru hilang, atau
            // pin yang sudah dilepas hidup lagi.
            //
            // Jadi device yang status manualnya SUDAH BERUBAH di basis data
            // dilewati sepenuhnya. Keadaan di basis data itu yang lebih baru;
            // sinkronisasi berikutnya (15 menit) yang menyegarkan barisnya.
            $manualNow = GenieacsDeviceMap::query()
                ->where('match_method', GenieacsDeviceMap::METHOD_MANUAL)
                ->pluck('device_id')
                ->flip();

            $rows = array_values(array_filter($rows, fn (array $row) => $manualNow->has($row['device_id'])
                === ($row['match_method'] === GenieacsDeviceMap::METHOD_MANUAL)));

            foreach (array_chunk($rows, 500) as $chunk) {
                GenieacsDeviceMap::upsert(
                    array_map(fn (array $row) => [...$row, 'updated_at' => now(), 'created_at' => now()], $chunk),
                    ['device_id'],
                    [
                        'serial_number', 'pon_mac', 'manufacturer', 'product_class',
                        'pppoe_username', 'tr069_ip',
                        'snmp_olt_id', 'slot', 'port', 'onu_id',
                        'match_method', 'manual_ref_type', 'manual_ref', 'manual_stale',
                        'manual_by', 'manual_at',
                        'matched_at', 'last_inform_at', 'updated_at',
                    ],
                );
            }

            // Device yang sudah dihapus dari ACS tidak boleh meninggalkan
            // penanda basi di tabel ONU.
            GenieacsDeviceMap::query()
                ->when($seenDeviceIds !== [], fn ($q) => $q->whereNotIn('device_id', $seenDeviceIds))
                ->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function positionKey(array $row): ?string
    {
        if ($row['snmp_olt_id'] === null || $row['onu_id'] === null) {
            return null;
        }

        return "{$row['snmp_olt_id']}.{$row['slot']}.{$row['port']}.{$row['onu_id']}";
    }

    /**
     * GenieACS mengirim `_lastInform` dalam UTC ("…T12:18:13.751Z"), sedangkan
     * aplikasi ini berjalan di `Asia/Jakarta`. Tanpa konversi, stempelnya
     * tersimpan tertinggal 7 jam dan SETIAP device akan tampak "lama tak
     * inform" walau baru saja melapor.
     */
    private function toTimestamp(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function trimTo(mixed $value, int $length): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'devices' => 0,
            'matched_serial' => 0,
            'matched_mac' => 0,
            'manual' => 0,
            'manual_stale' => 0,
            'unmatched' => 0,
            'conflicts' => 0,
            'duration_ms' => 0,
        ];
    }
}
