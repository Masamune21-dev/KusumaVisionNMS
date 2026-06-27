<?php

namespace App\Services\Report;

use App\Models\AlarmEvent;
use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use Illuminate\Support\Carbon;

class ReportService
{
    public const TYPES = ['onu', 'olt', 'alarm', 'provisioning'];

    public const RANGES = ['24h', '7d', '30d', 'all'];

    /** RX attenuation levels usable as a filter on the RX power report. */
    public const RX_STATUSES = ['normal', 'warning', 'critical'];

    /**
     * Build a report dataset for the given type and filters.
     *
     * @param  array{range?:string, olt_id?:int|null}  $filters
     * @return array{type:string, title:string, columns:array<int,array{key:string,label:string}>, rows:array<int,array<string,mixed>>, summary:array<int,array{label:string,value:int|string}>}
     */
    public function build(string $type, array $filters = []): array
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'onu';

        $report = match ($type) {
            'olt' => $this->oltStatus($filters),
            'alarm' => $this->alarmHistory($filters),
            'provisioning' => $this->provisioning($filters),
            default => $this->onuInventory($filters),
        };

        return $this->applyStatusFilter($report, $type, $filters['status'] ?? null);
    }

    /**
     * Expose the available status values (from the full dataset) and narrow the
     * displayed rows to the chosen one. Summary counts stay over the full dataset,
     * mirroring the RX attenuation filter. RX reports use their own redaman filter.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function applyStatusFilter(array $report, string $type, ?string $status): array
    {
        // OLT report keeps its status under "reachable"; the rest use "status".
        $column = $type === 'olt' ? 'reachable' : 'status';

        $report['status_column'] = $column;
        // ONU inventory uses fixed phase states (matching the ONU monitoring filter);
        // other reports derive their available statuses from the data.
        $report['status_options'] = $type === 'onu'
            ? [
                ['value' => 'Online', 'label' => 'Online'],
                ['value' => 'LOS', 'label' => 'LOS'],
                ['value' => 'Dying Gasp', 'label' => 'Dying Gasp'],
                ['value' => 'Offline', 'label' => 'Offline'],
            ]
            : collect($report['rows'])
                ->pluck($column)
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->unique()
                ->sort()
                ->values()
                ->map(fn ($value) => ['value' => (string) $value, 'label' => (string) $value])
                ->all();

        if ($status !== null && $status !== '') {
            $report['rows'] = array_values(array_filter(
                $report['rows'],
                fn (array $row) => (string) ($row[$column] ?? '') === $status,
            ));
        }

        return $report;
    }

    public function title(string $type): string
    {
        return match ($type) {
            'olt' => 'Laporan Status OLT',
            'alarm' => 'Laporan Riwayat Alarm',
            'provisioning' => 'Laporan Provisioning ONU',
            default => 'Laporan Inventaris & RX Power ONU',
        };
    }

    /**
     * @return array<int, array{value:string, label:string}>
     */
    public static function typeOptions(): array
    {
        return [
            ['value' => 'onu', 'label' => 'Inventaris & RX Power ONU'],
            ['value' => 'olt', 'label' => 'Status OLT'],
            ['value' => 'alarm', 'label' => 'Riwayat Alarm'],
            ['value' => 'provisioning', 'label' => 'Provisioning ONU'],
        ];
    }

    /**
     * @return array<int, array{value:string, label:string}>
     */
    public static function rangeOptions(): array
    {
        return [
            ['value' => '24h', 'label' => '24 Jam Terakhir'],
            ['value' => '7d', 'label' => '7 Hari Terakhir'],
            ['value' => '30d', 'label' => '30 Hari Terakhir'],
            ['value' => 'all', 'label' => 'Semua Waktu'],
        ];
    }

    /**
     * Human-friendly ONU phase label, mirroring the ONU monitoring status filter.
     */
    private function onuPhaseLabel(bool $online, string $phase): string
    {
        if ($online || $phase === 'Working') {
            return 'Online';
        }

        return match ($phase) {
            'LOS' => 'LOS',
            'DyingGasp' => 'Dying Gasp',
            'Offline' => 'Offline',
            default => $phase !== '' ? $phase : 'Offline',
        };
    }

    private function startFor(string $range): ?Carbon
    {
        return match ($range) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => null,
        };
    }

    /**
     * Inventaris ONU + RX Power dalam satu tabel. Selalu mencerminkan state cache
     * "saat ini" (last_test_result), tidak bergantung pada rentang waktu.
     *
     * @param  array{olt_id?:int|null, pon_port?:string|null, rx_status?:string|null}  $filters
     */
    private function onuInventory(array $filters): array
    {
        $olts = $this->oltsQuery($filters)->get();
        $rxFilter = in_array($filters['rx_status'] ?? null, self::RX_STATUSES, true)
            ? $filters['rx_status']
            : null;
        $rows = [];
        $total = 0;
        $online = 0;
        $warning = 0;
        $critical = 0;

        foreach ($olts as $olt) {
            foreach ((array) data_get($olt->last_test_result, 'port_onus', []) as $key => $group) {
                if (! empty($filters['pon_port']) && (string) $key !== $filters['pon_port']) {
                    continue;
                }
                [$slot, $port] = array_pad(explode('_', (string) $key), 2, '');
                foreach ((array) data_get($group, 'onus', []) as $onu) {
                    $total++;
                    $isOnline = (bool) data_get($onu, 'online', false);
                    $online += $isOnline ? 1 : 0;

                    // RX power opsional: tidak semua ONU punya pembacaan RX di cache.
                    $rx = data_get($onu, 'rx_power_dbm', data_get($onu, 'rx_power', data_get($onu, 'rx')));
                    $rxLevel = '';
                    $rxLabel = '-';
                    if (is_numeric($rx)) {
                        $rx = (float) $rx;
                        $rxLabel = sprintf('%.2f dBm', $rx);
                        $rxLevel = 'normal';
                        if ($rx < -28) {
                            $rxLevel = 'critical';
                            $critical++;
                        } elseif ($rx < -25) {
                            $rxLevel = 'warning';
                            $warning++;
                        }
                    }

                    // Ringkasan di atas mencakup seluruh dataset; baris yang tampil
                    // dipersempit ke level redaman terpilih (mirip filter ONU monitoring).
                    if ($rxFilter !== null && $rxLevel !== $rxFilter) {
                        continue;
                    }

                    $rows[] = [
                        'olt' => $olt->name,
                        'interface' => data_get($onu, 'interface', "{$slot}/{$port}"),
                        'onu_id' => data_get($onu, 'onu_id'),
                        'serial_number' => data_get($onu, 'serial_number'),
                        'name' => data_get($onu, 'name') ?: '-',
                        'rx_power' => $rxLabel,
                        // Bukan kolom tampil; hanya untuk pewarnaan sel RX di frontend.
                        'rx_level' => $rxLevel,
                        'status' => $this->onuPhaseLabel($isOnline, (string) data_get($onu, 'phase_state', '')),
                    ];
                }
            }
        }

        return [
            'type' => 'onu',
            'title' => $this->title('onu'),
            'columns' => [
                ['key' => 'olt', 'label' => 'OLT'],
                ['key' => 'interface', 'label' => 'Interface'],
                ['key' => 'onu_id', 'label' => 'ONU ID'],
                ['key' => 'serial_number', 'label' => 'Serial Number'],
                ['key' => 'name', 'label' => 'Nama / Pelanggan'],
                ['key' => 'rx_power', 'label' => 'RX Power'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total ONU', 'value' => $total],
                ['label' => 'Online', 'value' => $online],
                ['label' => 'Offline', 'value' => $total - $online],
                ['label' => 'RX Warning (< -25 dBm)', 'value' => $warning],
                ['label' => 'RX Critical (< -28 dBm)', 'value' => $critical],
            ],
        ];
    }

    /**
     * @param  array{range?:string, olt_id?:int|null}  $filters
     */
    private function oltStatus(array $filters): array
    {
        $olts = $this->oltsQuery($filters)->orderBy('name')->get();
        $rows = [];
        $reachable = 0;

        foreach ($olts as $olt) {
            $result = $olt->last_test_result ?? [];
            $isReachable = (bool) data_get($result, 'ok', false);
            $reachable += $isReachable ? 1 : 0;
            $portOnus = collect(data_get($result, 'port_onus', []));
            $onuTotal = (int) $portOnus->sum('count');
            $onuOnline = $portOnus->flatMap(fn ($p) => data_get($p, 'onus', []))->where('online', true)->count();

            $rows[] = [
                'name' => $olt->name,
                'ip' => $olt->ip,
                'reachable' => $isReachable ? 'Online' : 'Offline',
                'onu_total' => $onuTotal,
                'onu_online' => $onuOnline,
                'onu_offline' => max($onuTotal - $onuOnline, 0),
                'last_polled_at' => $olt->last_polled_at?->timezone(config('app.display_timezone', 'Asia/Jakarta'))->format('d/m/Y H:i') ?? '-',
            ];
        }

        return [
            'type' => 'olt',
            'title' => $this->title('olt'),
            'columns' => [
                ['key' => 'name', 'label' => 'OLT'],
                ['key' => 'ip', 'label' => 'IP'],
                ['key' => 'reachable', 'label' => 'Status'],
                ['key' => 'onu_total', 'label' => 'Total ONU'],
                ['key' => 'onu_online', 'label' => 'Online'],
                ['key' => 'onu_offline', 'label' => 'Offline'],
                ['key' => 'last_polled_at', 'label' => 'Polling Terakhir'],
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total OLT', 'value' => count($rows)],
                ['label' => 'Online', 'value' => $reachable],
                ['label' => 'Offline', 'value' => count($rows) - $reachable],
            ],
        ];
    }

    /**
     * @param  array{range?:string, olt_id?:int|null}  $filters
     */
    private function alarmHistory(array $filters): array
    {
        $start = $this->startFor($filters['range'] ?? '7d');

        $query = AlarmEvent::query()->with('olt:id,name');
        if ($start) {
            $query->where('last_seen_at', '>=', $start);
        }
        if (! empty($filters['olt_id'])) {
            $query->where('snmp_olt_id', $filters['olt_id']);
        }
        if (! empty($filters['pon_port'])) {
            [$slot, $port] = array_pad(explode('_', (string) $filters['pon_port']), 2, null);
            $query->where('slot', (int) $slot)->where('port', (int) $port);
        }

        $alarms = $query->orderByDesc('last_seen_at')->get();
        $bySeverity = ['critical' => 0, 'major' => 0, 'minor' => 0, 'warning' => 0];

        $rows = $alarms->map(function (AlarmEvent $a) use (&$bySeverity) {
            if (isset($bySeverity[$a->severity])) {
                $bySeverity[$a->severity]++;
            }

            return [
                'olt' => $a->olt?->name ?? '-',
                'severity' => ucfirst((string) $a->severity),
                'type' => $a->type,
                'message' => $a->message,
                'status' => $a->status === AlarmEvent::STATUS_ACTIVE ? 'Aktif' : 'Selesai',
                'last_seen_at' => $a->last_seen_at?->timezone(config('app.display_timezone', 'Asia/Jakarta'))->format('d/m/Y H:i') ?? '-',
            ];
        })->all();

        return [
            'type' => 'alarm',
            'title' => $this->title('alarm'),
            'columns' => [
                ['key' => 'olt', 'label' => 'OLT'],
                ['key' => 'severity', 'label' => 'Severity'],
                ['key' => 'type', 'label' => 'Tipe'],
                ['key' => 'message', 'label' => 'Pesan'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'last_seen_at', 'label' => 'Terakhir Terlihat'],
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total Alarm', 'value' => count($rows)],
                ['label' => 'Critical', 'value' => $bySeverity['critical']],
                ['label' => 'Major', 'value' => $bySeverity['major']],
                ['label' => 'Minor + Warning', 'value' => $bySeverity['minor'] + $bySeverity['warning']],
            ],
        ];
    }

    /**
     * @param  array{range?:string, olt_id?:int|null}  $filters
     */
    private function provisioning(array $filters): array
    {
        $start = $this->startFor($filters['range'] ?? '30d');

        $query = SmartOltOnuRegistration::query()->with(['olt:id,name', 'creator:id,name']);
        if ($start) {
            $query->where('created_at', '>=', $start);
        }
        if (! empty($filters['olt_id'])) {
            $query->where('snmp_olt_id', $filters['olt_id']);
        }
        if (! empty($filters['pon_port'])) {
            [$slot, $port] = array_pad(explode('_', (string) $filters['pon_port']), 2, null);
            $query->where('slot', (int) $slot)->where('port', (int) $port);
        }

        $registrations = $query->orderByDesc('created_at')->get();
        $success = 0;
        $failed = 0;

        $rows = $registrations->map(function (SmartOltOnuRegistration $r) use (&$success, &$failed) {
            if (in_array($r->status, ['success', 'executed', 'completed'], true)) {
                $success++;
            } elseif (in_array($r->status, ['failed', 'error'], true)) {
                $failed++;
            }

            return [
                'created_at' => $r->created_at?->timezone(config('app.display_timezone', 'Asia/Jakarta'))->format('d/m/Y H:i') ?? '-',
                'olt' => $r->olt?->name ?? '-',
                'serial_number' => $r->serial_number,
                'customer_name' => $r->customer_name ?: '-',
                'wan_mode' => $r->wan_mode ?: '-',
                'status' => ucfirst((string) $r->status),
                'created_by' => $r->creator?->name ?? '-',
            ];
        })->all();

        return [
            'type' => 'provisioning',
            'title' => $this->title('provisioning'),
            'columns' => [
                ['key' => 'created_at', 'label' => 'Tanggal'],
                ['key' => 'olt', 'label' => 'OLT'],
                ['key' => 'serial_number', 'label' => 'Serial Number'],
                ['key' => 'customer_name', 'label' => 'Pelanggan'],
                ['key' => 'wan_mode', 'label' => 'Mode WAN'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_by', 'label' => 'Dibuat Oleh'],
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total Registrasi', 'value' => count($rows)],
                ['label' => 'Berhasil', 'value' => $success],
                ['label' => 'Gagal', 'value' => $failed],
            ],
        ];
    }

    private function oltsQuery(array $filters)
    {
        $query = SnmpOlt::query();
        if (! empty($filters['olt_id'])) {
            $query->where('id', $filters['olt_id']);
        }

        return $query;
    }
}
