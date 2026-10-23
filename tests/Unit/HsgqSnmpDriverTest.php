<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\Hsgq\HsgqSnmp;
use App\Services\Hsgq\HsgqSnmpService;
use App\Services\Hsgq\HsgqValue;
use App\Support\SmartOltSupport;
use Tests\TestCase;

/**
 * Stub SNMP HSGQ: satu peta datar OID → nilai (format keluaran net-snmp). `walk()` mengembalikan
 * semua OID di bawah subtree yang diminta, seperti agen sungguhan; `get()` OID persis.
 */
class FakeHsgqSnmp extends HsgqSnmp
{
    /** @var list<string> */
    public array $walked = [];

    /** @param  array<string, string>  $oids */
    public function __construct(private array $oids = []) {}

    public function get(SnmpOlt $olt, string $oid): ?string
    {
        return isset($this->oids[$oid]) ? HsgqValue::clean($this->oids[$oid]) : null;
    }

    public function walk(SnmpOlt $olt, string $oid): array
    {
        $this->walked[] = $oid;
        $rows = [];
        foreach ($this->oids as $key => $value) {
            if (str_starts_with($key, $oid.'.')) {
                $rows[$key] = HsgqValue::clean($value) ?? '';
            }
        }

        return $rows;
    }
}

/**
 * Driver HSGQ 50224 diuji dengan walk FIKTIF berbentuk peta OID G02ID (`docs/SMARTOLT_HSGQ_GUIDE.md`):
 * nama/SN/MAC rekaan, tanpa data pelanggan.
 */
class HsgqSnmpDriverTest extends TestCase
{
    private const E = '1.3.6.1.4.1.50224';

    private const PON1 = 16777472;

    private const PON2 = 16777728;

    private function olt(array $attributes = []): SnmpOlt
    {
        $olt = new SnmpOlt(['snmp_version' => 'v2c', 'name' => 'OLT-HSGQ-UJI', 'vendor' => 'HSGQ 50224', ...$attributes]);
        $olt->id = 1;

        return $olt;
    }

