<?php

namespace App\Http\Controllers;

use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteProfileCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SmartOltProfileController extends Controller
{
    public function index(SnmpOlt $olt): Response
    {
        return Inertia::render('SmartOlt/Profiles', [
            'olt' => $this->serializeOlt($olt),
            'profiles' => self::profileOptions($olt, includeInactive: true, includeGlobalFallback: false),
            'types' => $this->types(),
        ]);
    }

    public function store(Request $request, SnmpOlt $olt, ZteProfileCatalogService $catalog, ZteCliProvisioningExecutor $executor): RedirectResponse
    {
        $data = $this->validated($request);
        unset($data['execute_cli']);
        $data['snmp_olt_id'] = $olt->id;
        $data['source'] = $request->boolean('execute_cli') ? 'manage_form_cli' : 'manage_form';
        $script = $catalog->buildScript('add', $data);

        if ($request->boolean('execute_cli')) {
            $executor->execute($olt, $script);
        }

        SmartOltProfile::updateOrCreate(
            [
                'snmp_olt_id' => $olt->id,
                'profile_type' => $data['profile_type'],
                'name' => $data['name'],
            ],
            $data,
        );

        return redirect()
            ->route('smartolt.profiles.index', $olt)
            ->with('success', $request->boolean('execute_cli') ? __('flash.profile_added_olt') : __('flash.profile_added_cache'));
    }

    public function update(Request $request, SnmpOlt $olt, SmartOltProfile $profile, ZteProfileCatalogService $catalog, ZteCliProvisioningExecutor $executor): RedirectResponse
    {
        abort_unless($profile->snmp_olt_id === $olt->id, 404);

        $oldName = $profile->name;
        $data = $this->validated($request, $profile);
        unset($data['execute_cli']);
        $data['snmp_olt_id'] = $olt->id;
        $data['source'] = $request->boolean('execute_cli') ? 'manage_form_cli' : 'manage_form';
        $script = $catalog->buildScript('add', $data, $oldName);

        if ($request->boolean('execute_cli')) {
            $executor->execute($olt, $script);
        }

        $profile->update($data);

        return redirect()
            ->route('smartolt.profiles.index', $olt)
            ->with('success', $request->boolean('execute_cli') ? __('flash.profile_updated_olt') : __('flash.profile_updated_cache'));
    }

    public function destroy(Request $request, SnmpOlt $olt, SmartOltProfile $profile, ZteProfileCatalogService $catalog, ZteCliProvisioningExecutor $executor): RedirectResponse
    {
        abort_unless($profile->snmp_olt_id === $olt->id, 404);

        if ($request->boolean('execute_cli')) {
            $executor->execute($olt, $catalog->buildScript('delete', $profile->toArray()));
        }

        $profile->delete();

        return redirect()
            ->route('smartolt.profiles.index', $olt)
            ->with('success', $request->boolean('execute_cli') ? __('flash.profile_deleted_olt') : __('flash.profile_deleted_cache'));
    }

    public function syncFromOlt(SnmpOlt $olt, ZteProfileCatalogService $catalog): RedirectResponse
    {
        try {
            $result = $catalog->syncFromOlt($olt);

            return redirect()
                ->route('smartolt.profiles.index', $olt)
                ->with('success', sprintf(__('flash.profile_sync_ok_fmt'), $result['count']));
        } catch (\Throwable $exception) {
            return redirect()
                ->route('smartolt.profiles.index', $olt)
                ->with('error', __('flash.profile_sync_failed').$exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SmartOltProfile $profile = null): array
    {
        $type = (string) $request->input('profile_type');

        return $request->validate([
            'profile_type' => ['required', Rule::in(SmartOltProfile::TYPES)],
            'name' => [
                'required',
                'string',
                'max:120',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('smartolt_profiles', 'name')
                    ->where('snmp_olt_id', $profile?->snmp_olt_id ?? $request->route('olt')?->id)
                    ->where('profile_type', $type)
                    ->ignore($profile),
            ],
            'vlan' => ['nullable', 'required_if:profile_type,vlan', 'integer', 'between:1,4094'],
            'params.type' => ['nullable', 'required_if:profile_type,tcont', 'integer', 'between:1,5'],
            'params.maximum' => ['nullable', 'required_if:profile_type,tcont', 'integer', 'between:64,9953280'],
            'params.tag_mode' => ['nullable', 'required_if:profile_type,vlan', Rule::in(['tag', 'untag', 'translate'])],
            'params.pri' => ['nullable', 'integer', 'between:0,7'],
            'params.gateway' => ['nullable', 'required_if:profile_type,ip', 'ip'],
            'params.primary_dns' => ['nullable', 'ip'],
            'params.secondary_dns' => ['nullable', 'ip'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'execute_cli' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function profileOptions(?SnmpOlt $olt = null, bool $includeInactive = false, bool $includeGlobalFallback = true): array
    {
        $query = SmartOltProfile::query()
            ->orderBy('profile_type')
            ->orderBy('name');

        if ($olt) {
            $query->where('snmp_olt_id', $olt->id);

            if ($includeGlobalFallback) {
                $query->orWhere(function ($query) use ($includeInactive) {
                    $query->whereNull('snmp_olt_id');

                    if (! $includeInactive) {
                        $query->where('is_active', true);
                    }
                });
            }
        }

        if (! $includeInactive && (! $olt || ! $includeGlobalFallback)) {
            $query->where('is_active', true);
        }

        return $query
            ->get()
            ->groupBy('profile_type')
            ->map(fn ($profiles) => $profiles->map(fn (SmartOltProfile $profile) => [
                'id' => $profile->id,
                'snmp_olt_id' => $profile->snmp_olt_id,
                'profile_type' => $profile->profile_type,
                'name' => $profile->name,
                'source' => $profile->source,
                'vlan' => $profile->vlan,
                'params' => $profile->params ?? [],
                'notes' => $profile->notes,
                'is_active' => $profile->is_active,
                'last_synced_at' => $profile->last_synced_at?->toIso8601String(),
            ])->values()->all())
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function types(): array
    {
        return [
            ['key' => 'onu_type', 'label' => 'ONU Type', 'uses_vlan' => false],
            ['key' => 'tcont', 'label' => 'T-CONT Profile', 'uses_vlan' => false],
            ['key' => 'vlan', 'label' => 'VLAN Profile', 'uses_vlan' => true],
            ['key' => 'ip', 'label' => 'IP Profile', 'uses_vlan' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOlt(SnmpOlt $olt): array
    {
        return [
            'id' => $olt->id,
            'name' => $olt->name,
            'ip' => $olt->ip,
            'cli_transport' => $olt->cli_transport,
            'cli_port' => $olt->cli_port,
            'cli_username' => $olt->cli_username,
        ];
    }
}
