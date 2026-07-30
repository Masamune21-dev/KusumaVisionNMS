<?php

namespace App\Services\HsAirPo;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use Throwable;

/**
 * Driver read OLT HsAirPo / HSGQ EPON (OEM Shenzhen Photon Broadband, enterprise **12170**,
 * produk "4PON EPON-OLT").
 *
 * **CLI-first** — beda dari driver non-ZTE lain: perangkat ini tidak punya tabel ONU di SNMP mana
 * pun (terverifikasi full-walk live Jul 2026, `docs/SMARTOLT_HSAIRPO_GUIDE.md` §2). Pembagian sumber:
 *   - **SNMP** (MIB-2): sistem + daftar/status port PON dari `ifDescr` `pon1..pon4`; plus GET skalar
 *     vendor (nama vendor & jumlah ONU online per-PON sebagai cross-check).
 *   - **CLI** ({@see HsAirPoCliService}): inventori ONU (`show epon onu all info`) + `show version`.
 *
 * Rx per-ONU = Fase B (CLI per-ONU, mahal & wajib throttle) → {@see self::getPortRxMap()} kosong.
 * Semua aksi tulis masih dimatikan di capability (Fase C).
 */
class HsAirPoEponService implements SmartOltSnmpDriver
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    private const SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';

    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';

    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    /** Skalar vendor: nama manufaktur ("Shenzhen Photon Broadband Technology Co.,Ltd"). */
    private const VENDOR_NAME = '1.3.6.1.4.1.12170.2.3.1.1.12.0';

    /** Skalar vendor: nama perangkat ("EPON-OLT"). */
    private const DEVICE_NAME = '1.3.6.1.4.1.12170.2.3.1.1.17.0';

    /**
     * Jumlah ONU **online** per PON: `{base}.{pon}` (kolom 8). Kolom 7 = kapasitas (64/PON).
     * GET langsung — subtree enterprise 12170 TAK BOLEH di-walk (GETNEXT loop, guide §2).
     */
    private const PON_ONLINE_COUNT = '1.3.6.1.4.1.12170.2.3.3.1.1.8.1.0';

    /**
     * Memo hasil sesi CLI per-OLT untuk satu instance driver: scanner memanggil getSystemInfo(),
     * getPorts(), lalu getRegisteredOnus() berurutan pada instance yang sama, dan tanpa memo tiap
     * pemanggilan akan membuka sesi telnet baru ke OLT yang sama.
     *
     * @var array<int, array{version: array<string, string|null>, onus: array<int, array<string, mixed>>, total: ?int, online: ?int}>
     */
    private array $cliSnapshots = [];

    public function __construct(
        private readonly HsAirPoSnmp $snmp,
        private readonly HsAirPoCliService $cli,
    ) {}

    public function ping(SnmpOlt $olt): bool
    {
        try {
            $oid = $this->snmp->get($olt, self::SYS_OBJECT_ID);

            return $oid !== null && str_contains($oid, '12170');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * sysDescr perangkat ini KOSONG (hanya spasi) — identitas diambil dari sysObjectID + skalar
     * vendor, dan firmware/produk dari CLI `show version` (dipakai juga sebagai penanda family di
     * UI). Kegagalan CLI tak menggagalkan info sistem: field firmware sekadar null.
     */
    public function getSystemInfo(SnmpOlt $olt): array
    {
        $version = $this->cliSnapshot($olt, required: false)['version'] ?? [];

        return [
            'sys_descr' => $this->snmp->get($olt, self::SYS_DESCR),
            'sys_object_id' => $this->snmp->get($olt, self::SYS_OBJECT_ID),
            'sys_uptime' => $this->snmp->get($olt, self::SYS_UPTIME),
            'sys_name' => $this->snmp->get($olt, self::SYS_NAME),
            'vendor_name' => $this->snmp->get($olt, self::VENDOR_NAME),
            'device_name' => $this->snmp->get($olt, self::DEVICE_NAME),
            'firmware' => $version['firmware'] ?? null,
            'product' => $version['product'] ?? null,
            'hardware_mac' => $version['mac'] ?? null,
        ];
    }

    /**
     * Port PON dari ifDescr `pon{n}` (ifIndex 5002–5005 pada unit uji). `ifOperStatus` di family ini
     * mencerminkan status port PON (1 up / 2 down) dan dipakai apa adanya; jumlah ONU online per PON
     * ikut dibawa sebagai info tambahan (skalar vendor, cross-check terhadap CLI).
     */
    public function getPorts(SnmpOlt $olt): array
    {
        $status = [];
        try {
            foreach ($this->snmp->walk($olt, self::IF_OPER_STATUS) as $oid => $value) {
                $status[(int) $this->lastSegment($oid)] = (int) $value;
            }
        } catch (Throwable) {
            // status opsional — port tetap dilaporkan dengan 'unknown'
        }

        $ports = [];

        foreach ($this->snmp->walk($olt, self::IF_DESCR) as $oid => $label) {
            if (! preg_match('/^pon\s*(\d+)$/i', trim((string) $label), $m)) {
                continue;
            }

            $ifIndex = (int) $this->lastSegment($oid);
            $pon = (int) $m[1];
            $code = $status[$ifIndex] ?? null;

            $ports[$pon] = [
                'if_index' => $ifIndex,
                'name' => "pon{$pon}",
                'slot' => 1,
                'port' => $pon,
                'oper_status_code' => $code,
                'oper_status' => match ($code) {
                    1 => 'up',
                    2 => 'down',
                    default => 'unknown',
                },
                'onu_online_snmp' => $this->ponOnlineCount($olt, $pon),
            ];
        }

        ksort($ports);

        return array_values($ports);
    }

    public function getRegisteredOnus(SnmpOlt $olt): array
    {
        return $this->cliSnapshot($olt)['onus'];
    }

    public function getRegisteredOnusByPort(SnmpOlt $olt, int $slot, int $port): array
    {
        return array_values(array_filter(
            $this->getRegisteredOnus($olt),
            fn (array $onu) => $onu['slot'] === $slot && $onu['port'] === $port,
        ));
    }

    /**
     * Fase A belum membaca Rx: satu-satunya sumber adalah CLI **per-ONU**
     * (`show epon port {n} onu {id} optical-info`) — 116 perintah untuk OLT uji, dan varian `all`
     * membekukan CLI. Akan diaktifkan di Fase B bersama throttle + cadence RX tersendiri.
     */
    public function getPortRxMap(SnmpOlt $olt): array
    {
        return [];
    }

    public function countRegisteredOnus(SnmpOlt $olt): int
    {
        try {
            $snapshot = $this->cliSnapshot($olt);

            return $snapshot['total'] ?? count($snapshot['onus']);
        } catch (Throwable) {
            return 0;
        }
    }

    public function getUnconfiguredOnus(SnmpOlt $olt): array
    {
        try {
            $ponPorts = array_map(static fn (array $port) => (int) $port['port'], $this->getPorts($olt));

            return $this->cli->fetchUnconfigured($olt, $ponPorts);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Jumlah ONU online per PON menurut SNMP vendor (GET tunggal). Null bila tak terbaca —
     * sekadar info tambahan di baris port, bukan sumber kebenaran (CLI yang berwenang).
     */
    private function ponOnlineCount(SnmpOlt $olt, int $pon): ?int
    {
        try {
            $value = $this->snmp->get($olt, self::PON_ONLINE_COUNT.".{$pon}");

            return is_numeric($value) ? (int) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ambil (dan memo-kan) hasil sesi CLI untuk OLT ini. `$required=false` menelan kegagalan CLI
     * supaya info sistem tetap tampil dari SNMP saat kredensial CLI salah/OLT sibuk.
     *
     * @return array{version: array<string, string|null>, onus: array<int, array<string, mixed>>, total: ?int, online: ?int}
     */
    private function cliSnapshot(SnmpOlt $olt, bool $required = true): array
    {
        if (isset($this->cliSnapshots[$olt->id])) {
            return $this->cliSnapshots[$olt->id];
        }

        try {
            return $this->cliSnapshots[$olt->id] = $this->cli->fetchSnapshot($olt);
        } catch (Throwable $exception) {
            if ($required) {
                throw $exception;
            }

            return ['version' => [], 'onus' => [], 'total' => null, 'online' => null];
        }
    }

    private function lastSegment(string $oid): string
    {
        $segments = explode('.', trim($oid, '.'));

        return end($segments) ?: '0';
    }
}
