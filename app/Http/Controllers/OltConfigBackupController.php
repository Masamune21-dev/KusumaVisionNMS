<?php

namespace App\Http\Controllers;

use App\Models\OltConfigBackup;
use App\Models\SnmpOlt;
use App\Services\Zte\OltConfigBackupService;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Riwayat & aksi backup running-config OLT (ZTE). Otorisasi kepemilikan ditegakkan
 * otomatis oleh route-model binding + PartnerOltScope (admin/operator lihat semua,
 * partner hanya OLT miliknya). Isi config sensitif → hanya diserahkan lewat endpoint
 * content/download yang juga ter-scope OLT.
 */
class OltConfigBackupController extends Controller
{
    public function index(SnmpOlt $olt): Response
    {
        $backups = $olt->configBackups()
            ->with('creator:id,name')
            ->paginate(20)
            ->through(fn (OltConfigBackup $b) => $this->serializeBackup($b));

        return Inertia::render('SmartOlt/ConfigBackups', [
            'olt' => [
                'id' => $olt->id,
                'name' => $olt->name,
                'ip' => $olt->ip,
                'vendor' => $olt->vendor,
                'config_backup_enabled' => (bool) $olt->config_backup_enabled,
            ],
            'supported' => SmartOltSupport::driverKey($olt) === SmartOltSupport::DRIVER_ZTE,
            'backups' => $backups,
        ]);
    }

    public function store(SnmpOlt $olt, OltConfigBackupService $service): RedirectResponse
    {
        // Backup manual berjalan sinkron; running-config OLT besar bisa perlu puluhan detik.
        @set_time_limit(180);

        $result = $service->capture($olt, OltConfigBackup::TRIGGER_MANUAL, request()->user()?->id);

        if (! $result['ok']) {
            return back()->with('error', __('flash.backup_failed').($result['error'] ?? 'kesalahan tidak diketahui'));
        }

        return back()->with(
            'success',
            $result['changed']
                ? "Backup config OLT {$olt->name} tersimpan (versi baru)."
                : 'Config tidak berubah sejak backup terakhir — tak ada versi baru dibuat.',
        );
    }

    public function toggle(SnmpOlt $olt): RedirectResponse
    {
        $enabled = ! $olt->config_backup_enabled;
        $olt->update(['config_backup_enabled' => $enabled]);

        return back()->with(
            'success',
            $enabled
                ? "Backup config harian diaktifkan untuk OLT {$olt->name}."
                : "Backup config harian dimatikan untuk OLT {$olt->name}.",
        );
    }

    public function content(SnmpOlt $olt, OltConfigBackup $backup): JsonResponse
    {
        $this->assertBackupBelongsTo($olt, $backup);

        return response()->json([
            'id' => $backup->id,
            'captured_at' => optional($backup->captured_at)->toIso8601String(),
            'content' => $backup->status === OltConfigBackup::STATUS_OK ? (string) $backup->content : '',
        ]);
    }

    public function download(SnmpOlt $olt, OltConfigBackup $backup): StreamedResponse
    {
        $this->assertBackupBelongsTo($olt, $backup);
        abort_if($backup->status !== OltConfigBackup::STATUS_OK, 404);

        $stamp = optional($backup->captured_at)->format('Ymd-His') ?? (string) $backup->id;
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $olt->name) ?: 'olt';
        $filename = "running-config_{$slug}_{$stamp}.txt";
        $content = (string) $backup->content;

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function assertBackupBelongsTo(SnmpOlt $olt, OltConfigBackup $backup): void
    {
        abort_unless($backup->snmp_olt_id === $olt->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBackup(OltConfigBackup $backup): array
    {
        return [
            'id' => $backup->id,
            'size_bytes' => (int) $backup->size_bytes,
            'trigger' => $backup->trigger,
            'status' => $backup->status,
            'error' => $backup->error,
            'created_by' => $backup->creator?->name,
            'captured_at' => optional($backup->captured_at)->toIso8601String(),
        ];
    }
}
