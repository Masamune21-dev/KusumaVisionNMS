<?php

namespace App\Services\Zte;

use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteProvisioningScriptBuilder;
use App\Support\CliOutputSanitizer;
use App\Support\SmartOltSupport;
use Illuminate\Validation\Rule;

/**
 * Inti registrasi ONU ZTE (mode dasar / single-service template): validasi,
 * bangun script CLI, simpan audit ({@see SmartOltOnuRegistration}), dan opsional
 * eksekusi ke OLT via Telnet. Diekstrak agar dipakai bersama REST API mobile
 * (dan sejajar dengan alur web `SmartOltController::storeOnu`).
 */
class OnuRegistrationService
{
    public function __construct(
        private readonly ZteProvisioningScriptBuilder $builder,
        private readonly ZteCliProvisioningExecutor $executor,
    ) {}

    /**
     * Aturan validasi payload registrasi (mode dasar), scoped profil per-OLT.
     *
     * @return array<string, mixed>
     */
    public function rules(SnmpOlt $olt): array
    {
        return [
            'serial_number' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9:_.-]+\z/'],
            'slot' => ['required', 'integer', 'between:1,255'],
            'port' => ['required', 'integer', 'between:1,255'],
            'onu_id' => ['required', 'integer', 'between:1,4096'],
            'oid_index' => ['nullable', 'string', 'max:191'],
            // Blokir CR/LF & karakter kontrol (anti-injeksi CLI); spasi/karakter cetak lain sah utk nama.
            'customer_name' => ['required', 'string', 'max:191', 'not_regex:/[\x00-\x1F\x7F]/'],
            'onu_type' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule($olt, 'onu_type')],
            'tcont_profile' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule($olt, 'tcont')],
            'vlan' => ['required', 'integer', 'between:1,4094'],
            'vlan_profile' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule($olt, 'vlan')],
            'service_name' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'service_mode' => ['nullable', Rule::in(['vlanpri', 'transparent'])],
            'wan_mode' => ['required', Rule::in(['pppoe', 'dhcp', 'static', 'bridge'])],
            'pppoe_username' => ['nullable', 'string', 'max:120', 'regex:/^\S+\z/'],
            'pppoe_password' => ['nullable', 'string', 'max:120', 'regex:/^\S+\z/'],
            'ip_profile' => ['nullable', 'required_if:wan_mode,static', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', $this->activeProfileRule($olt, 'ip')],
            'static_ip' => ['nullable', 'required_if:wan_mode,static', 'ip'],
            'static_netmask' => ['nullable', 'required_if:wan_mode,static', 'integer', 'between:1,32'],
            'tr069_enabled' => ['boolean'],
            'acs_url' => ['nullable', 'required_if:tr069_enabled,true,1', 'url', 'max:255'],
            'acs_username' => ['nullable', 'required_if:tr069_enabled,true,1', 'string', 'max:120', 'regex:/^\S+\z/'],
            'acs_password' => ['nullable', 'required_if:tr069_enabled,true,1', 'string', 'max:120', 'regex:/^\S+\z/'],
            'remote_ont_enabled' => ['boolean'],
            'remote_ont_id' => ['nullable', 'required_if:remote_ont_enabled,true,1', 'integer', 'between:1,4095'],
            'remote_ont_mode' => ['nullable', 'required_if:remote_ont_enabled,true,1', Rule::in(['forward', 'discard'])],
            'remote_ont_protocol' => ['nullable', 'required_if:remote_ont_enabled,true,1', Rule::in(['web', 'telnet', 'ssh', 'ftp', 'tftp', 'snmp'])],
        ];
    }

    /**
     * Bangun script CLI dari payload (untuk preview & eksekusi).
     *
     * @param  array<string, mixed>  $data
     */
    public function buildScript(SnmpOlt $olt, array $data): string
    {
        return $this->builder->build($this->prepare($olt, $data));
    }

    /**
     * Simpan audit + (opsional) eksekusi ke OLT.
     *
     * @param  array<string, mixed>  $validated
     * @return array{status:string, registration_id:int, script:string, output:?string, error:?string}
     */
    public function register(SnmpOlt $olt, array $validated, bool $execute, ?int $userId): array
    {
        $data = $this->prepare($olt, $validated);
        $script = $this->builder->build($data);

        $base = [
            ...$data,
            'snmp_olt_id' => $olt->id,
            'pon_port' => SmartOltSupport::onuInterfaceId(
                (int) $data['slot'],
                (int) $data['port'],
                (int) $data['onu_id'],
                SmartOltSupport::isC600($olt),
            ),
            'cli_script' => $script,
            'created_by' => $userId,
        ];

        if (! $execute) {
            $registration = SmartOltOnuRegistration::create([...$base, 'status' => 'generated']);

            return [
                'status' => 'generated',
                'registration_id' => $registration->id,
                'script' => $script,
                'output' => null,
                'error' => null,
            ];
        }

        try {
            $result = $this->executor->execute($olt, $script);
            $output = CliOutputSanitizer::clean($result['output']);
            $error = $result['error'] === null ? null : CliOutputSanitizer::clean($result['error']);

            $registration = SmartOltOnuRegistration::create([
                ...$base,
                'status' => $result['ok'] ? 'executed' : 'failed',
                'execution_output' => $output,
                'execution_error' => $error,
                'executed_at' => now(),
                'executed_by' => $userId,
            ]);

            return [
                'status' => $result['ok'] ? 'executed' : 'failed',
                'registration_id' => $registration->id,
                'script' => $script,
                'output' => $output,
                'error' => $error,
            ];
        } catch (\Throwable $exception) {
            $error = CliOutputSanitizer::clean($exception->getMessage());
            $registration = SmartOltOnuRegistration::create([
                ...$base,
                'status' => 'failed',
                'execution_error' => $error,
                'executed_at' => now(),
                'executed_by' => $userId,
            ]);

            return [
                'status' => 'failed',
                'registration_id' => $registration->id,
                'script' => $script,
                'output' => null,
                'error' => $error,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(SnmpOlt $olt, array $data): array
    {
        $data = $this->hydrateProfiles($olt, $data);
        $data['is_c600'] = SmartOltSupport::isC600($olt);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function hydrateProfiles(SnmpOlt $olt, array $data): array
    {
        // Mode bridge memakai VLAN ID numerik apa adanya (mis. 100) — jangan
        // ditimpa oleh vlan-profile (yang cuma relevan untuk baris wan-ip routed).
        if (strtolower((string) ($data['wan_mode'] ?? '')) === 'bridge') {
            return $data;
        }

        if (($data['vlan_profile'] ?? null) === null || $data['vlan_profile'] === '') {
            return $data;
        }

        $profile = SmartOltProfile::query()
            ->where('profile_type', 'vlan')
            ->where('is_active', true)
            ->where(function ($query) use ($olt) {
                $query->where('snmp_olt_id', $olt->id)
                    ->orWhereNull('snmp_olt_id');
            })
            ->where('name', $data['vlan_profile'])
            ->first();

        if ($profile) {
            $data['vlan'] = $profile->vlan;
        }

        return $data;
    }

    private function activeProfileRule(SnmpOlt $olt, string $type): mixed
    {
        return Rule::exists('smartolt_profiles', 'name')
            ->where('profile_type', $type)
            ->where('is_active', true)
            ->where(function ($query) use ($olt) {
                $query->where('snmp_olt_id', $olt->id)
                    ->orWhereNull('snmp_olt_id');
            });
    }
}
