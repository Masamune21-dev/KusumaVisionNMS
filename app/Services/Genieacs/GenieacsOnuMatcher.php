<?php

namespace App\Services\Genieacs;

/**
 * Mencocokkan device GenieACS (TR-069) dengan posisi ONU di NMS.
 *
 * Kelas ini sengaja MURNI — tidak menyentuh HTTP maupun database — supaya
 * aturan pencocokannya bisa diuji langsung. Pengambilan data ada di
 * {@see GenieacsDeviceSyncService}.
 *
 * DUA KUNCI, diukur pada armada nyata (22 Sep 2026, 2.189 device ACS vs 3.011
 * ONU NMS):
 *
 *  1. SERIAL sama persis — 856 pasangan. Berlaku untuk C-Data (`CDTC…`), ZICG,
 *     dan sebagian ZTE yang melaporkan serial GPON lewat TR-069.
 *
 *  2. MAC dengan toleransi ±1 — 608 pasangan tambahan. MAC PON yang dilaporkan
 *     ONU dan MAC yang dilihat OLT berselisih tepat satu pada byte terakhir
 *     (mis. serial `CDTCAF0012E6` berpasangan dengan PonMac `d0:5f:af:00:12:e7`).
 *     Toleransi sengaja dikunci di ±1: pengukuran menunjukkan hasilnya berhenti
 *     bertambah di ±2 dan ±3, jadi melonggarkannya hanya menambah risiko salah
 *     pasang tanpa menambah cakupan.
 *
 * Sisanya dibiarkan TIDAK tercocok. Menebak pasangan ONU berarti menampilkan
 * data pelanggan lain, jadi kunci yang ambigu (satu serial/MAC dipakai lebih
 * dari satu ONU) sengaja dibuang, bukan dipilih salah satunya.
 */
class GenieacsOnuMatcher
{
    /** Selisih byte terakhir yang masih dianggap perangkat yang sama. */
    private const MAC_OFFSETS = [0, -1, 1];

    /**
     * Serial dibakukan huruf besar tanpa spasi. Null bila kosong.
     */
    public static function normalizeSerial(?string $serial): ?string
    {
        $serial = strtoupper(trim((string) $serial));

        return $serial === '' ? null : $serial;
    }

