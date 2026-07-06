<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autentikasi REST API berbasis token (Laravel Sanctum).
 *
 * Klien (web aplikasi lain / Android) menukar email+password dengan sebuah
 * personal access token, lalu mengirimnya pada setiap request berikutnya
 * via header `Authorization: Bearer <token>`.
 */
class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login — tukar kredensial dengan token akses.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau kata sandi salah.'],
            ]);
        }

        $device = $data['device_name'] ?? ($request->userAgent() ?: 'api-client');

        // Token hanya ditampilkan sekali (plain text) — simpan di sisi klien.
        $token = $user->createToken($device);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    /**
     * GET /api/v1/me — info user pemilik token saat ini.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    /**
     * POST /api/v1/auth/logout — cabut token yang sedang dipakai.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Token dicabut.']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'is_admin' => $user->isAdmin(),
            'is_demo' => $user->isDemo(),
        ];
    }
}
