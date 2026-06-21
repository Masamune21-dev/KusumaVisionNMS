<?php

namespace App\Services;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;
use RuntimeException;

/**
 * Memetakan SnmpOlt → driver SNMP read C-Data (non-ZTE) berdasarkan family hasil
 * {@see SmartOltSupport::driverKey()}.
 *
 * ZTE sengaja tidak melewati resolver ini — pakai {@see OltSnmpClient} langsung.
 *
 * Fase 0: kerangka resolver + deteksi family. Driver C-Data konkret (CDataEponSnmpService /
 * CDataGponSnmpService) di-inject & di-return pada Fase 2; untuk sekarang melempar exception
 * deskriptif agar caller jelas mengapa belum tersedia.
 */
class SmartOltSnmpServiceResolver
{
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
            // Fase 2: return $this->cdataEpon / $this->cdataGpon
            SmartOltSupport::DRIVER_CDATA_EPON,
            SmartOltSupport::DRIVER_CDATA_GPON => throw new RuntimeException(
                "Driver C-Data ({$driver}) untuk OLT '{$olt->name}' belum diimplementasikan (Fase 2)."
            ),
            SmartOltSupport::DRIVER_ZTE => throw new RuntimeException(
                "OLT ZTE '{$olt->name}' memakai OltSnmpClient langsung, bukan resolver C-Data."
            ),
            default => throw new RuntimeException(
                "Family OLT '{$olt->name}' belum dikenali — jalankan Test untuk probe sysObjectID."
            ),
        };
    }
}
