<?php

namespace App\Services\CData;

use App\Models\SnmpOlt;
use Throwable;

/**
 * Kumpulkan layout panel-depan (faceplate) OLT C-Data dari IF-MIB: enumerasi SEMUA port fisik
 * (PON, GE uplink, XGE uplink), klasifikasi per grup + status oper/admin, plus identitas device
 * (model/serial/versi) bila tersedia (tabel enterprise `17409.2.3.1.*`, ada di GPON FlashV3).
 *
 * Murni SNMP read (v1/v2c) — sama untuk EPON & GPON. Dipakai oleh {@see CDataOltScanner} untuk
 * mengisi cache `last_test_result.panel`, lalu divisualkan di `Components/CDataOlt/OltFaceplate.vue`.
 *
 * Port di-klasifikasi dari nama `ifDescr`:
 *   epon/gpon 0/<frame>/<slot>  → grup PON (fiber), di-subgrup per frame/slot
 *   ge  0/<f>/<n>               → uplink GE (copper)
 *   xge 0/<f>/<n>               → uplink XGE (fiber)
 * Status: oper=1 → up · admin=2 → shutdown · selain itu → down.
 *
 * Tata letak fisik (urutan blok, port bertumpuk, kartu/modul) mengikuti panel depan yang
 * terverifikasi dari foto & datasheet (26 Sep 2026):
 *   - EPON 8-PON (FD1208S-R1 family, serial AF2802-…): kartu KIRI = modul ekspansi PON 0/2
 *     (4 SFP); kartu KANAN = papan utama PON 0/1 (4 SFP) · GE 1-4 RJ45 sebaris · XGE 1-4 SFP
 *     bertumpuk 2×2 · CONSOLE (atas) / MGMT (bawah) · LED. Slot terkecil = papan utama (di
 *     kanan, bersama uplink); slot lebih besar = kartu tambahan di kirinya (konfirmasi user
 *     26 Sep 2026 dari unit fisik).
 *   - GPON FD1608S-B1: PON 1-8 SFP (jeda tiap 4) · COMBO GE 1-4 SFP · XGE 1-2 SFP ·
 *     COMBO GE 1-4 RJ45 bertumpuk 2×2 · CONSOLE/MGMT · LED. Port GE combo = satu port logis
 *     dengan dua konektor, jadi statusnya sama di kedua blok.
 *   - GPON ringkas FD1601S-B1 / FD1602S-B1 (≤ 2 PON, datasheet + foto 28 Sep 2026): PON SFP ·
 *     GE 1-2 RJ45 sebaris (bukan combo) · 10GE SFP+ · CONSOLE saja (tanpa MGMT).
 *   Konvensi C-Data untuk port bertumpuk (label `1▼▲2`): genap di ATAS, ganjil di BAWAH.
 *
 * Struktur grup: `rows` (1 = sebaris, 2 = bertumpuk, urutan port kolom demi kolom dari atas),
 * `chunk` (jeda visual tiap N port), `module` (nomor kartu; grup se-modul digambar dalam satu
 * kartu). Port dengan `fixed: true` adalah konektor non-SNMP (CONSOLE/MGMT) tanpa status.
 */
class CDataFaceplateService
{
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_OPER = '1.3.6.1.2.1.2.2.1.8';

    private const IF_ADMIN = '1.3.6.1.2.1.2.2.1.7';

    // Tabel device/card enterprise (GPON FlashV3): identitas perangkat. `.3.1` = model produk di
    // semua C-Data yang diuji (FD1608S-B1-NDA0, FD1601S-B1, FD1304E); `.2.1` = nama/hostname device
    // (kebetulan sama dgn model di FD1608S yang hostname-nya bawaan) — hanya cadangan.
    private const DEV_PRODUCT = '1.3.6.1.4.1.17409.2.3.1.2.1.1.3.1';

    private const DEV_MODEL = '1.3.6.1.4.1.17409.2.3.1.2.1.1.2.1';

    private const DEV_VENDOR = '1.3.6.1.4.1.17409.2.3.1.2.1.1.10.1';

    private const DEV_HW = '1.3.6.1.4.1.17409.2.3.1.3.1.1.7.1.0';

    private const DEV_SW = '1.3.6.1.4.1.17409.2.3.1.3.1.1.8.1.0';

    private const DEV_SERIAL = '1.3.6.1.4.1.17409.2.3.1.3.1.1.12.1.0';

