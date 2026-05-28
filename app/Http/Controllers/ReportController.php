<?php

namespace App\Http\Controllers;

use App\Models\SnmpOlt;
use App\Services\Report\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $type = $this->type($request);

        return Inertia::render('Reports/Index', [
            'report' => $this->reports->build($type, $filters),
            'filters' => [
                'type' => $type,
                'range' => $filters['range'],
                'olt_id' => $filters['olt_id'],
                'pon_port' => $filters['pon_port'],
            ],
            'typeOptions' => ReportService::typeOptions(),
            'rangeOptions' => ReportService::rangeOptions(),
            'oltOptions' => SnmpOlt::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (SnmpOlt $olt) => ['value' => $olt->id, 'label' => $olt->name]),
            'ponPortOptions' => $this->ponPortOptions($filters['olt_id']),
        ]);
    }

    /**
     * PON port options for the selected OLT (empty when no OLT selected).
     *
     * @return array<int, array{value:string, label:string}>
     */
    private function ponPortOptions(?int $oltId): array
    {
        if (! $oltId) {
            return [];
        }

        $olt = SnmpOlt::find($oltId);
        if (! $olt) {
            return [];
        }

        return collect(data_get($olt->last_test_result, 'ports', []))
            ->map(function (array $port) {
                $slot = data_get($port, 'slot');
                $no = data_get($port, 'port');

                return [
                    'value' => "{$slot}_{$no}",
                    'label' => "PON {$slot}/{$no}",
                ];
            })
            ->filter(fn (array $opt) => $opt['value'] !== '_')
            ->unique('value')
            ->values()
            ->all();
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $type = $this->type($request);
        $report = $this->reports->build($type, $this->filters($request));
        $filename = 'laporan-'.$type.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($report) {
            $handle = fopen('php://output', 'w');
            // BOM agar Excel mengenali UTF-8.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_column($report['columns'], 'label'));
            foreach ($report['rows'] as $row) {
                $line = [];
                foreach ($report['columns'] as $column) {
                    $line[] = $row[$column['key']] ?? '';
                }
                fputcsv($handle, $line);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request)
    {
        $type = $this->type($request);
        $report = $this->reports->build($type, $this->filters($request));

        $pdf = Pdf::loadView('reports.pdf', [
            'report' => $report,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('laporan-'.$type.'-'.now()->format('Ymd-His').'.pdf');
    }

    private function type(Request $request): string
    {
        $type = (string) $request->query('type', 'onu');

        return in_array($type, ReportService::TYPES, true) ? $type : 'onu';
    }

    /**
     * @return array{range:string, olt_id:int|null, pon_port:string|null}
     */
    private function filters(Request $request): array
    {
        $range = (string) $request->query('range', '7d');
        $oltId = $request->query('olt_id') !== null && $request->query('olt_id') !== ''
            ? (int) $request->query('olt_id')
            : null;

        // PON port only relevant when a single OLT is selected; format "{slot}_{port}".
        $ponPort = (string) $request->query('pon_port', '');
        $ponPort = $oltId && preg_match('/^\d+_\d+$/', $ponPort) === 1 ? $ponPort : null;

        return [
            'range' => in_array($range, ReportService::RANGES, true) ? $range : '7d',
            'olt_id' => $oltId,
            'pon_port' => $ponPort,
        ];
    }
}
