<?php

namespace App\Services;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use App\Services\CData\CDataEponSnmpService;
use App\Services\CData\CDataGponCliService;
use App\Services\CData\CDataGponSnmpService;
use App\Services\CData\CDataSnmp;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;
use RuntimeException;

/**
 * Memetakan SnmpOlt → driver SNMP read C-Data (non-ZTE) berdasarkan family hasil
 * {@see SmartOltSupport::driverKey()}.
 *
 * ZTE sengaja tidak melewati resolver ini — pakai {@see OltSnmpClient} langsung.
 *
 * Mengembalikan {@see CDataEponSnmpService} (EPON 17409) atau {@see CDataGponSnmpService}
 * (GPON 34592, legacy + deteksi FlashV3.x). Family yang belum dikenali / ZTE → exception
 * deskriptif. GPON V3 inventory penuh (SN/MAC/optical) di-enrich via CLI pada fase berikutnya.
 */
class SmartOltSnmpServiceResolver
{
    public function __construct(
        private readonly CDataSnmp $snmp,
        private readonly CDataGponCliService $cli,
    ) {}

    public function driverKey(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
    }

    public function supports(SnmpOlt $olt): bool
    {
        return SmartOltSupport::isCData($this->driverKey($olt));
    }

    public function resolve(SnmpOlt $olt): SmartOltSnmpDriver
    {
        $driver = $this->driverKey($olt);

        return match ($driver) {
            SmartOltSupport::DRIVER_CDATA_EPON => new CDataEponSnmpService($this->snmp),
            SmartOltSupport::DRIVER_CDATA_GPON => new CDataGponSnmpService($this->snmp, $this->cli),
            SmartOltSupport::DRIVER_ZTE => throw new RuntimeException(
                "OLT ZTE '{$olt->name}' memakai OltSnmpClient langsung, bukan resolver C-Data."
            ),
            default => throw new RuntimeException(
                "Family OLT '{$olt->name}' belum dikenali — jalankan Test untuk probe sysObjectID."
            ),
        };
    }
}
