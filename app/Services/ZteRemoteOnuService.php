<?php

namespace App\Services;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;

class ZteRemoteOnuService
{
    private const ONU_NAME_OID = '1.3.6.1.4.1.3902.1012.3.28.1.1.2';

    private const ONU_DESCRIPTION_OID = '1.3.6.1.4.1.3902.1012.3.28.1.1.3';

    private const ONU_ADMIN_STATE_OID = '1.3.6.1.4.1.3902.1012.3.28.1.1.17';

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
        $script = implode("\n", [
            'conf t',
            sprintf('pon-onu-mng gpon-onu_1/%d/%d:%d', $slot, $port, $onuId),
            'reboot',
        ]);

        return $this->executor->executeConfirmable($olt, $script);
    }

    /**
     * Enable (1) or disable (2) an ONU via SNMP SET on the admin-state OID.
     */
    public function setActiveState(SnmpOlt $olt, int $ifIndex, int $onuId, bool $active): bool
    {
        return $this->snmp->set(
            $olt,
            sprintf('%s.%d.%d', self::ONU_ADMIN_STATE_OID, $ifIndex, $onuId),
            'i',
            $active ? '1' : '2',
        );
    }

    /**
     * Write ONU name and/or description via SNMP SET. Null values are skipped.
     */
    public function setInfo(SnmpOlt $olt, int $ifIndex, int $onuId, ?string $name, ?string $description): void
    {
        if ($name !== null) {
            $this->snmp->set($olt, sprintf('%s.%d.%d', self::ONU_NAME_OID, $ifIndex, $onuId), 's', $name);
        }

        if ($description !== null) {
            $this->snmp->set($olt, sprintf('%s.%d.%d', self::ONU_DESCRIPTION_OID, $ifIndex, $onuId), 's', $description);
        }
    }
}
