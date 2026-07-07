<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search?q= — pencarian global lintas-OLT untuk aplikasi mobile.
 *
 * Mengembalikan data navigasi ter-struktur (bukan URL web) agar klien bisa
 * melakukan deep-link langsung ke detail OLT atau halaman ONU per port.
 */
class SearchController extends Controller
{
    public function __construct(private readonly GlobalSearchService $search) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->search->search((string) $request->query('q', ''), 10);

        $data = array_map(fn (array $item): array => [
            'type' => $item['type'],
            'label' => $item['label'],
            'sublabel' => $item['sublabel'],
            'olt_id' => $item['olt_id'],
            'olt_name' => $item['olt_name'],
            'slot' => $item['slot'],
            'port' => $item['port'],
            'onu_id' => $item['onu_id'],
            'serial_number' => $item['serial_number'],
        ], $items);

        return response()->json(['data' => $data]);
    }
}
