<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Endpoint status PUBLIK (tanpa login) — aman untuk disisipkan/di-embed di
 * halaman web lain. Hanya angka agregat: TIDAK memuat data pelanggan
 * (nama/alamat/serial), TIDAK memuat IP/community OLT. CORS aktif (`api/*`).
 *
 * Hasil di-cache 30 detik agar tahan banting bila dipanggil dari banyak page-view.
 */
class PublicStatusController extends Controller
{
    public function __construct(private readonly DashboardStatsService $stats) {}

    /**
     * GET /api/v1/public/status — ringkasan status jaringan untuk widget publik.
     */
    public function __invoke(): JsonResponse
    {
        $payload = Cache::remember('api.public.status', 30, function () {
            $cards = $this->stats->statCards();

            $olts = collect($this->stats->oltStatuses())->map(fn (array $o) => [
                'name' => $o['name'],
                'reachable' => $o['reachable'],
                'onu_total' => $o['onu_total'],
                'onu_online' => $o['onu_online'],
                'onu_offline' => $o['onu_offline'],
                'last_polled_at' => $o['last_polled_at'],
            ])->values()->all();

            return [
                'data' => [
                    'olt' => [
                        'total' => $cards['olt']['total'],
                        'online' => $cards['olt']['online'],
                        'offline' => $cards['olt']['offline'],
                    ],
                    'onu' => [
                        'total' => $cards['onu']['total'],
                        'online' => $cards['onu']['online'],
                        'offline' => $cards['onu']['offline'],
                    ],
                    'online_share' => $cards['online_share'],
                    'alarms' => ['active' => $cards['alarms']['total']],
                    'olts' => $olts,
                ],
                'meta' => ['generated_at' => now()->toIso8601String()],
            ];
        });

        return response()->json($payload);
    }
}
