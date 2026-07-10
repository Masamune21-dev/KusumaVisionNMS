<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->with('partnerOlts:id')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'assigned_olt_ids' => $user->partnerOlts->pluck('id')->all(),
                'created_at' => $user->created_at?->toISOString(),
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roleOptions' => UserRole::options(),
            'oltOptions' => SnmpOlt::query()
                ->orderBy('name')
                ->get(['id', 'name', 'ip'])
                ->map(fn (SnmpOlt $olt) => [
                    'value' => $olt->id,
                    'label' => $olt->name,
                    'ip' => $olt->ip,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
        ]);

        $this->syncPartnerOlts($user, $data);

        return back()->with('success', 'User berhasil ditambahkan.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->rules($user));

        // Cegah admin terakhir menurunkan rolenya sendiri sehingga sistem terkunci.
        if ($user->isAdmin() && $data['role'] !== UserRole::Admin->value && $this->isLastAdmin($user)) {
            return back()->with('error', 'Tidak dapat menurunkan role admin terakhir.');
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        $this->syncPartnerOlts($user, $data);

        return back()->with('success', 'User berhasil diperbarui.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'.($user ? ','.$user->id : '')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => [$user ? 'nullable' : 'required', Password::defaults()],
            'olt_ids' => ['nullable', 'array'],
            'olt_ids.*' => ['integer', 'exists:snmp_olts,id'],
        ];
    }

    /**
     * Assignment OLT relevan untuk partner & operator; role lain di-kosongkan.
     * (Operator tanpa assignment = akses penuh; lihat {@see User::isOltScoped()}.)
     *
     * PENTING: OLT PRIVAT milik user (`snmp_olts.owner_user_id`) tak terlihat admin
     * (di-scope keluar) sehingga tak ikut di form. Baris pivot-nya WAJIB dipertahankan
     * agar sync di sini tak melepas kepemilikan partner atas OLT-nya sendiri.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncPartnerOlts(User $user, array $data): void
    {
        $assigned = in_array($user->role, [UserRole::Partner, UserRole::Operator], true)
            ? ($data['olt_ids'] ?? [])
            : [];

        $ownedIds = SnmpOlt::withoutGlobalScopes()
            ->where('owner_user_id', $user->id)
            ->pluck('id')
            ->all();

        $user->partnerOlts()->sync(array_values(array_unique([...$assigned, ...$ownedIds])));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Tidak dapat menghapus akun Anda sendiri.');
        }

        if ($user->isAdmin() && $this->isLastAdmin($user)) {
            return back()->with('error', 'Tidak dapat menghapus admin terakhir.');
        }

        // OLT privat milik user yang dihapus dikembalikan ke pool global (owner_user_id
        // null) agar tak jadi yatim/tak terlihat siapa pun. Pivot olt_user cascade otomatis.
        SnmpOlt::withoutGlobalScopes()
            ->where('owner_user_id', $user->id)
            ->update(['owner_user_id' => null]);

        $user->delete();

        return back()->with('success', 'User berhasil dihapus.');
    }

    private function isLastAdmin(User $user): bool
    {
        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }
}