    /**
     * GPON 2 PON: PON1 punya ONU 0 (online), 1 (offline, Laser out), 5 (online); PON2 ONU 0 (online).
     *
     * @param  array<string, string>  $override
     * @return array<string, string>
     */
    private function gponOids(array $override = []): array
    {
        $e = self::E;
        $o1 = self::PON1;
        $o2 = self::PON2;

        $oids = [
            '1.3.6.1.2.1.1.1.0' => 'STRING: "HSGQ-G02ID"',
            '1.3.6.1.2.1.1.2.0' => 'OID: .1.3.6.1.4.1.50224.3.1.1',
            '1.3.6.1.2.1.1.3.0' => 'Timeticks: (1053900) 2:55:39.00',
            '1.3.6.1.2.1.1.5.0' => 'STRING: "OLT-UJI"',
            "{$e}.3.1.1.1.0" => 'Hex-STRING: 00 00 5E 00 53 01',
            "{$e}.3.1.1.6.0" => 'Hex-STRING: 49 47 43 5F 56 31 2E 32 2E 39 49 44 5F 52 65 6C',
            "{$e}.3.1.1.17.0" => 'INTEGER: 3',
            "{$e}.3.1.1.18.0" => 'INTEGER: 39',
            "{$e}.3.1.1.20.0" => 'STRING: "SN0800TEST01"',
            '1.3.6.1.2.1.2.2.1.2.257' => 'STRING: "PON01"',
            '1.3.6.1.2.1.2.2.1.2.258' => 'STRING: "PON02"',
            '1.3.6.1.2.1.2.2.1.2.259' => 'STRING: "GE01"',
            '1.3.6.1.2.1.2.2.1.8.257' => 'INTEGER: 1',
            '1.3.6.1.2.1.2.2.1.8.258' => 'INTEGER: 2',
            '1.3.6.1.2.1.2.2.1.8.259' => 'INTEGER: 1',
            // Penghitung per PON: terdaftar / online / offline.
            "{$e}.3.2.3.1.3.{$o1}" => 'INTEGER: 3',
            "{$e}.3.2.3.1.4.{$o1}" => 'INTEGER: 2',
            "{$e}.3.2.3.1.5.{$o1}" => 'INTEGER: 1',
            "{$e}.3.2.3.1.3.{$o2}" => 'INTEGER: 1',
            "{$e}.3.2.3.1.4.{$o2}" => 'INTEGER: 1',
            "{$e}.3.2.3.1.5.{$o2}" => 'INTEGER: 0',
            // SFP PON1 (÷100); PON2 tanpa modul (0).
            "{$e}.3.2.4.1.8.{$o1}" => 'INTEGER: 4450',
            "{$e}.3.2.4.1.9.{$o1}" => 'INTEGER: 330',
            "{$e}.3.2.4.1.10.{$o1}" => 'INTEGER: 2736',
            "{$e}.3.2.4.1.11.{$o1}" => 'INTEGER: 809',
            "{$e}.3.2.4.1.8.{$o2}" => 'INTEGER: 0',
            // Tabel ONU skema ID.
            "{$e}.3.12.2.1.2.{$o1}" => 'STRING: "uji0800-a"',
            "{$e}.3.12.2.1.2.".($o1 + 1) => 'STRING: "uji0800-b"',
            "{$e}.3.12.2.1.2.".($o1 + 5) => 'STRING: "uji0800-c"',
            "{$e}.3.12.2.1.2.{$o2}" => 'STRING: "uji0800-d"',
            "{$e}.3.12.2.1.3.{$o1}" => 'INTEGER: 1',
            "{$e}.3.12.2.1.3.".($o1 + 1) => 'INTEGER: 2',
            "{$e}.3.12.2.1.3.".($o1 + 5) => 'INTEGER: 1',
            "{$e}.3.12.2.1.3.{$o2}" => 'INTEGER: 1',
            "{$e}.3.12.2.1.8.{$o1}" => 'STRING: "HWTC"',
            "{$e}.3.12.2.1.9.{$o1}" => 'STRING: "MODEL-UJI"',
            "{$e}.3.12.2.1.13.{$o1}" => 'STRING: "V1.0-UJI"',
            "{$e}.3.12.2.1.15.{$o1}" => 'Hex-STRING: 48 57 54 43 00 08 00 01',
            "{$e}.3.12.2.1.15.".($o1 + 1) => 'STRING: "ZTEG00080002"',
            "{$e}.3.12.2.1.15.".($o1 + 5) => 'Hex-STRING: 48 57 54 43 00 08 00 03',
            "{$e}.3.12.2.1.15.{$o2}" => 'Hex-STRING: 48 57 54 43 00 08 00 04',
            "{$e}.3.12.2.1.19.{$o1}" => 'INTEGER: 3718',
            "{$e}.3.12.2.1.19.".($o1 + 1) => 'INTEGER: 0',
            "{$e}.3.12.2.1.22.".($o1 + 1) => 'STRING: "Laser out"',
            "{$e}.3.12.2.1.23.{$o1}" => 'INTEGER: 1433700',
            "{$e}.3.12.2.1.23.".($o1 + 1) => 'INTEGER: 0',
            "{$e}.3.12.2.1.23.".($o1 + 5) => 'INTEGER: 50000',
            "{$e}.3.12.2.1.23.{$o2}" => 'INTEGER: 9000',
            // Optik: `.0.0` dibaca ONU, `.65535.65535` Rx di OLT.
            "{$e}.3.12.3.1.4.{$o1}.0.0" => 'INTEGER: -2000',
            "{$e}.3.12.3.1.4.{$o1}.65535.65535" => 'INTEGER: -3045',
            "{$e}.3.12.3.1.4.".($o1 + 5).'.0.0' => 'INTEGER: -2400',
            "{$e}.3.12.3.1.4.{$o2}.0.0" => 'INTEGER: 149',
            "{$e}.3.12.3.1.5.{$o1}.0.0" => 'INTEGER: 141',
        ];

        return array_merge($oids, $override);
    }

