<?php

namespace App\Http\Controllers;

use App\Models\SmartOltProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SmartOltProfileController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SmartOlt/Profiles', [
            'profiles' => $this->profileOptions(includeInactive: true),
            'types' => [
                ['key' => 'onu_type', 'label' => 'ONU Type', 'uses_vlan' => false],
                ['key' => 'tcont', 'label' => 'T-CONT Profile', 'uses_vlan' => false],
                ['key' => 'vlan', 'label' => 'VLAN Profile', 'uses_vlan' => true],
                ['key' => 'ip', 'label' => 'IP Profile', 'uses_vlan' => false],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        SmartOltProfile::create($this->validated($request));

        return redirect()
            ->route('smartolt.profiles.index')
            ->with('success', 'Profile berhasil ditambahkan.');
    }

    public function update(Request $request, SmartOltProfile $profile): RedirectResponse
    {
        $profile->update($this->validated($request, $profile));

        return redirect()
            ->route('smartolt.profiles.index')
            ->with('success', 'Profile berhasil diperbarui.');
    }

    public function destroy(SmartOltProfile $profile): RedirectResponse
    {
        $profile->delete();

        return redirect()
            ->route('smartolt.profiles.index')
            ->with('success', 'Profile berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SmartOltProfile $profile = null): array
    {
        return $request->validate([
            'profile_type' => ['required', Rule::in(SmartOltProfile::TYPES)],
            'name' => [
                'required',
                'string',
                'max:120',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('smartolt_profiles', 'name')
                    ->where('profile_type', $request->input('profile_type'))
                    ->ignore($profile),
            ],
            'vlan' => ['nullable', 'required_if:profile_type,vlan', 'integer', 'between:1,4094'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function profileOptions(bool $includeInactive = false): array
    {
        $query = SmartOltProfile::query()
            ->orderBy('profile_type')
            ->orderBy('name');

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return $query
            ->get()
            ->groupBy('profile_type')
            ->map(fn ($profiles) => $profiles->map(fn (SmartOltProfile $profile) => [
                'id' => $profile->id,
                'profile_type' => $profile->profile_type,
                'name' => $profile->name,
                'vlan' => $profile->vlan,
                'notes' => $profile->notes,
                'is_active' => $profile->is_active,
            ])->values()->all())
            ->all();
    }
}
