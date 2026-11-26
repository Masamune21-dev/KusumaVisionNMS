<?php

namespace App\Services\Olt;

use App\Models\SmartOltFanStatus;
use App\Models\SmartOltFanSummary;
use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteCliProvisioningExecutor;
use App\Support\SmartOltSupport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Baca status fan OLT ZTE (C300/C320 lewat SNMP, C600 lewat CLI `show fan`).
 *
 * Fase ini BACA-SAJA: tidak ada SNMP SET atau perintah konfigurasi. Data disimpan per OLT
 * supaya halaman Detail tidak membuka sesi SNMP/telnet setiap kali dirender.
 */
class OltFanStatusService
{
    /** Subtree `zxAnEnvFan` — terverifikasi live di C300 & C320 (Nov 2026). */
    private const FAN_BASE = '1.3.6.1.4.1.3902.1015.2.1.3.10.10';

    /** Suhu lingkungan chassis. */
    private const ENV_TEMP = '1.3.6.1.4.1.3902.1015.2.1.3.2.0';

    /** Tabel fan relatif terhadap FAN_BASE: `…10.10.10.1`. */
    private const FAN_TABLE = self::FAN_BASE.'.10.1';

    private const MODE_AUTO = 1;

    private const MODE_FIXED = 2;

    public function __construct(
        private readonly OltSnmpClient $snmp,
        private readonly ZteCliProvisioningExecutor $executor,
    ) {}

    public function supports(SnmpOlt $olt): bool
    {
        return SmartOltSupport::driverKey($olt) === SmartOltSupport::DRIVER_ZTE;
    }

    /**
     * Baca langsung dari perangkat (tanpa menyimpan).
     *
     * @return array<string, mixed>
     */
    public function read(SnmpOlt $olt): array
    {
        if (! $this->supports($olt)) {
            throw new RuntimeException(__('fan.unsupported'));
        }

        return SmartOltSupport::isC600($olt)
            ? $this->readC600($olt)
            : $this->readSnmp($olt);
    }

    /**
     * Baca lalu simpan. Mengembalikan bentuk tersimpan (siap dikirim ke Vue).
     *
     * @return array<string, mixed>
     */
    public function refresh(SnmpOlt $olt): array
    {
        $data = $this->read($olt);
        $now = now();

        DB::transaction(function () use ($olt, $data, $now): void {
            SmartOltFanSummary::query()->updateOrCreate(
                ['snmp_olt_id' => $olt->id],
                [
                    'source' => $data['source'],
                    'mode' => $data['mode'],
                    'mode_code' => $data['mode_code'],
                    'temp_c' => $data['temp_c'],
                    'min_level' => $data['min_level'],
                    'thresholds' => $data['thresholds'] ?: null,
                    'percents' => $data['percents'] ?: null,
                    'raw_output' => $data['raw_output'] ?? null,
                    'refreshed_at' => $now,
                ],
            );

            SmartOltFanStatus::query()->where('snmp_olt_id', $olt->id)->delete();

            foreach ($data['fans'] as $fan) {
                SmartOltFanStatus::query()->create([
                    ...$fan,
                    'snmp_olt_id' => $olt->id,
                    'refreshed_at' => $now,
                ]);
            }
        });

        return $this->stored($olt) ?? $data;
    }

