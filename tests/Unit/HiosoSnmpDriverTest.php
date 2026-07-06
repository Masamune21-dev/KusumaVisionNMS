<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Hioso\HiosoEponSnmpService;
use App\Services\Hioso\HiosoSnmp;
use Tests\TestCase;

/**
 * Stub SNMP HiOSO: kembalikan walk sintetis di-key oleh base-OID yang diminta driver.
 */
class FakeHiosoSnmp extends HiosoSnmp
{
    /**
     * @param  array<string, array<string, string>>  $walks
     * @param  array<string, ?string>  $gets
     */
    public function __construct(private array $walks = [], private array $gets = []) {}

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        return $this->gets[$oid] ?? null;
    }

    public function walk(SnmpOlt $olt, string $oid, int $timeoutUs = 10_000_000, int $retries = 3): array
    {
        return $this->walks[$oid] ?? [];
    }
}

/**
 * Stub SNMP HiOSO lossy: tiap OID punya ANTREAN hasil walk. Walk pertama bisa terpotong (partial),
 * walk berikutnya lebih lengkap; hasil terakhir "menempel" (walk stabil setelahnya). Meniru link WAN
 * yang memutus GETBULK di tengah.
 */
class QueuedHiosoSnmp extends HiosoSnmp
{
    /**
     * @param  array<string, array<int, array<string, string>>>  $queues
     */
    public function __construct(private array $queues = []) {}

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        return null;
    }

    public function walk(SnmpOlt $olt, string $oid, int $timeoutUs = 10_000_000, int $retries = 3): array
    {
        if (! isset($this->queues[$oid]) || $this->queues[$oid] === []) {
            return [];
        }

        return count($this->queues[$oid]) > 1
            ? array_shift($this->queues[$oid])
            : $this->queues[$oid][0];
    }
}

class HiosoSnmpDriverTest extends TestCase
{
    private function olt(): SnmpOlt
    {
        return new SnmpOlt(['snmp_version' => 'v2c']);
    }

    public function test_hioso_driver_parses_inventory_rx_and_offline(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new FakeHiosoSnmp([
            $name => [
                // index = {PON}.{ONU}
                "{$name}.1.1" => 'serlybendokaton',
                "{$name}.1.7" => 'offline-onu',
            ],
            $mac => [
                "{$mac}.1.1" => 'ec237bd78071',
                "{$mac}.1.7" => 'd05fafd2a10d',
            ],
            $rx => [
                "{$rx}.1.1" => '-20.36',
                "{$rx}.1.7" => 'na', // offline / no signal
            ],
        ]);

        $onus = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($this->olt());

        $this->assertCount(2, $onus);

        [$a, $b] = $onus;
        // Online ONU dengan Rx valid
        $this->assertSame([1, 1, 1], [$a['slot'], $a['port'], $a['onu_id']]);
        $this->assertSame('epon 0/1/1:1', $a['interface']);
        $this->assertSame('serlybendokaton', $a['name']);
        $this->assertSame('EC:23:7B:D7:80:71', $a['mac']);
        $this->assertSame('EC:23:7B:D7:80:71', $a['serial_number']); // EPON: identitas = MAC
        $this->assertTrue($a['online']);
        $this->assertSame('Online', $a['phase_state']);
        $this->assertSame(-20.36, $a['rx_power_dbm']);
        $this->assertSame('snmp', $a['rx_power_source']);

        // Offline ONU (Rx "na") — tetap punya MAC, tapi tak online & Rx null
        $this->assertSame([1, 1, 7], [$b['slot'], $b['port'], $b['onu_id']]);
        $this->assertSame('D0:5F:AF:D2:A1:0D', $b['mac']);
        $this->assertFalse($b['online']);
        $this->assertSame('Offline', $b['phase_state']);
        $this->assertNull($b['rx_power_dbm']);
    }

    public function test_hioso_driver_ignores_zero_and_out_of_range_rx(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new FakeHiosoSnmp([
            $name => ["{$name}.2.3" => 'cust'],
            $mac => ["{$mac}.2.3" => 'ec237bd78071'],
            $rx => ["{$rx}.2.3" => '0'], // 0 = no signal
        ]);

        $onu = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($this->olt())[0];

        $this->assertSame([1, 2, 3], [$onu['slot'], $onu['port'], $onu['onu_id']]);
        $this->assertNull($onu['rx_power_dbm']);
        $this->assertFalse($onu['online']);
    }

    /**
     * Regresi: saat polling terjadwal, walk RX/Nama sering terpotong sehingga sebagian ONU tampak
     * offline & tanpa Rx. robustWalk harus mengulang walk (target = kunci ONU dari tabel MAC yang
     * andal) sampai semua ONU ter-cover — sehingga hasil sama lengkapnya dengan refresh manual.
     */
    public function test_robust_walk_recovers_rx_and_status_from_partial_walks(): void
    {
        $name = '1.3.6.1.4.1.25355.3.2.6.3.2.1.37.1';
        $mac = '1.3.6.1.4.1.25355.3.2.6.3.2.1.11.1';
        $rx = '1.3.6.1.4.1.25355.3.2.6.14.2.1.8.1';

        $snmp = new QueuedHiosoSnmp([
            // MAC stabil → semua ONU selalu terload (sesuai gejala: ONU lengkap, Rx/status yang bolong).
            $mac => [[
                "{$mac}.1.1" => 'ec237bd78071',
                "{$mac}.1.7" => 'd05fafd2a10d',
            ]],
            // Nama: walk pertama terpotong (hanya 1.1), walk kedua lengkap.
            $name => [
                ["{$name}.1.1" => 'onu-a'],
                ["{$name}.1.1" => 'onu-a', "{$name}.1.7" => 'onu-b'],
            ],
            // Rx: walk pertama terpotong (hanya 1.1) → tanpa robustWalk, ONU 1.7 salah tampak offline.
            $rx => [
                ["{$rx}.1.1" => '-20.36'],
                ["{$rx}.1.1" => '-20.36', "{$rx}.1.7" => '-24.10'],
            ],
        ]);

        $onus = (new HiosoEponSnmpService($snmp))->getRegisteredOnus($this->olt());

        $this->assertCount(2, $onus);
        [$a, $b] = $onus;

        $this->assertSame([1, 1], [$a['port'], $a['onu_id']]);
        $this->assertTrue($a['online']);
        $this->assertSame(-20.36, $a['rx_power_dbm']);

        // ONU 1.7: walk Rx pertama tak memuatnya → harus dipulihkan walk kedua, bukan tercatat offline.
        $this->assertSame([1, 7], [$b['port'], $b['onu_id']]);
        $this->assertTrue($b['online'], 'ONU 1.7 harus online setelah robustWalk memulihkan Rx yang terpotong');
        $this->assertSame(-24.10, $b['rx_power_dbm']);
        $this->assertSame('onu-b', $b['name']);
    }
}
