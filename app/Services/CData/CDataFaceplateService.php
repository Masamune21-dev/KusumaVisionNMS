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
 */
class CDataFaceplateService
{
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_OPER = '1.3.6.1.2.1.2.2.1.8';

    private const IF_ADMIN = '1.3.6.1.2.1.2.2.1.7';

    // Tabel device/card enterprise (GPON FlashV3): identitas perangkat.
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

        foreach ($descrs as $oid => $label) {
            $idx = substr($oid, strrpos($oid, '.') + 1);
            $oper = (int) ($opers[self::IF_OPER.'.'.$idx] ?? 0);
            $admin = (int) ($admins[self::IF_ADMIN.'.'.$idx] ?? 1);
            $status = $admin === 2 ? 'shutdown' : ($oper === 1 ? 'up' : 'down');

            if (preg_match('/^(epon|gpon)\s+(\d+)\/(\d+)\/(\d+)/i', $label, $m)) {
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
        // Grup PON dulu (utama), di-subgrup per frame/slot, urut.
        ksort($pon, SORT_NATURAL);
        foreach ($pon as $key => $ports) {
            usort($ports, fn ($a, $b) => $a['pos'] <=> $b['pos']);
            $groups[] = ['key' => 'pon-'.$key, 'label' => 'PON '.$key, 'kind' => 'fiber', 'ports' => $ports];
        }
        if ($ge !== []) {
            usort($ge, fn ($a, $b) => $a['pos'] <=> $b['pos']);
            $groups[] = ['key' => 'ge', 'label' => 'GE', 'kind' => 'copper', 'ports' => $ge];
        }
        if ($xge !== []) {
            usort($xge, fn ($a, $b) => $a['pos'] <=> $b['pos']);
            $groups[] = ['key' => 'xge', 'label' => 'XGE', 'kind' => 'fiber', 'ports' => $xge];
        }

        return [
            'device' => array_filter([
                // Kolom `.2.1.1.2.1`: model produk bersih di GPON (`FD1608S-…`); di EPON berisi
                // NAMA device fixed-width null-padded (balik sbg Hex-STRING) → buang, bukan model.
                'model' => $this->productModel($this->snmp->get($olt, self::DEV_MODEL)),
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
                ['key' => 'sys', 'label' => 'SYS', 'state' => 'up'],
                ['key' => 'alm', 'label' => 'ALM', 'state' => 'off'],
                ['key' => 'mgmt', 'label' => 'MGMT', 'state' => 'up'],
            ],
        ];
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