    /**
     * MAC dibakukan jadi 12 digit heksadesimal huruf kecil tanpa pemisah.
     * Null bila bukan MAC yang utuh.
     */
    public static function normalizeMac(?string $mac): ?string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $mac) ?? '');

        return strlen($hex) === 12 ? $hex : null;
    }

    /**
     * Susun indeks lookup dari daftar ONU NMS (bentuk OnuInventoryService::normalize()).
     *
     * Kunci yang muncul lebih dari sekali ditandai ambigu dan TIDAK dipakai.
     *
     * @param  array<int, array<string, mixed>>  $onus
     * @return array{serial: array<string, array<string, int>|null>, mac: array<string, array<string, int>|null>, position: array<string, array<string, int>>}
     */
    public function buildIndex(array $onus): array
    {
        $serial = [];
        $mac = [];
        $positions = [];
        // Semua pemegang tiap kunci (termasuk yang ambigu) + status online — HANYA dipakai
        // pin manual, lihat resolveRef(). Pencocokan otomatis tetap membuang kunci ambigu.
        $candidates = ['serial' => [], 'mac' => []];

        foreach ($onus as $onu) {
            $pos = [
                'snmp_olt_id' => (int) ($onu['olt_id'] ?? 0),
                'slot' => (int) ($onu['slot'] ?? 0),
                'port' => (int) ($onu['port'] ?? 0),
                'onu_id' => (int) ($onu['onu_id'] ?? 0),
            ];

            if ($pos['snmp_olt_id'] === 0) {
                continue;
            }

            $positions[self::positionKey($pos)] = $pos;
            $online = (bool) ($onu['online'] ?? false);

            $rawSerial = $onu['serial_number'] ?? null;

            if ($key = self::normalizeSerial($rawSerial)) {
                $this->put($serial, $key, $pos);
                $candidates['serial'][$key][self::positionKey($pos)] = [$pos, $online];
            }

            // OLT EPON menaruh MAC pada kolom serial ("D0:5F:AF:00:56:2F").
            // Dikenali dari pemisah titik dua supaya serial vendor seperti
            // "D05FAF0012E6" — yang kebetulan 12 digit heksadesimal — tidak
            // ikut tertarik ke indeks MAC.
            if (is_string($rawSerial) && str_contains($rawSerial, ':')) {
                if ($key = self::normalizeMac($rawSerial)) {
                    $this->put($mac, $key, $pos);
                    $candidates['mac'][$key][self::positionKey($pos)] = [$pos, $online];
                }
            }

            if ($key = self::normalizeMac($onu['mac'] ?? null)) {
                $this->put($mac, $key, $pos);
                $candidates['mac'][$key][self::positionKey($pos)] = [$pos, $online];
            }
        }

        return ['serial' => $serial, 'mac' => $mac, 'position' => $positions, 'candidates' => $candidates];
    }

    /**
     * Cari posisi ONU untuk satu device GenieACS.
     *
     * @param  array{serial?: ?string, pon_mac?: ?string}  $device
     * @param  array{serial: array<string, array<string, int>|null>, mac: array<string, array<string, int>|null>}  $index
     * @return array{position: array<string, int>, method: string}|null
     */
    public function match(array $device, array $index): ?array
    {
        if ($serial = self::normalizeSerial($device['serial'] ?? null)) {
            $hit = $index['serial'][$serial] ?? null;
            if ($hit !== null) {
                return ['position' => $hit, 'method' => 'serial'];
            }
        }

        if ($mac = self::normalizeMac($device['pon_mac'] ?? null)) {
            foreach ($this->macCandidates($mac) as $candidate) {
                $hit = $index['mac'][$candidate] ?? null;
                if ($hit !== null) {
                    return ['position' => $hit, 'method' => 'mac'];
                }
            }
        }

        return null;
    }

    /**
     * Kunci posisi kanonik "oltId.slot.port.onuId".
     *
     * @param  array<string, int>  $position
     */
    public static function positionKey(array $position): string
    {
        return "{$position['snmp_olt_id']}.{$position['slot']}.{$position['port']}.{$position['onu_id']}";
    }

    /**
     * Terjemahkan identitas yang disematkan operator menjadi posisi ONU SEKARANG.
     *
     * Inilah yang membuat pin manual tahan ONU pindah port: yang disimpan
     * identitasnya, posisinya dihitung ulang tiap sinkronisasi. Null berarti
     * identitas itu tak ada lagi di inventori — ONU diganti atau dicabut.
     *
     * @param  array{serial: array, mac: array, position: array}  $index
     * @return array<string, int>|null
     */
    public function resolveRef(string $type, string $ref, array $index): ?array
    {
        $key = match ($type) {
            'serial' => self::normalizeSerial($ref),
            'mac' => self::normalizeMac($ref),
            default => null,
        };

        if ($type === 'position') {
            return $index['position'][$ref] ?? null;
        }

        if ($key === null) {
            return null;
        }

        if (($hit = $index[$type][$key] ?? null) !== null) {
            return $hit;
        }

        // Identitas yang disematkan operator dipegang >1 ONU — kasus nyata: ONU EPON pindah
        // OLT dan registrasi lamanya masih tertinggal (offline) di OLT asal. Operator sudah
        // memilih perangkatnya, jadi ikuti SATU-SATUNYA pemegang yang online. Kalau tak ada
        // atau lebih dari satu yang online, tetap lepas — lebih aman daripada menebak.
        $online = array_values(array_filter(
            $index['candidates'][$type][$key] ?? [],
            fn (array $candidate) => $candidate[1],
        ));

        return count($online) === 1 ? $online[0][0] : null;
    }

    /**
     * MAC itu sendiri plus tetangga ±1 pada byte terakhir.
     *
     * @return array<int, string>
     */
    private function macCandidates(string $mac): array
    {
        $value = hexdec($mac);
        $candidates = [];

        foreach (self::MAC_OFFSETS as $offset) {
            $shifted = $value + $offset;

            if ($shifted < 0 || $shifted > 0xFFFFFFFFFFFF) {
                continue;
            }

            $candidates[] = str_pad(dechex((int) $shifted), 12, '0', STR_PAD_LEFT);
        }

        return $candidates;
    }

    /**
     * Daftarkan kunci; kunci yang berulang ditandai ambigu dengan null.
     *
     * @param  array<string, array<string, int>|null>  $bucket
     * @param  array<string, int>  $position
     */
    private function put(array &$bucket, string $key, array $position): void
    {
        if (! array_key_exists($key, $bucket)) {
            $bucket[$key] = $position;

            return;
        }

        // Kunci yang sama menunjuk ONU berbeda → ambigu, jangan dipakai.
        if ($bucket[$key] !== $position) {
            $bucket[$key] = null;
        }
    }
}