    public function test_reads_system_info_with_decoded_firmware_and_gpon_type(): void
    {
        $system = (new HsgqSnmpService(new FakeHsgqSnmp($this->gponOids())))->getSystemInfo($this->olt());

        $this->assertSame('HSGQ-G02ID', $system['model']);
        $this->assertSame('IGC_V1.2.9ID_Rel', $system['firmware']);
        $this->assertSame('SN0800TEST01', $system['serial']);
        $this->assertSame('00:00:5E:00:53:01', $system['mac']);
        $this->assertSame(39, $system['memory_percent']);
        $this->assertSame('gpon', $system['pon_type']);
        $this->assertSame('id', $system['hsgq_schema']);
    }

    public function test_reads_pon_ports_from_if_mib_with_counters_and_sfp(): void
    {
        $ports = (new HsgqSnmpService(new FakeHsgqSnmp($this->gponOids())))->getPorts($this->olt());

        $this->assertCount(2, $ports, 'GE01 bukan port PON');
        $this->assertSame(['PON01', 'up', 3, 2, 1], [$ports[0]['name'], $ports[0]['oper_status'], $ports[0]['onu_registered_snmp'], $ports[0]['onu_online_snmp'], $ports[0]['onu_offline_snmp']]);
        $this->assertSame('down', $ports[1]['oper_status']);
        $this->assertSame(['temperature_c' => 44.5, 'voltage_v' => 3.3, 'bias_ma' => 27.36, 'tx_dbm' => 8.09], $ports[0]['sfp']);
        $this->assertNull($ports[1]['sfp'], 'PON tanpa modul SFP');
    }

    public function test_reads_gpon_onus_with_verified_status_and_onu_zero(): void
    {
        $onus = (new HsgqSnmpService(new FakeHsgqSnmp($this->gponOids())))->getRegisteredOnus($this->olt());

        $this->assertSame(['1.0', '1.1', '1.5', '2.0'], array_column($onus, 'onu_key'));

        $first = $onus[0];
        $this->assertSame(0, $first['onu_id']);
        $this->assertSame('PON01/0', $first['interface']);
        $this->assertSame('HWTC00080001', $first['serial_number']);
        $this->assertNull($first['mac']);
        $this->assertTrue($first['online']);
        $this->assertTrue($first['status_verified']);
        $this->assertSame('status_column', $first['status_source']);
        $this->assertSame(-20.0, $first['rx_power_dbm']);
        $this->assertSame(-30.45, $first['olt_rx_power_dbm']);
        $this->assertSame(1.41, $first['tx_power_dbm']);
        $this->assertSame(3718, $first['distance_m']);
        $this->assertSame('MODEL-UJI', $first['type_name']);
        $this->assertSame('3h 58m', $first['uptime']);

        $offline = $onus[1];
        $this->assertSame('ZTEG00080002', $offline['serial_number']);
        $this->assertFalse($offline['online']);
        $this->assertSame('LOS', $offline['phase_state'], '"Laser out" = cahaya hilang');
        $this->assertNull($offline['rx_power_dbm']);
        $this->assertNull($offline['distance_m']);

        // Rx positif (+1,49 dBm) = glitch yang tercatat di peta OID G02ID → dibuang.
        $this->assertNull($onus[3]['rx_power_dbm']);
    }

    public function test_unmatched_counters_mark_status_unverified(): void
    {
        // Penghitung OLT bilang PON1 online 3, padahal tak satu pun sinyal ONU memberi 3.
        $oids = $this->gponOids(['1.3.6.1.4.1.50224.3.2.3.1.4.'.self::PON1 => 'INTEGER: 3']);
        $onus = (new HsgqSnmpService(new FakeHsgqSnmp($oids)))->getRegisteredOnus($this->olt());

        $this->assertNotEmpty($onus);
        foreach ($onus as $onu) {
            $this->assertFalse($onu['status_verified']);
        }
    }

