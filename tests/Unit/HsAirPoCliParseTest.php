<?php

namespace Tests\Unit;

use App\Services\HsAirPo\HsAirPoCliService;
use App\Support\SmartOltSupport;
use PHPUnit\Framework\TestCase;

/**
 * Parser CLI HsAirPo / HSGQ (Photon 12170). Fixture = output ASLI dari OLT lab (4PON EPON-OLT,
 * firmware 1.1.2.20210408_release) — bukan karangan, sesuai aturan "verifikasi di perangkat asli".
 */
class HsAirPoCliParseTest extends TestCase
{
    /** Potongan nyata `show epon onu all info` (kolom dipisah campuran tab & spasi). */
    private function onuAllInfoOutput(): string
    {
        return implode("\n", [
            'show epon onu all info',
            '-----------------------------------------------------------------------------',
            "\tPON   ONU\tMAC\t\t\tControl\t Run  \t  Config   Match",
            "\t      ID\t   \t\t\tflag     state\t  state    state",
            '-----------------------------------------------------------------------------',
            "\t1      1   \t0C:37:47:78:E9:27\tActive   Online   Success  Match   ",
            "\t1      2   \tC0:94:AD:4F:18:3A\tActive   Online   Success  Match   ",
            "\t1      27  \tEC:23:7B:AF:B6:38\tActive   Offline  Initial  Initial ",
            "\t2      1   \t74:B5:7E:9D:19:6F\tActive   Online   Success  Match   ",
            "\t4      27  \t84:93:B2:A0:8B:B6\tActive   Online   Success  Match   ",
            '-----------------------------------------------------------------------------',
            '  Total: 116, online 107',
            'EPON-OLT#',
        ]);
    }

    public function test_parses_onu_table_into_zte_shaped_rows(): void
    {
        $rows = (new HsAirPoCliService)->parseOnuAllInfo($this->onuAllInfoOutput());

        $this->assertCount(5, $rows);

        $first = $rows[0];
        $this->assertSame('1.1', $first['onu_key']);
        $this->assertSame(1, $first['slot']);          // chassis tunggal → slot selalu 1
        $this->assertSame(1, $first['port']);          // port = nomor PON
        $this->assertSame(1, $first['onu_id']);
        $this->assertSame('pon1:1', $first['interface']);
        $this->assertSame('0C:37:47:78:E9:27', $first['serial_number']);
        $this->assertSame('0C:37:47:78:E9:27', $first['mac']);
        $this->assertTrue($first['online']);
        $this->assertSame('Online', $first['phase_state']);
        $this->assertSame('enable', $first['admin_state']);
        $this->assertSame('Success', $first['config_state']);
        $this->assertSame('Match', $first['match_state']);
        // Rx belum dibaca di fase ini (CLI per-ONU, Fase B).
        $this->assertNull($first['rx_power_dbm']);
    }

    public function test_marks_offline_onu_from_run_state_column(): void
    {
        $rows = (new HsAirPoCliService)->parseOnuAllInfo($this->onuAllInfoOutput());
        $offline = collect($rows)->firstWhere('onu_key', '1.27');

        $this->assertNotNull($offline);
        $this->assertFalse($offline['online']);
        $this->assertSame('Offline', $offline['phase_state']);
    }

    public function test_sorts_rows_by_pon_then_onu_id(): void
    {
        $rows = (new HsAirPoCliService)->parseOnuAllInfo($this->onuAllInfoOutput());

        $this->assertSame(
            ['1.1', '1.2', '1.27', '2.1', '4.27'],
            array_column($rows, 'onu_key'),
        );
    }

