<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Http\JsonResponse;

/**
 * Ringkasan dashboard (counter OLT/ONU/alarm) untuk widget di aplikasi eksternal.
 */
class SummaryController extends Controller
{
    public function __construct(private readonly DashboardStatsService $stats) {}

    /**
     * GET /api/v1/summary — kartu statistik utama (OLT, ONU, alarm).
     */
    public function index(): JsonResponse
    {
        $cards = $this->stats->statCards();

        return response()->json([
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
                    'warning' => $cards['onu']['warning'],
                ],
                'online_share' => $cards['online_share'],
                'alarms' => [
                    'total' => $cards['alarms']['total'],
                    'critical' => $cards['alarms']['critical'],
                    'major' => $cards['alarms']['major'],
                    'minor' => $cards['alarms']['minor'],
                    'warning' => $cards['alarms']['warning'],
                ],
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
