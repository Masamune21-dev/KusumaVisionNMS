<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SnmpOlt;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/olts/{olt}/unconfigured — ONU yang terdeteksi OLT tapi belum diregister
 * (autofind), dari snapshot `last_test_result.unconfigured_onus`. ZTE-only; family lain
 * mengembalikan daftar kosong (snapshot tak pernah terisi). Refresh live = endpoint tulis.
 */
class UnconfiguredOnuController extends Controller
{
    public function index(SnmpOlt $olt): JsonResponse
    {
        $snapshot = data_get($olt->last_test_result ?? [], 'unconfigured_onus', []);

        return response()->json([
            'data' => data_get($snapshot, 'onus', []),
            'meta' => [
                'olt_id' => $olt->id,
                'ok' => (bool) data_get($snapshot, 'ok', false),
                'count' => (int) data_get($snapshot, 'count', 0),
                'error' => data_get($snapshot, 'error'),
                'refreshed_at' => data_get($snapshot, 'refreshed_at'),
            ],
        ]);
    }
}
