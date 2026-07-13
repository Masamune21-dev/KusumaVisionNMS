<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardSearchController extends Controller
{
    public function __construct(private readonly GlobalSearchService $search) {}

    public function __invoke(Request $request): JsonResponse
    {
        $items = $this->search->search((string) $request->query('q', ''), 10);

        $results = array_map(function (array $item): array {
            if ($item['type'] === 'olt') {
                return [
                    'type' => 'olt',
                    'id' => $item['olt_id'],
                    'label' => $item['label'],
                    'sublabel' => $item['sublabel'],
                    'url' => route($item['route_prefix'].'.detail', $item['olt_id']),
                ];
            }

            $hasPort = $item['slot'] !== null && $item['port'] !== null;

            return [
                'type' => 'onu',
                'id' => $item['olt_id'].'-'.$item['slot'].'-'.$item['port'].'-'.($item['onu_id'] ?? ''),
                'route_prefix' => $item['route_prefix'],
                'label' => $item['label'],
                'sublabel' => $item['sublabel'],
                'url' => $hasPort
                    ? route($item['route_prefix'].'.port-onus', [
                        'olt' => $item['olt_id'],
                        'slot' => $item['slot'],
                        'port' => $item['port'],
                        'q' => $item['search_value'] ?? '',
                        'focus' => $item['onu_id'],
                    ])
                    : route($item['route_prefix'].'.detail', $item['olt_id']),
            ];
        }, $items);

        return response()->json(['results' => $results]);
    }
}
