<?php

namespace App\Services;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Services\Zte\Epon\ZteEponProvisioningScriptBuilder;
use App\Support\SmartOltSupport;
use App\Support\ZteEpon;
use RuntimeException;

class ZteRemoteOnuService
{
    // C300/C320 OIDs
    private const ONU_NAME_OID = '1.3.6.1.4.1.3902.1012.3.28.1.1.2';

    private const ONU_DESCRIPTION_OID = '1.3.6.1.4.1.3902.1012.3.28.1.1.3';

    private const ONU_ADMIN_STATE_OID = '1.3.6.1.4.1.3902.1012.3.28.1.1.17';

    // C600: no write OID is mapped. The .1082.500.10.2.3/.10.2.8 subtree these constants used to
    // point at does not exist on a live C600 (No Such Object), so a SET would have written to an
    // unknown OID on the OLT. Both writes stay closed until the real columns are confirmed.
    private const C600_ONU_NAME_OID = null;

    private const C600_ONU_ADMIN_STATE_OID = null;

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
        // ONU EPON: `pon-onu-mng epon-onu_…` + `reboot` (konfirmasi dijawab otomatis) — belum
        // diverifikasi di perangkat asli, lihat ZteEpon.
        $iface = ZteEpon::isEponPort($olt, $slot, $port)
            ? ZteEpon::onuInterface($slot, $port, $onuId)
            : SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt));
        $script = implode("\n", [
            'conf t',
            "pon-onu-mng {$iface}",
            'reboot',
        ]);

        return $this->executor->executeConfirmable($olt, $script);
    }

    /**
     * Delete (deregister) an ONU via CLI `no onu {id}` under the GPON-OLT
     * interface (guide §8 rollback). Destructive: removes the ONU registration.
     *
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function delete(SnmpOlt $olt, int $slot, int $port, int $onuId): array
    {
        $iface = $this->oltInterface($olt, $slot, $port);
        $script = implode("\n", [
            'conf t',
            "interface {$iface}",
            "no onu {$onuId}",
            'exit',
        ]);

        return $this->executor->execute($olt, $script);
    }

    /**
     * Pasang SN ONU pengganti ke slot ONU lama (fitur "bind ONU" NetNumen): hanya SN yang
     * berubah — type, tcont, gemport, service-port, dan pon-onu-mng tetap, lalu dikirim ulang
     * OLT lewat OMCI begitu ONU baru terdaftar. SN sudah divalidasi alfanumerik oleh pemanggil.
     *
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function replaceSerial(SnmpOlt $olt, int $slot, int $port, int $onuId, string $serial): array
    {
        // `registration-method sn` hanya ada di GPON; ONU EPON dikenali dari MAC.
        if (ZteEpon::isEponPort($olt, $slot, $port)) {
            throw new RuntimeException(__('zte.epon_action_unsupported'));
        }

        $iface = SmartOltSupport::onuInterfaceId($slot, $port, $onuId, SmartOltSupport::isC600($olt));
        $script = implode("\n", [
            'conf t',
            "interface {$iface}",
            "registration-method sn {$serial}",
            'exit',
        ]);

        return $this->executor->execute($olt, $script);
    }

    /**
     * Hapus beberapa ONU sekaligus dalam SATU sesi CLI: `no onu {id}` per ONU di bawah
     * interface GPON-OLT yang sama. Kegagalan dipetakan per ONU dari error CLI yang dikutip
     * executor ("`no onu N` → %Error …"), jadi ONU lain tetap dianggap terhapus.
     *
     * @param  list<int>  $onuIds
     * @return array{deleted:list<int>, failed:array<int,string>, error:string|null}
     */
    public function deleteMany(SnmpOlt $olt, int $slot, int $port, array $onuIds): array
    {
        $iface = $this->oltInterface($olt, $slot, $port);
        $script = implode("\n", [
            'conf t',
            "interface {$iface}",
            ...array_map(fn (int $id): string => "no onu {$id}", $onuIds),
            'exit',
        ]);

        $result = $this->executor->execute($olt, $script);
        $error = $result['error'];

        if ($result['ok'] || $error === null) {
            return ['deleted' => array_values($onuIds), 'failed' => [], 'error' => null];
        }

        $failed = [];
        foreach (explode('; ', $error) as $part) {
            if (preg_match('/^`no onu (\d+)` → (.+)$/u', $part, $m) && in_array((int) $m[1], $onuIds, true)) {
                $failed[(int) $m[1]] = $m[2];
            }
        }

        // Error tak terpetakan ke ONU tertentu (sesi putus, interface salah): anggap semua gagal.
        if ($failed === []) {
            return ['deleted' => [], 'failed' => array_fill_keys($onuIds, $error), 'error' => $error];
        }

        return [
            'deleted' => array_values(array_diff($onuIds, array_keys($failed))),
            'failed' => $failed,
            'error' => $error,
        ];
    }

    /**
     * Ganti nama/deskripsi ONU EPON lewat CLI: ONU EPON ZTE menyimpan keduanya dalam SATU baris
     * `property description $$nama$$deskripsi` di `interface epon-onu_…` (tak ada OID SNMP tulis
     * yang terpetakan, dan tak ada perintah `name` terpisah). Nilai yang tak diubah wajib diisi
     * pemanggil dengan nilai lamanya — baris ini menimpa keduanya sekaligus.
     *
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function setEponInfo(SnmpOlt $olt, int $slot, int $port, int $onuId, string $name, string $description): array
    {
        $script = implode("\n", [
            'conf t',
            'interface '.ZteEpon::onuInterface($slot, $port, $onuId),
            ZteEponProvisioningScriptBuilder::propertyDescription($name, $description),
            'exit',
        ]);

        return $this->executor->execute($olt, $script);
    }

    /**
     * Interface OLT tempat `no onu {id}`: `epon-olt_…` untuk port EPON, selain itu GPON per family.
     */
    private function oltInterface(SnmpOlt $olt, int $slot, int $port): string
    {
        return ZteEpon::isEponPort($olt, $slot, $port)
            ? ZteEpon::oltInterface($slot, $port)
            : SmartOltSupport::gponOltInterface($slot, $port, SmartOltSupport::isC600($olt));
    }

    /**
     * Enable (1) or disable (2) an ONU via SNMP SET on the admin-state OID.
     */
    public function setActiveState(SnmpOlt $olt, int $ifIndex, int $onuId, bool $active): bool
    {
        $stateOid = SmartOltSupport::isC600($olt)
            ? self::C600_ONU_ADMIN_STATE_OID
            : self::ONU_ADMIN_STATE_OID;

        if ($stateOid === null) {
            throw new RuntimeException(__('zte.c600_toggle_unsupported'));
        }

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
            if ($nameOid === null) {
                throw new RuntimeException(__('zte.c600_rename_unsupported'));
            }

            $this->snmp->set($olt, sprintf('%s.%d.%d', $nameOid, $ifIndex, $onuId), 's', $name);
        }

        // C600 has no separate description OID; skip silently
        if ($description !== null && ! $isC600) {
            $this->snmp->set($olt, sprintf('%s.%d.%d', self::ONU_DESCRIPTION_OID, $ifIndex, $onuId), 's', $description);
        }
    }
}
