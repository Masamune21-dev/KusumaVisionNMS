<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ZoneService;
use Illuminate\Http\JsonResponse;

/**
 * Daftar zona (taksonomi global, bukan per-OLT) — dipakai aplikasi eksternal/mobile
 * untuk dropdown filter atau provisioning.
 */
class ZoneController extends Controller
{
    public function __construct(private readonly ZoneService $zones) {}

    /**
     * GET /api/v1/zones — daftar zona terurut alfabetis.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->zones->options()]);
    }
}