    /**
     * Data tersimpan terakhir untuk satu OLT, atau null bila belum pernah di-refresh.
     *
     * @return array<string, mixed>|null
     */
    public function stored(SnmpOlt $olt): ?array
    {
        $summary = SmartOltFanSummary::query()
            ->where('snmp_olt_id', $olt->id)
            ->first();

        if ($summary === null) {
            return null;
        }

        $fans = SmartOltFanStatus::query()
            ->where('snmp_olt_id', $olt->id)
            ->orderBy('rack')
            ->orderBy('shelf')
            ->orderBy('fan_index')
            ->get();

        return [
            'source' => $summary->source,
            'mode' => $summary->mode,
            'mode_code' => $summary->mode_code,
            'temp_c' => $summary->temp_c,
            'min_level' => $summary->min_level,
            'thresholds' => $summary->thresholds ?? [],
            'percents' => $summary->percents ?? [],
            'unit' => $summary->source === 'cli' && $fans->contains(fn (SmartOltFanStatus $f) => $f->speed_percent !== null)
                ? 'percent'
                : 'rpm',
            'fans' => $fans->map(fn (SmartOltFanStatus $fan) => [
                'index' => $fan->fan_index,
                'name' => $fan->fan_name,
                'status' => $fan->status,
                'admin_status' => $fan->admin_status,
                'oper_status' => $fan->oper_status,
                'online_status' => $fan->online_status,
                'level' => $fan->level,
                'speed_rpm' => $fan->speed_rpm,
                'speed_percent' => $fan->speed_percent,
            ])->all(),
            'refreshed_at' => $summary->refreshed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readSnmp(SnmpOlt $olt): array
    {
        $rows = $this->snmp->walk($olt, self::FAN_BASE);

        if ($rows === []) {
            // C300/C320 tanpa subtree fan (mis. model non-ZTE yang salah dideteksi) —
            // jangan simpan tabel kosong seolah-olah fan sehat.
            throw new RuntimeException(__('fan.table_empty'));
        }

        $temp = null;
        try {
            $temp = $this->toInt($this->snmp->get($olt, self::ENV_TEMP));
        } catch (\Throwable) {
            // Suhu lingkungan opsional: fan tetap bisa ditampilkan walau OID suhu gagal.
        }

        $data = $this->parseSnmpRows($rows, $temp);
        $data['raw_output'] = null;

        if ($data['fans'] === []) {
            throw new RuntimeException(__('fan.table_empty'));
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function readC600(SnmpOlt $olt): array
    {
        $result = $this->executor->execute($olt, 'show fan');

        if (! $result['ok']) {
            throw new RuntimeException($result['error'] ?: __('fan.c600_unparseable'));
        }

        $data = $this->parseC600Cli($result['output']);

        if ($data['fans'] === []) {
            throw new RuntimeException(__('fan.c600_unparseable'));
        }

        $data['raw_output'] = $result['output'];

        return $data;
    }

    /**
     * Parse hasil walk `zxAnEnvFan` (OID tanpa titik awal) + suhu lingkungan.
     *
     * @param  array<string, string>  $rows
     * @return array<string, mixed>
     */
    public function parseSnmpRows(array $rows, ?int $tempC = null): array
    {
        $modeCode = $this->toInt($rows[self::FAN_BASE.'.1.0'] ?? null);

        $thresholds = [];
        foreach ([6, 7, 8, 9] as $column) {
            $value = $this->toInt($rows[self::FAN_BASE.'.'.$column.'.0'] ?? null);
            if ($value !== null) {
                $thresholds[] = $value;
            }
        }

        $percents = [];
        foreach ([11, 12, 13, 14] as $column) {
            $value = $this->toInt($rows[self::FAN_BASE.'.'.$column.'.0'] ?? null);
            if ($value !== null) {
                $percents[] = $value;
            }
        }

        $fans = [];
        $prefix = self::FAN_TABLE.'.';
        foreach ($rows as $oid => $value) {
            $suffix = str_starts_with($oid, $prefix) ? substr($oid, strlen($prefix)) : null;
            if ($suffix === null || ! preg_match('/^([2-7])\.(\d+)$/', $suffix, $match)) {
                continue;
            }

            $column = (int) $match[1];
            $index = (int) $match[2];
            $fans[$index][$column] = $this->toInt($value);
        }

        $parsed = [];
        foreach ($fans as $index => $columns) {
            $oper = $columns[5] ?? null;
            $online = $columns[6] ?? null;
            $status = ($oper === 1 && $online === 1) ? 'normal' : 'abnormal';

            $parsed[] = [
                'rack' => 1,
                'shelf' => 1,
                'fan_index' => $index,
                'fan_name' => 'Fan '.$index,
                'status' => $status,
                'admin_status' => $columns[4] ?? null,
                'oper_status' => $oper,
                'online_status' => $online,
                'level' => $columns[3] ?? $columns[2] ?? null,
                'speed_rpm' => $columns[7] ?? null,
                'speed_percent' => null,
                'raw_line' => null,
            ];
        }

        usort($parsed, fn (array $a, array $b) => $a['fan_index'] <=> $b['fan_index']);

        return [
            'source' => 'snmp',
            'mode' => match ($modeCode) {
                self::MODE_AUTO => 'auto',
                self::MODE_FIXED => 'fixed',
                default => 'unknown',
            },
            'mode_code' => $modeCode,
            'temp_c' => $tempC,
            'min_level' => null,
            'thresholds' => $thresholds,
            'percents' => $percents,
            'fans' => $parsed,
        ];
    }

    /**
     * Parse keluaran CLI C600:
     *
     *   Fan speed mini level: 1
     *   Fan speed mode: auto
     *   Index      Status      Speed
     *     0    work normally   44%
     *
     * @return array<string, mixed>
     */
    public function parseC600Cli(string $output): array
    {
        $mode = 'unknown';
        if (preg_match('/Fan speed mode:\s*([a-zA-Z-]+)/', $output, $match)) {
            $mode = match (strtolower($match[1])) {
                'auto' => 'auto',
                'fixed', 'fix' => 'fixed',
                default => 'unknown',
            };
        }

        $minLevel = null;
        if (preg_match('/Fan speed mini level:\s*(\d+)/', $output, $match)) {
            $minLevel = (int) $match[1];
        }

        $fans = [];
        if (preg_match('/Index\s+Status\s+Speed(.*)$/s', $output, $section)) {
            preg_match_all('/^\s*(\d+)\s+([A-Za-z][A-Za-z ]*?)\s+(\d+)\s*%\s*$/m', $section[1], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $statusText = strtolower(trim($match[2]));
                $fans[] = [
                    'rack' => 1,
                    'shelf' => 1,
                    'fan_index' => (int) $match[1],
                    'fan_name' => 'FAN',
                    'status' => str_contains($statusText, 'normal') ? 'normal' : 'abnormal',
                    'admin_status' => null,
                    'oper_status' => null,
                    'online_status' => 1,
                    'level' => null,
                    'speed_rpm' => null,
                    'speed_percent' => (int) $match[3],
                    'raw_line' => trim($match[0]),
                ];
            }
        }

        usort($fans, fn (array $a, array $b) => $a['fan_index'] <=> $b['fan_index']);

        return [
            'source' => 'cli',
            'mode' => $mode,
            'mode_code' => null,
            'temp_c' => null,
            'min_level' => $minLevel,
            'thresholds' => [],
            'percents' => [],
            'fans' => $fans,
        ];
    }

    private function toInt(?string $value): ?int
    {
        if ($value === null || ! preg_match('/-?\d+/', $value, $match)) {
            return null;
        }

        return (int) $match[0];
    }
}
