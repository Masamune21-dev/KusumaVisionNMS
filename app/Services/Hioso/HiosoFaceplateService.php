<?php

namespace App\Services\Hioso;

use App\Models\SnmpOlt;
use Throwable;

/**
 * Faceplate (panel depan) OLT HiOSO / V-Sol HA7304.
 *
 * SNMP HiOSO hanya meng-expose 8 interface (ifType 117/1G semua): `Pon-Nni1..4` (PON) & `G1..G4`
 * (uplink) — TIDAK membedakan SFP vs LAN, dan TIDAK meng-expose MGMT/Console. Jadi layout panel
 * fisik HA7304 di-hardcode sesuai perangkat: **4 PON (fiber) + 2 SFP (fiber) + 2 GE (copper) +
 * MGMT + Console**. Status PON diambil dari status port hasil turunan scanner (ONU online, karena
 * ifOperStatus Pon-Nni tak reliable — guide §4.2); status uplink G dari ifOperStatus (reliable).
 *
 * Berdiri sendiri (transport {@see HiosoSnmp}). Menghasilkan struktur `panel` yang divisualkan
 * `Components/CDataOlt/OltFaceplate.vue`.
 */
class HiosoFaceplateService
{
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_OPER = '1.3.6.1.2.1.2.2.1.8';

    private const OLT_FIRMWARE = '1.3.6.1.4.1.25355.3.1.8.1.1.2.1';

    /**
     * Pembagian uplink G1..G4 → tipe fisik (asumsi HA7304; bisa dibalik bila panel berbeda).
     * G1/G2 = GE RJ45 (copper), G3/G4 = SFP (fiber).
     */
    private const GE_UPLINKS = [1, 2];

    private const SFP_UPLINKS = [3, 4];

    public function __construct(private readonly HiosoSnmp $snmp) {}

    /**
     * @param  array<int, array<string, mixed>>  $ports  port PON dgn status turunan dari scanner
     * @return array<string, mixed>|null
     */
    public function build(SnmpOlt $olt, array $ports): ?array
    {
        try {
            $descrs = $this->snmp->walk($olt, self::IF_DESCR);
        } catch (Throwable) {
            return null;
        }

        if ($descrs === []) {
            return null;
        }

        // Status uplink G{n} dari ifOperStatus (reliable untuk port ethernet fisik).
        $opers = $this->safeWalk($olt, self::IF_OPER);
        $uplink = [];
        foreach ($descrs as $oid => $label) {
            if (preg_match('/^G(\d+)$/i', trim((string) $label), $m)) {
                $idx = substr($oid, strrpos($oid, '.') + 1);
                $uplink[(int) $m[1]] = ((int) ($opers[self::IF_OPER.'.'.$idx] ?? 0)) === 1 ? 'up' : 'down';
            }
        }

        // PON: pakai status turunan scanner (up bila ada ONU online). 'unknown'/empty → tampil down.
        $ponPorts = [];
        foreach ($ports as $p) {
            $ponPorts[] = [
                'pos' => (int) ($p['port'] ?? 0),
                'name' => (string) ($p['name'] ?? ('PON '.($p['port'] ?? '?'))),
                'status' => ($p['oper_status'] ?? null) === 'up' ? 'up' : 'down',
            ];
        }
        usort($ponPorts, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        $sfp = array_map(fn (int $n) => ['pos' => $n, 'name' => "G{$n}", 'status' => $uplink[$n] ?? 'down'], self::SFP_UPLINKS);
        $ge = array_map(fn (int $n) => ['pos' => $n, 'name' => "G{$n}", 'status' => $uplink[$n] ?? 'down'], self::GE_UPLINKS);

        $groups = [];
        if ($ponPorts !== []) {
            $groups[] = ['key' => 'pon', 'label' => 'PON', 'kind' => 'fiber', 'ports' => $ponPorts];
        }
        $groups[] = ['key' => 'sfp', 'label' => 'SFP', 'kind' => 'fiber', 'ports' => $sfp];
        $groups[] = ['key' => 'ge', 'label' => 'GE', 'kind' => 'copper', 'ports' => $ge];

        return [
            'device' => $this->deviceInfo($olt),
            'groups' => $groups,
            'leds' => [
                ['key' => 'sys', 'label' => 'SYS', 'state' => 'up'],
                ['key' => 'alm', 'label' => 'ALM', 'state' => 'off'],
                ['key' => 'mgmt', 'label' => 'MGMT', 'state' => 'up'],
            ],
            // Port fisik di luar SNMP (RJ45) — digambar statis di faceplate.
            'fixed_ports' => [
                ['label' => 'MGMT', 'num' => 'M'],
                ['label' => 'CONSOLE', 'num' => 'C'],
            ],
        ];
    }

    /**
     * Identitas device dari signature firmware `1.0.0.1/HA7304/SN2018-03-00007` (guide §4.1).
     *
     * @return array<string, string>
     */
    private function deviceInfo(SnmpOlt $olt): array
    {
        $fw = HiosoValue::clean($this->snmp->get($olt, self::OLT_FIRMWARE));
        if ($fw === null) {
            return [];
        }

        $parts = explode('/', $fw);

        return array_filter([
            'sw_version' => $parts[0] ?? null,
            'model' => $parts[1] ?? null,
            'serial' => isset($parts[2]) ? preg_replace('/^SN/i', '', $parts[2]) : null,
        ], fn ($v) => $v !== null && $v !== '');
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
