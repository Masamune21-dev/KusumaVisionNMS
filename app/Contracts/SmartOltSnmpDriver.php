<?php

namespace App\Contracts;

use App\Models\SnmpOlt;
use App\Services\SmartOltSnmpServiceResolver;
use App\Services\Snmp\OltSnmpClient;

/**
 * Kontrak read untuk driver OLT non-ZTE (C-Data EPON/GPON, vendor lain menyusul) yang di-resolve
 * lewat {@see SmartOltSnmpServiceResolver}.
 *
 * Catatan: OLT ZTE TIDAK memakai kontrak ini — ZTE punya API sendiri di
 * {@see OltSnmpClient} (sengaja tidak di-refactor agar tetap stabil).
 *
 * Method write (rename/reboot/enable-disable/provisioning) BELUM masuk kontrak ini; v1 read-only
 * dan akan ditambah saat fase aksi ONU.
 */
interface SmartOltSnmpDriver
{
    /**
     * Cek reachability + family yang benar. true bila perangkat merespons sebagai family driver ini.
     */
    public function ping(SnmpOlt $olt): bool;

    /**
     * Info sistem MIB-II: sys_descr, sys_object_id, sys_name, sys_uptime, plus penanda family
     * (mis. cdata.firmware_v3) untuk di-cache di last_test_result.
     *
     * @return array<string, mixed>
     */
    public function getSystemInfo(SnmpOlt $olt): array;

    /**
     * Daftar port PON (EPON `epon 0/x/y` atau GPON `gpon X/Y/Z`) dari ifDescr/ifOperStatus.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPorts(SnmpOlt $olt): array;

    /**
     * Seluruh inventory ONU OLT. Tiap baris memakai bentuk cache yang sama dengan ZTE
     * (kunci minimal: onu_key, interface, slot, port, onu_id, name, description, serial_number,
     * mac, status, rx) agar konsisten di ONU Monitoring & global search.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRegisteredOnus(SnmpOlt $olt): array;

    /**
     * Inventory ONU yang dibatasi pada satu port PON.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRegisteredOnusByPort(SnmpOlt $olt, int $slot, int $port): array;

    /**
     * Peta daya Rx per ONU, di-key oleh onu_key. Nilai dalam dBm (sudah dikonversi dari raw).
     *
     * @return array<string, float|null>
     */
    public function getPortRxMap(SnmpOlt $olt): array;

    /**
     * Jumlah ONU teregistrasi (untuk badge ringkas tanpa walk penuh bila driver bisa hemat).
     */
    public function countRegisteredOnus(SnmpOlt $olt): int;

    /**
     * ONU unconfigured/autofind yang belum di-bind.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnconfiguredOnus(SnmpOlt $olt): array;
}