    private const DEV_TYPE = '1.3.6.1.4.1.17409.2.3.1.3.1.1.14.1.0';

    public function __construct(private readonly CDataSnmp $snmp) {}

    /**
     * @return array<string, mixed>|null null bila OLT tak terbaca SNMP
     */
    public function collect(SnmpOlt $olt): ?array
    {
        try {
            $descrs = $this->snmp->walk($olt, self::IF_DESCR);
        } catch (Throwable) {
            return null;
        }

        if ($descrs === []) {
            return null;
        }

        $opers = $this->safeWalk($olt, self::IF_OPER);
        $admins = $this->safeWalk($olt, self::IF_ADMIN);

        $pon = [];     // di-subgrup per "frame/slot"
        $ge = [];
        $xge = [];
        $isGpon = false;

        foreach ($descrs as $oid => $label) {
            $idx = substr($oid, strrpos($oid, '.') + 1);
            $oper = (int) ($opers[self::IF_OPER.'.'.$idx] ?? 0);
            $admin = (int) ($admins[self::IF_ADMIN.'.'.$idx] ?? 1);
            $status = $admin === 2 ? 'shutdown' : ($oper === 1 ? 'up' : 'down');

            if (preg_match('/^(epon|gpon)\s+(\d+)\/(\d+)\/(\d+)/i', $label, $m)) {
                $isGpon = $isGpon || strtolower($m[1]) === 'gpon';
                $pon[(int) $m[2].'/'.(int) $m[3]][] = [
                    'pos' => (int) $m[4],
                    'name' => sprintf('%s 0/%d/%d', strtolower($m[1]), (int) $m[3], (int) $m[4]),
                    'status' => $status,
                ];
            } elseif (preg_match('/^xge\s+\d+\/\d+\/(\d+)/i', $label, $m)) {
                $xge[] = ['pos' => (int) $m[1], 'name' => trim($label), 'status' => $status];
            } elseif (preg_match('/^ge\s+\d+\/\d+\/(\d+)/i', $label, $m)) {
                $ge[] = ['pos' => (int) $m[1], 'name' => trim($label), 'status' => $status];
            }
        }

        $groups = [];
        // Grup PON dulu, di-subgrup per frame/slot. Kartu PON 4-port EPON adalah modul terpisah
        // secara fisik: slot TERBESAR (kartu ekspansi) di kiri = modul 1, …, slot TERKECIL (papan
        // utama) di kanan = modul terakhir, satu kartu dengan uplink/CONSOLE/MGMT/LED.
        krsort($pon, SORT_NATURAL);
        $slotCount = count($pon);
        $module = 1;
        foreach ($pon as $key => $ports) {
            usort($ports, fn ($a, $b) => $a['pos'] <=> $b['pos']);
            $groups[] = [
                'key' => 'pon-'.$key,
                'label' => 'PON '.$key,
                'kind' => 'fiber',
                'rows' => 1,
                'chunk' => 4,
                'module' => $module,
                'ports' => $ports,
            ];
            if ($slotCount > 1 && $module < $slotCount) {
                $module++;
            }
        }

        usort($ge, fn ($a, $b) => $a['pos'] <=> $b['pos']);
        usort($xge, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        // FD1601S/FD1602S: GE RJ45 biasa & hanya CONSOLE — combo + MGMT khusus FD1608S.
        $compactGpon = $isGpon && array_sum(array_map('count', $pon)) <= 2;

        if ($ge !== [] && $isGpon && ! $compactGpon) {
            // FD1608S: 4 SFP combo sebaris, lalu (setelah XGE) 4 RJ45 combo bertumpuk.
            $groups[] = ['key' => 'ge-sfp', 'label' => 'COMBO GE', 'kind' => 'fiber', 'rows' => 1, 'module' => $module, 'ports' => $ge];
        } elseif ($ge !== []) {
            $groups[] = ['key' => 'ge', 'label' => 'GE', 'kind' => 'copper', 'rows' => 1, 'module' => $module, 'ports' => $ge];
        }
        if ($xge !== []) {
            $stacked = count($xge) >= 4;
            $groups[] = [
                'key' => 'xge',
                'label' => 'XGE',
                'kind' => 'fiber',
                'rows' => $stacked ? 2 : 1,
                'module' => $module,
                'ports' => $stacked ? self::stackEvenOnTop($xge) : $xge,
            ];
        }
        if ($ge !== [] && $isGpon && ! $compactGpon) {
            $groups[] = ['key' => 'ge-rj45', 'label' => 'COMBO GE', 'kind' => 'copper', 'rows' => 2, 'module' => $module, 'ports' => self::stackEvenOnTop($ge)];
        }
        // CONSOLE (atas) / MGMT (bawah): konektor RJ45 di luar SNMP. GPON ringkas cuma CONSOLE.
        $console = ['pos' => 'C', 'name' => 'CONSOLE', 'label' => 'CONSOLE', 'status' => 'fixed', 'fixed' => true];
        $groups[] = [
            'key' => 'mgmt',
            'label' => '',
            'kind' => 'copper',
            'rows' => $compactGpon ? 1 : 2,
            'module' => $module,
            'ports' => $compactGpon ? [$console] : [
                $console,
                ['pos' => 'M', 'name' => 'MGMT', 'label' => 'MGMT', 'status' => 'fixed', 'fixed' => true],
            ],
        ];

        return [
            'device' => array_filter([
                // Model produk `.3.1`; cadangan `.2.1` (di EPON berisi NAMA device fixed-width
                // null-padded yang balik sbg Hex-STRING → dibuang, bukan model).
                'model' => $this->productModel($this->snmp->get($olt, self::DEV_PRODUCT))
                    ?? $this->productModel($this->snmp->get($olt, self::DEV_MODEL)),
                'vendor' => $this->snmp->get($olt, self::DEV_VENDOR),
                'hw_version' => $this->snmp->get($olt, self::DEV_HW),
                'sw_version' => $this->snmp->get($olt, self::DEV_SW),
                'serial' => $this->snmp->get($olt, self::DEV_SERIAL),
                'device_type' => $this->snmp->get($olt, self::DEV_TYPE),
            ], fn ($v) => $v !== null && $v !== ''),
            'groups' => $groups,
            // LED dari sinyal nyata: SYS/MGMT hijau karena OLT merespons SNMP. ALM tidak dikarang
            // (tak ada OID alarm-LED terverifikasi) — biarkan off sampai disambung ke alarm engine.
            'leds' => [
                ['key' => 'pwr', 'label' => 'PWR', 'state' => 'up'],
                ['key' => 'sys', 'label' => 'SYS', 'state' => 'up'],
                ['key' => 'alm', 'label' => 'ALM', 'state' => 'off'],
                ['key' => 'mgmt', 'label' => 'MGMT', 'state' => 'up'],
            ],
            // CONSOLE/MGMT kini digambar sebagai blok bertumpuk di dalam grup `mgmt`.
            'fixed_ports' => [],
        ];
    }

    /**
     * Susun port sebaris jadi urutan kolom-demi-kolom untuk blok bertumpuk 2 baris, konvensi
     * C-Data: genap di atas, ganjil di bawah ([1,2,3,4] → [2,1,4,3]).
     *
     * @param  array<int, array<string, mixed>>  $ports  sudah urut `pos`
     * @return array<int, array<string, mixed>>
     */
    public static function stackEvenOnTop(array $ports): array
    {
        $out = [];
        foreach (array_chunk(array_values($ports), 2) as $pair) {
            if (count($pair) === 2) {
                $out[] = $pair[1];
                $out[] = $pair[0];
            } else {
                $out[] = $pair[0];
            }
        }

        return $out;
    }

    /**
     * Hanya pertahankan sebagai "model" bila nilainya string ASCII bersih (mis. `FD1608S-B1-NDA0`).
     * Bila berbentuk Hex-STRING (`4F 4C 54 …`), itu field nama fixed-width null-padded → null.
     */
    private function productModel(?string $value): ?string
    {
        $value = trim((string) $value);

        // Kosong, atau Hex-STRING dump (pasangan hex dipisah/diakhiri spasi, mis. nama null-padded
        // `4F 4C 54 … 00 00 `) → bukan model produk. Model asli (mis. `FD1608S-B1-NDA0`) memuat
        // huruf non-hex sehingga tak cocok pola ini.
        if ($value === '' || preg_match('/^(?:[0-9A-Fa-f]{2}\s*)+$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function safeWalk(SnmpOlt $olt, string $oid): array
    {
        try {
            return $this->snmp->walk($olt, $oid);
        } catch (Throwable) {
            return [];
        }
    }
}
