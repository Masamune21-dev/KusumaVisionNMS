<?php

namespace App\Services\Hioso;

use App\Models\SnmpOlt;
use App\Services\CData\CDataFaceplateService;
use Throwable;

/**
 * Faceplate (panel depan) OLT HiOSO / V-Sol HA7304.
 *
 * SNMP HiOSO hanya meng-expose 8 interface (ifType 117/1G semua): `Pon-Nni1..4` (PON) & `G1..G4`
 * (uplink) — TIDAK membedakan SFP vs LAN, dan TIDAK meng-expose MGMT/Console. Jadi layout panel
 * fisik di-hardcode per model, mengikuti label cetak di panel (foto HA7304, 26 Sep 2026):
 *
 *   HA7304 / HA7304C:  [LED PON] [PON 1-4 SFP] [G1 G2 = SFP] [G4 ⬒ CONSOLE / G3 ⬓ MGMT = blok RJ45
 *                      2×2: kolom kiri G4 (atas) & G3 (bawah), kolom kanan CONSOLE (atas) & MGMT
 *                      (bawah)] [RESET] [LED ALM SYS / MACT PWR].
 *                      → G1/G2 fiber (SFP), G3/G4 tembaga (RJ45). Datasheet hanya menyebut
 *                      "2 SFP + 2 RJ45 uplink" tanpa nomor; nomor diambil dari label foto.
 *   HA7302*:           [PON 1-2 SFP] [G1 G2 RJ45] [CONSOLE]. Belum ada unitnya di NMS — layout
 *                      dari foto produk & datasheet HA7302CST (2 PON, 2 GE RJ45, 1 console).
 *   Model lain:        semua G{n} dari SNMP sebagai RJ45 sebaris + CONSOLE/MGMT bertumpuk.
 *
 * Status PON diambil dari status port hasil turunan scanner (ONU online, karena ifOperStatus
 * Pon-Nni tak reliable — guide §4.2); status uplink G dari ifOperStatus (reliable).
 *
 * Berdiri sendiri (transport {@see HiosoSnmp}). Menghasilkan struktur `panel` yang divisualkan
 * `Components/CDataOlt/OltFaceplate.vue` (kontrak `rows`/`chunk`/`module`/`fixed` sama dengan
 * {@see CDataFaceplateService}).
 */
class HiosoFaceplateService
{
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_OPER = '1.3.6.1.2.1.2.2.1.8';

    private const OLT_FIRMWARE = '1.3.6.1.4.1.25355.3.1.8.1.1.2.1';

    /** HA7304: G1/G2 = SFP (fiber), G3/G4 = RJ45 (copper) — sesuai label panel. */
    private const HA7304_SFP_UPLINKS = [1, 2];

    private const HA7304_GE_UPLINKS = [3, 4];

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
        ksort($uplink);

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

        $device = $this->deviceInfo($olt);
        $model = strtoupper((string) ($device['model'] ?? ''));

        $groups = [];
        if ($ponPorts !== []) {
            $groups[] = ['key' => 'pon', 'label' => 'PON', 'kind' => 'fiber', 'rows' => 1, 'module' => 1, 'ports' => $ponPorts];
        }

        $g = fn (int $n) => ['pos' => $n, 'name' => "G{$n}", 'status' => $uplink[$n] ?? 'down'];
        $console = ['pos' => 'C', 'name' => 'CONSOLE', 'label' => 'CONSOLE', 'status' => 'fixed', 'fixed' => true];
        $mgmt = ['pos' => 'M', 'name' => 'MGMT', 'label' => 'MGMT', 'status' => 'fixed', 'fixed' => true];

        if (str_starts_with($model, 'HA7304')) {
            $groups[] = ['key' => 'sfp', 'label' => 'SFP', 'kind' => 'fiber', 'rows' => 1, 'module' => 1, 'ports' => array_map($g, self::HA7304_SFP_UPLINKS)];
            // Blok RJ45 2×2: kolom kiri G4 (atas) / G3 (bawah), kolom kanan CONSOLE (atas) / MGMT (bawah).
            $groups[] = ['key' => 'ge', 'label' => 'GE', 'kind' => 'copper', 'rows' => 2, 'module' => 1, 'ports' => [$g(4), $g(3), $console, $mgmt]];
        } elseif (str_starts_with($model, 'HA7302')) {
            $known = $uplink === [] ? [1, 2] : array_keys($uplink);
            $groups[] = ['key' => 'ge', 'label' => 'GE', 'kind' => 'copper', 'rows' => 1, 'module' => 1, 'ports' => array_map($g, $known)];
            $groups[] = ['key' => 'mgmt', 'label' => '', 'kind' => 'copper', 'rows' => 1, 'module' => 1, 'ports' => [$console]];
        } else {
            $known = $uplink === [] ? [1, 2, 3, 4] : array_keys($uplink);
            $groups[] = ['key' => 'ge', 'label' => 'GE', 'kind' => 'copper', 'rows' => 1, 'module' => 1, 'ports' => array_map($g, $known)];
            $groups[] = ['key' => 'mgmt', 'label' => '', 'kind' => 'copper', 'rows' => 2, 'module' => 1, 'ports' => [$console, $mgmt]];
        }

        return [
            'device' => $device,
            'groups' => $groups,
            // Urutan seperti cetakan panel HA7304: ALM SYS / MACT PWR. MACT (aktivitas manajemen)
            // menyala karena OLT sedang merespons SNMP; ALM tidak dikarang (tak ada OID alarm-LED).
            'leds' => [
                ['key' => 'alm', 'label' => 'ALM', 'state' => 'off'],
                ['key' => 'sys', 'label' => 'SYS', 'state' => 'up'],
                ['key' => 'mact', 'label' => 'MACT', 'state' => 'up'],
                ['key' => 'pwr', 'label' => 'PWR', 'state' => 'up'],
            ],
            // CONSOLE/MGMT kini bagian dari grup (blok RJ45), bukan daftar terpisah.
            'fixed_ports' => [],
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
