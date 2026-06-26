<?php

namespace App\Http\Controllers;

use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $results = [];

        $oltMatches = SnmpOlt::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('ip', 'like', "%{$query}%");
            })
            ->limit(5)
            ->get(['id', 'name', 'ip', 'vendor']);

        foreach ($oltMatches as $olt) {
            $results[] = [
                'type' => 'olt',
                'id' => $olt->id,
                'label' => $olt->name,
                'sublabel' => $olt->ip,
                'url' => route(SmartOltSupport::isCData(SmartOltSupport::driverKey($olt)) ? 'cdata-olt.detail' : 'smartolt.detail', $olt->id),
            ];
        }

        $onuMatches = $this->searchOnusInCachedResults($query, 10 - count($results));
        $results = array_merge($results, $onuMatches);

        return response()->json(['results' => $results]);
    }

    /**
     * @return array<int, array{type:string, id:string, label:string, sublabel:string, url:string}>
     */
    private function searchOnusInCachedResults(string $query, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $needle = strtolower($query);
        $matches = [];

        $olts = SnmpOlt::query()->get(['id', 'name', 'vendor', 'last_test_result']);
        foreach ($olts as $olt) {
            $portRouteName = SmartOltSupport::isCData(SmartOltSupport::driverKey($olt)) ? 'cdata-olt.port-onus' : 'smartolt.port-onus';
            $portOnus = collect($olt->last_test_result['port_onus'] ?? []);
            foreach ($portOnus as $port) {
                foreach (($port['onus'] ?? []) as $onu) {
                    $serialValue = (string) ($onu['serial_number'] ?? $onu['sn'] ?? $onu['serial'] ?? '');
                    $serial = strtolower($serialValue);
                    $name = strtolower((string) ($onu['name'] ?? ''));
                    $interface = strtolower((string) ($onu['interface'] ?? ''));
                    // ONU EPON tak punya serial terpisah (identitas = MAC) → ikut cocokkan MAC.
                    $mac = strtolower((string) ($onu['mac'] ?? ''));
                    if ($serial === '' && $name === '' && $interface === '' && $mac === '') {
                        continue;
                    }
                    if (! str_contains($serial, $needle) && ! str_contains($name, $needle)
                        && ! str_contains($interface, $needle) && ! str_contains($mac, $needle)) {
                        continue;
                    }

                    $slot = $port['slot'] ?? null;
                    $portNo = $port['port'] ?? null;
                    $label = $serialValue !== '' ? $serialValue : ($onu['name'] ?? $onu['mac'] ?? $onu['interface'] ?? 'ONU');

                    $hasPort = $slot !== null && $portNo !== null;
                    $sublabelParts = [$olt->name];
                    if ($hasPort) {
                        $sublabelParts[] = "{$slot}/{$portNo}";
                    }
                    if (($onu['name'] ?? '') !== '') {
                        $sublabelParts[] = $onu['name'];
                    }

                    $matches[] = [
                        'type' => 'onu',
                        'id' => $olt->id.'-'.$slot.'-'.$portNo.'-'.($onu['onu_id'] ?? $onu['id'] ?? ''),
                        'label' => $label,
                        'sublabel' => implode(' · ', $sublabelParts),
                        'url' => $hasPort
                            ? route($portRouteName, [
                                'olt' => $olt->id,
                                'slot' => $slot,
                                'port' => $portNo,
                                'q' => $serialValue !== '' ? $serialValue : ($onu['name'] ?? ''),
                                'focus' => $onu['onu_id'] ?? $onu['id'] ?? null,
                            ])
                            : route(SmartOltSupport::isCData(SmartOltSupport::driverKey($olt)) ? 'cdata-olt.detail' : 'smartolt.detail', $olt->id),
                    ];

                    if (count($matches) >= $limit) {
                        return $matches;
                    }
                }
            }
        }

        return $matches;
    }
}