    public function test_ignores_header_and_footer_lines(): void
    {
        $rows = (new HsAirPoCliService)->parseOnuAllInfo($this->onuAllInfoOutput());

        // Baris header ("PON ONU MAC ...") & footer ("Total: 116, online 107") bukan ONU.
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[0-9A-F:]{17}$/', (string) $row['mac']);
        }
    }

    public function test_reads_footer_totals(): void
    {
        $cli = new HsAirPoCliService;
        $output = $this->onuAllInfoOutput();

        $this->assertSame(116, $cli->parseFooterCount($output, 'total'));
        $this->assertSame(107, $cli->parseFooterCount($output, 'online'));
        $this->assertNull($cli->parseFooterCount('tak ada angka di sini', 'total'));
    }

    public function test_parses_show_version(): void
    {
        $output = implode("\n", [
            'show version',
            ' Version     : 1.1.2.20210408_release (build-145001)',
            ' Created     : 2021-04-08 14:50:01',
            ' Product Name: 4PON EPON-OLT',
            ' Product OID : 1.3.6.1.4.1.12170.2.3',
            ' MAC Address : 90c6.821c.cdab',
            ' DRAM SIZE   : 262144K bytes',
            ' Running Time: DAY:75 HOUR:15 MIN:35 SEC:12',
            'EPON-OLT#',
        ]);

        $version = (new HsAirPoCliService)->parseVersion($output);

        $this->assertSame('1.1.2.20210408_release (build-145001)', $version['firmware']);
        $this->assertSame('4PON EPON-OLT', $version['product']);
        $this->assertSame('1.3.6.1.4.1.12170.2.3', $version['product_oid']);
        $this->assertSame('90c6.821c.cdab', $version['mac']);
    }

    public function test_autofind_list_empty_when_device_reports_none(): void
    {
        $cli = new HsAirPoCliService;

        // Kalimat asli OLT saat autofind kosong (typo vendor dipertahankan apa adanya).
        $this->assertSame([], $cli->parseAutofindList('Error: There is no ONU does not exist in autofind list.'));
        $this->assertSame(
            ['AA:BB:CC:DD:EE:FF'],
            $cli->parseAutofindList("  1   aa:bb:cc:dd:ee:ff   unauth\nEPON-OLT#"),
        );
    }

    public function test_driver_detection_and_capabilities(): void
    {
        // sysObjectID adalah penanda paling andal: sysDescr perangkat ini kosong.
        $driver = SmartOltSupport::driverKey(null, null, '.1.3.6.1.4.1.12170.2.3');
        $this->assertSame(SmartOltSupport::DRIVER_HSAIRPO_EPON, $driver);
        $this->assertSame(SmartOltSupport::DRIVER_HSAIRPO_EPON, SmartOltSupport::driverKey(null, 'HSGQ 4PON EPON-OLT'));
        $this->assertSame(SmartOltSupport::DRIVER_HSAIRPO_EPON, SmartOltSupport::driverKey(null, 'HsAirPo EPON'));

        $this->assertTrue(SmartOltSupport::isHsAirPo($driver));
        $this->assertTrue(SmartOltSupport::isNonZte($driver));
        $this->assertFalse(SmartOltSupport::isCData($driver));
        $this->assertFalse(SmartOltSupport::isHioso($driver));
        $this->assertSame('hsairpo-olt', SmartOltSupport::inventoryRoutePrefix($driver));

        $caps = SmartOltSupport::capabilities($driver);
        $this->assertSame('EPON', $caps['pon_label']);
        // Aktif: Rx via CLI + rename/reboot/delete via CLI config.
        foreach (['supports_cli_rx', 'supports_reboot', 'supports_onu_info_write', 'supports_onu_delete'] as $capability) {
            $this->assertTrue($caps[$capability], "capability {$capability} harus aktif");
        }
        // Belum: SNMP Rx (tak ada), enable/disable (semantik belum diuji), provisioning, save-config.
        foreach (['supports_snmp_rx', 'supports_onu_toggle', 'supports_provisioning', 'supports_config_save'] as $capability) {
            $this->assertFalse($caps[$capability], "capability {$capability} harus mati");
        }
        $this->assertSame('cli_hsairpo', $caps['description_mode']);
    }

    public function test_parses_optical_rx_and_rejects_sentinel(): void
    {
        $cli = new HsAirPoCliService;

        $output = implode("\n", [
            'show epon port 1 onu 1 optical-info',
            '-----------------------------------------------------------------------------',
            '  Voltage(V)              : 3.23',
            '  Tx optical power(dBm)   : 1.93',
            '  Rx optical power(dBm)   : -21.43',
            '  Laser bias current(mA)  : 18.25',
            '  Temperature(C)          : 49.99',
            '-----------------------------------------------------------------------------',
            'EPON-OLT#',
        ]);

        $this->assertSame(-21.43, $cli->parseOpticalRx($output));
        // Di luar jendela wajar (-40..0) → null (sentinel / tak masuk akal).
        $this->assertNull($cli->parseOpticalRx('  Rx optical power(dBm)   : -99.99'));
        $this->assertNull($cli->parseOpticalRx('  Rx optical power(dBm)   : 3.00'));
        $this->assertNull($cli->parseOpticalRx('tak ada data optik'));
    }

    public function test_zte_and_hioso_detection_still_wins_over_hsairpo_needles(): void
    {
        // Urutan needle: ZTE & HiOSO diperiksa sebelum HsAirPo; "epon" generik C-Data sesudahnya.
        $this->assertSame(SmartOltSupport::DRIVER_ZTE, SmartOltSupport::driverKey(null, 'ZTE C320'));
        $this->assertSame(SmartOltSupport::DRIVER_HIOSO_EPON, SmartOltSupport::driverKey(null, 'HiOSO HA7304'));
        $this->assertSame(SmartOltSupport::DRIVER_CDATA_EPON, SmartOltSupport::driverKey(null, 'C-Data FD1108 EPON'));
    }
}
