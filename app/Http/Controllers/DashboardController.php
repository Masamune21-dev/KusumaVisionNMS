<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardStatsService $stats) {}

    public function index(Request $request): Response
    {
        $range = in_array($request->query('range'), ['24h', '7d', '30d'], true)
            ? $request->query('range')
            : '24h';

        return Inertia::render('Dashboard', [
            'cards' => $this->stats->statCards(),
            'polling_trend' => $this->stats->pollingTrend($range),
            'olt_inventory' => $this->stats->oltInventoryList(),
            'olts' => $this->stats->oltStatuses(),
            'recent_alarms' => $this->stats->recentAlarms(5),
            'provisioning' => $this->stats->provisioningSummary(),
            'range' => $range,
        ]);
    }
}