    public function test_status_falls_back_to_online_time_when_status_column_disagrees(): void
    {
        // Kolom status melapor 1 untuk semua (arti belum terbukti) → lama online yang cocok penghitung.
        $oids = $this->gponOids(['1.3.6.1.4.1.50224.3.12.2.1.3.'.(self::PON1 + 1) => 'INTEGER: 1']);
        $onus = (new HsgqSnmpService(new FakeHsgqSnmp($oids)))->getRegisteredOnus($this->olt());

        $this->assertSame('online_time', $onus[0]['status_source']);
        $this->assertTrue($onus[0]['status_verified']);
        $this->assertFalse($onus[1]['online']);
    }

    public function test_reads_legacy_epon_schema_with_mac_identity(): void
    {
        $e = self::E;
        $o1 = self::PON1;
        $snmp = new FakeHsgqSnmp([
            '1.3.6.1.2.1.1.1.0' => 'STRING: "EPON OLT"',
            '1.3.6.1.2.1.2.2.1.2.257' => 'STRING: "PON1"',
            '1.3.6.1.2.1.2.2.1.8.257' => 'INTEGER: 1',
            "{$e}.3.2.3.1.3.{$o1}" => 'INTEGER: 2',
            "{$e}.3.2.3.1.4.{$o1}" => 'INTEGER: 1',
            "{$e}.3.2.3.1.5.{$o1}" => 'INTEGER: 1',
            "{$e}.3.3.2.1.2.".($o1 + 1) => 'STRING: "uji0800-e"',
            "{$e}.3.3.2.1.2.".($o1 + 2) => 'STRING: "uji0800-f"',
            "{$e}.3.3.2.1.7.".($o1 + 1) => 'Hex-STRING: 00 00 5E 00 53 11',
            "{$e}.3.3.2.1.7.".($o1 + 2) => 'STRING: "00005e005312"',
            "{$e}.3.3.2.1.9.".($o1 + 1) => 'INTEGER: 1',
            "{$e}.3.3.2.1.9.".($o1 + 2) => 'INTEGER: 2',
            "{$e}.3.3.3.1.4.".($o1 + 1).'.0.0' => 'INTEGER: -2136',
        ]);
        $service = new HsgqSnmpService($snmp);
        $olt = $this->olt();

        $system = $service->getSystemInfo($olt);
        $this->assertSame('legacy', $system['hsgq_schema']);
        $this->assertSame('epon', $system['pon_type']);

        $onus = $service->getRegisteredOnus($olt);
        $this->assertSame(['1.1', '1.2'], array_column($onus, 'onu_key'));
        $this->assertSame('00:00:5E:00:53:11', $onus[0]['mac']);
        $this->assertSame('00:00:5E:00:53:11', $onus[0]['serial_number']);
        $this->assertSame('00:00:5E:00:53:12', $onus[1]['mac'], 'teks 12 hex di kolom MAC = MAC, bukan SN');
        $this->assertTrue($onus[0]['online']);
        $this->assertSame(-21.36, $onus[0]['rx_power_dbm']);
        $this->assertFalse($onus[1]['online']);
        $this->assertTrue($onus[0]['status_verified']);
    }

    public function test_never_walks_credential_subtrees(): void
    {
        $snmp = new FakeHsgqSnmp($this->gponOids());
        $service = new HsgqSnmpService($snmp);
        $service->diagnose($this->olt());

        foreach ($snmp->walked as $oid) {
            $this->assertFalse(
                str_starts_with($oid, self::E.'.3.1.1') || str_starts_with($oid, self::E.'.3.1.5') || $oid === self::E || $oid === self::E.'.3',
                "walk subtree sistem/akun terlarang: {$oid}",
            );
        }
    }

    public function test_diagnose_masks_identity_and_omits_names(): void
    {
        $report = (new HsgqSnmpService(new FakeHsgqSnmp($this->gponOids())))->diagnose($this->olt());

        $this->assertTrue($report['snmp_ok']);
        $this->assertSame(['id' => 4, 'legacy' => 0], $report['onu_table_rows']);
        $this->assertTrue($report['status_check']['verified']);
        $this->assertSame('HWTC******01', $report['sample_onu']['identity']);
        $this->assertSame(9, $report['sample_onu']['name_length']);
        $this->assertStringNotContainsString('uji0800', json_encode($report));
    }

