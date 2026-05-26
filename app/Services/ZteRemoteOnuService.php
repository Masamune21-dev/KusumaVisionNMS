<?php

namespace App\Services;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;

class ZteRemoteOnuService
{
    // C300/C320 OIDs
    private const ONU_NAME_OID = '1.3.6.1.4.1.3902.1012.3.28.1.1.2';

    private const ONU_DESCRIPTION_OID = '1.3.6.1.4.1.3902.1012.3.28.1.1.3';

    private const ONU_ADMIN_STATE_OID = '1.3.6.1.4.1.3902.1012.3.28.1.1.17';

    // C600 OIDs (.1082 subtree)
    private const C600_ONU_NAME_OID = '1.3.6.1.4.1.3902.1082.500.10.2.3.1.2';

    private const C600_ONU_ADMIN_STATE_OID = '1.3.6.1.4.1.3902.1082.500.10.2.8.1.1';

    public function __construct(
        private readonly ZteCliProvisioningExecutor $executor,
        private readonly OltSnmpClient $snmp,
    ) {}

    /**
     * Reboot an ONU via CLI (pon-onu-mng ... reboot), auto-confirming the prompt.
     *
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function reboot(SnmpOlt $olt, int $slot, int $port, int $onuId): array
    {
        $iface = SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt));
        $script = implode("\n", [
            'conf t',
            "pon-onu-mng {$iface}",
            'reboot',
        ]);

        return $this->executor->executeConfirmable($olt, $script);
    }

    /**
     * Enable (1) or disable (2) an ONU via SNMP SET on the admin-state OID.
     */
    public function setActiveState(SnmpOlt $olt, int $ifIndex, int $onuId, bool $active): bool
    {
        $stateOid = SmartOltSupport::isC600($olt)
            ? self::C600_ONU_ADMIN_STATE_OID
            : self::ONU_ADMIN_STATE_OID;

        return $this->snmp->set(
            $olt,
            sprintf('%s.%d.%d', $stateOid, $ifIndex, $onuId),
            'i',
            $active ? '1' : '2',
        );
    }

    /**
     * Write ONU name and/or description via SNMP SET. Null values are skipped.
     */
    public function setInfo(SnmpOlt $olt, int $ifIndex, int $onuId, ?string $name, ?string $description): void
    {
        $isC600 = SmartOltSupport::isC600($olt);
        $nameOid = $isC600 ? self::C600_ONU_NAME_OID : self::ONU_NAME_OID;

        if ($name !== null) {
            $this->snmp->set($olt, sprintf('%s.%d.%d', $nameOid, $ifIndex, $onuId), 's', $name);
        }

        // C600 has no separate description OID; skip silently
        if ($description !== null && ! $isC600) {
            $this->snmp->set($olt, sprintf('%s.%d.%d', self::ONU_DESCRIPTION_OID, $ifIndex, $onuId), 's', $description);
        }
    }
}
