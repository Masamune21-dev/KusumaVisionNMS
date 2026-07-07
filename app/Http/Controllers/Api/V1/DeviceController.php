<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FcmDeviceToken;
use App\Services\Fcm\FcmAlarmNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registrasi & pencabutan token perangkat FCM (aplikasi Android).
 * Setiap user yang login boleh mendaftarkan perangkatnya (termasuk demo).
 */
class DeviceController extends Controller
{
    /**
     * POST /api/v1/devices — daftarkan/segarkan token perangkat.
     * Token unik: bila sudah ada, di-rebind ke user saat ini.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:32'],
        ]);

        FcmDeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'device_name' => $data['device_name'] ?? null,
                'platform' => $data['platform'] ?? 'android',
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['data' => ['registered' => true]]);
    }

    /**
     * DELETE /api/v1/devices — cabut token (dipanggil saat logout).
     */
    public function destroy(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');

        FcmDeviceToken::query()
            ->where('token', $token)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['data' => ['removed' => true]]);
    }

    /**
     * POST /api/v1/devices/test — kirim notifikasi tes ke perangkat user saat ini.
     * Untuk memverifikasi pipeline FCM end-to-end dari halaman Akun.
     */
    public function test(Request $request, FcmAlarmNotifier $notifier): JsonResponse
    {
        if (! $notifier->enabled()) {
            return response()->json(['data' => [
                'ok' => false,
                'message' => 'Push FCM belum dikonfigurasi di server (kredensial Firebase belum dipasang).',
            ]]);
        }

        $tokens = FcmDeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->pluck('token')
            ->all();

        if ($tokens === []) {
            return response()->json(['data' => [
                'ok' => false,
                'message' => 'Perangkat ini belum terdaftar untuk notifikasi. Coba keluar lalu masuk lagi, dan izinkan notifikasi.',
            ]]);
        }

        $res = $notifier->sendTest($tokens, '🔔 Tes Notifikasi', 'Push FCM KusumaVision NMS berhasil diterima.');

        return response()->json(['data' => [
            'ok' => $res['ok'],
            'sent' => $res['sent'],
            'message' => $res['ok']
                ? 'Notifikasi tes terkirim ke '.$res['sent'].' perangkat. Cek bar notifikasi.'
                : 'Gagal mengirim: '.($res['error'] ?? $res['reason'] ?? 'tidak diketahui'),
        ]]);
    }
}