    public function test_value_helpers(): void
    {
        $this->assertSame([1, 0], HsgqValue::decodeIndex(16777472));
        $this->assertSame([2, 127], HsgqValue::decodeIndex(16777728 + 127));
        $this->assertNull(HsgqValue::decodeIndex(257));
        $this->assertSame(16777728, HsgqValue::ponIndex(2));
        $this->assertSame('DyingGasp', HsgqValue::offlinePhase('Power down'));
        $this->assertSame('Offline', HsgqValue::offlinePhase(null));
        $this->assertSame('00:00:5E:xx:xx:xx', HsgqValue::mask('00:00:5E:00:53:01'));
    }

    public function test_driver_detection_and_capabilities(): void
    {
        $this->assertSame(SmartOltSupport::DRIVER_HSGQ, SmartOltSupport::driverKey(null, null, '.1.3.6.1.4.1.50224.3.1.1'));
        $this->assertSame(SmartOltSupport::DRIVER_HSGQ, SmartOltSupport::driverKey(null, 'HSGQ-G02ID'));
        // "EPON" tak lagi menyeret OLT HSGQ ke C-Data EPON.
        $this->assertSame(SmartOltSupport::DRIVER_HSGQ, SmartOltSupport::driverKey($this->olt(['name' => 'OLT HSGQ EPON', 'vendor' => 'HSGQ'])));
        // OEM 12170 berlabel HSGQ bukan driver ini.
        $this->assertNotSame(SmartOltSupport::DRIVER_HSGQ, SmartOltSupport::driverKey(null, 'HSGQ EPON', '.1.3.6.1.4.1.12170.2.3'));
        // C-Data & HiOSO tetap seperti sebelumnya.
        $this->assertSame(SmartOltSupport::DRIVER_CDATA_EPON, SmartOltSupport::driverKey(null, 'EPON OLT', '.1.3.6.1.4.1.17409.2.3'));
        $this->assertSame(SmartOltSupport::DRIVER_HIOSO_EPON, SmartOltSupport::driverKey(null, null, '.1.3.6.1.4.1.25355.1'));

        $driver = SmartOltSupport::DRIVER_HSGQ;
        $this->assertTrue(SmartOltSupport::isNonZte($driver));
        $this->assertSame('hsgq-olt', SmartOltSupport::inventoryRoutePrefix($driver));

        $gpon = SmartOltSupport::capabilities($driver, $this->olt(['last_test_result' => ['system' => ['pon_type' => 'gpon']]]));
        $epon = SmartOltSupport::capabilities($driver, $this->olt(['last_test_result' => ['system' => ['pon_type' => 'epon']]]));
        $this->assertSame(['GPON', 'HSGQ GPON'], [$gpon['pon_label'], $gpon['vendor_family']]);
        $this->assertSame(['EPON', 'HSGQ EPON'], [$epon['pon_label'], $epon['vendor_family']]);
        $this->assertSame('EPON', SmartOltSupport::ponLabel($this->olt(['vendor' => 'HSGQ', 'last_test_result' => ['system' => ['pon_type' => 'epon']]])));

        foreach (['supports_reboot', 'supports_onu_info_write', 'supports_config_save', 'supports_snmp_rx', 'hsgq_unverified'] as $on) {
            $this->assertTrue($gpon[$on], $on);
        }
        foreach (['supports_onu_delete', 'supports_onu_toggle', 'supports_provisioning', 'supports_cli_rx'] as $off) {
            $this->assertFalse($gpon[$off], $off);
        }

        $this->assertSame('epon', SmartOltSupport::hsgqPonTypeFromText('HSGQ-XE08R'));
        $this->assertSame('epon', SmartOltSupport::hsgqPonTypeFromText('hsgq e04i'));
        $this->assertSame('gpon', SmartOltSupport::hsgqPonTypeFromText('HSGQ-G16L'));
        $this->assertNull(SmartOltSupport::hsgqPonTypeFromText('OLT kantor'));
    }
}
