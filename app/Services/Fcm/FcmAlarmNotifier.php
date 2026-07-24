<?php

namespace App\Services\Fcm;

use App\Enums\UserRole;
use App\Models\AlarmEvent;
use App\Models\FcmDeviceToken;
use App\Models\FcmSetting;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Alarm\OdpAlarmGrouper;
use App\Services\AlarmEvaluator;
use App\Services\Telegram\TelegramNotifier;
use App\Support\SmartOltSupport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Kirim push FCM ke perangkat Android saat alarm naik (raised) / turun (cleared).
 * Meniru pola {@see TelegramNotifier}: filter minimal severity,
 * satu notifikasi per alarm dengan data payload untuk deep-link.
 *
 * DEFENSIF: bila kreait belum terpasang, service-account JSON belum ada, atau tak ada
 * token perangkat, method jadi no-op — polling terjadwal tak boleh gagal karenanya.
 */
class FcmAlarmNotifier
{
    public const SEVERITY_RANK = [
        AlarmEvent::SEVERITY_WARNING => 1,
        AlarmEvent::SEVERITY_MINOR => 2,
        AlarmEvent::SEVERITY_MAJOR => 3,
        AlarmEvent::SEVERITY_CRITICAL => 4,
    ];

    /**
     * Kredensial Firebase tersedia (kapabilitas teknis). Dipakai tombol "Tes Push"
     * & broadcast manual — bekerja terlepas dari saklar forwarding alarm.
     */
    public function enabled(): bool
    {
        $path = (string) config('services.fcm.credentials');

        return class_exists(Factory::class) && $path !== '' && is_file($path);
    }

    /**
     * Push alarm otomatis aktif: kredensial ada DAN admin mengaktifkan di Settings.
     * Dipakai {@see AlarmEvaluator} untuk memutuskan dispatch job.
     */
    public function active(): bool
    {
        return $this->enabled() && FcmSetting::instance()->enabled;
    }

    /**
     * @param  array<int, AlarmEvent>|Collection<int, AlarmEvent>  $raised
     * @param  array<int, AlarmEvent>|Collection<int, AlarmEvent>  $cleared
     */
    public function notify(SnmpOlt $olt, iterable $raised = [], iterable $cleared = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $setting = FcmSetting::instance();
        if (! $setting->enabled) {
            return;
        }

        // Penerima: admin + operator tanpa-assignment (semua OLT) + operator/partner
        // yang di-assign ke OLT ini. Yang di-scope tak menerima alarm di luar assignment.
        $tokens = FcmDeviceToken::query()
            ->whereIn('user_id', $this->recipientUserIds($olt))
            ->pluck('token')
            ->all();
        if ($tokens === []) {
            return;
        }

        $minRank = $setting->minSeverityRank();
        $types = $setting->notifyTypes();

        $messages = [];
        if ($setting->notify_on_raise) {
            // Filter dulu (semantik tak berubah), lalu kelompokkan alarm down per-ODP:
            // >1 ONU 1 ODP → 1 push grup, sisanya per-ONU seperti biasa.
            $eligible = [];
            foreach ($raised as $alarm) {
                if ((self::SEVERITY_RANK[$alarm->severity] ?? 1) < $minRank
                    || ! in_array($alarm->type, $types, true)) {
                    continue;
                }
                $eligible[] = $alarm;
            }

            foreach (app(OdpAlarmGrouper::class)->group($olt, $eligible) as $item) {
                $messages[] = $item['kind'] === 'odp'
                    ? $this->buildOdpMessage($olt, $item)
                    : $this->buildMessage($olt, $item['alarm'], 'raised');
            }
        }
        if ($setting->notify_on_clear) {
            foreach ($cleared as $alarm) {
                if ((self::SEVERITY_RANK[$alarm->severity] ?? 1) < $minRank
                    || ! in_array($alarm->type, $types, true)) {
                    continue;
                }
                $messages[] = $this->buildMessage($olt, $alarm, 'cleared');
            }
        }

        if ($messages === []) {
            return;
        }

        try {
            $messaging = (new Factory)
                ->withServiceAccount((string) config('services.fcm.credentials'))
                ->createMessaging();

            $invalid = [];
            foreach ($messages as $message) {
                $report = $messaging->sendMulticast($message, $tokens);
                foreach ($report->invalidTokens() as $bad) {
                    $invalid[$bad] = true;
                }
                foreach ($report->unknownTokens() as $bad) {
                    $invalid[$bad] = true;
                }
            }

            if ($invalid !== []) {
                FcmDeviceToken::query()->whereIn('token', array_keys($invalid))->delete();
            }

            $setting->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();
        } catch (\Throwable $e) {
            Log::warning('FCM push gagal: '.$e->getMessage());
            $setting->forceFill(['last_error' => $e->getMessage()])->save();
        }
    }

    /**
     * Broadcast notifikasi manual ke SEMUA perangkat terdaftar (dari halaman Settings web).
     *
     * @return array{ok:bool, sent:int, failed:int, reason:?string, error:?string}
     */
    public function broadcast(string $title, string $body): array
    {
        $tokens = FcmDeviceToken::query()->pluck('token')->all();
        $res = $this->sendTest($tokens, $title, $body);

        $setting = FcmSetting::instance();
        if ($res['ok']) {
            $setting->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();
        } elseif (($res['reason'] ?? null) === 'error') {
            $setting->forceFill(['last_error' => $res['error']])->save();
        }

        return $res;
    }

    /**
     * Kirim notifikasi tes ke token tertentu (dipakai tombol "Tes Push" di app).
     *
     * @param  array<int, string>  $tokens
     * @return array{ok:bool, sent:int, failed:int, reason:?string, error:?string}
     */
    public function sendTest(array $tokens, string $title, string $body): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'reason' => 'fcm_disabled', 'error' => null];
        }
        if ($tokens === []) {
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'reason' => 'no_tokens', 'error' => null];
        }

        try {
            $messaging = (new Factory)
                ->withServiceAccount((string) config('services.fcm.credentials'))
                ->createMessaging();

            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData(['event' => 'test'])
                ->withDefaultSounds()
                ->withAndroidConfig([
                    'priority' => 'high',
                    'notification' => ['channel_id' => 'alarms'],
                ]);

            $report = $messaging->sendMulticast($message, $tokens);

            $invalid = array_merge($report->invalidTokens(), $report->unknownTokens());
            if ($invalid !== []) {
                FcmDeviceToken::query()->whereIn('token', $invalid)->delete();
            }

            return [
                'ok' => $report->successes()->count() > 0,
                'sent' => $report->successes()->count(),
                'failed' => $report->failures()->count(),
                'reason' => null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('FCM test push gagal: '.$e->getMessage());

            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Id user yang berhak menerima push alarm untuk OLT ini (selaras {@see User::isOltScoped()}):
     * - admin → semua OLT,
     * - operator TANPA assignment → semua OLT (assignment opsional),
     * - operator/partner DENGAN assignment → hanya OLT yang termasuk assignment-nya.
     *
     * @return array<int, int>
     */
    private function recipientUserIds(SnmpOlt $olt): array
    {
        $ids = [];

        // Admin & operator mengikuti saklar alarm OLT (`snmp_olts.alarms_enabled`) — operator
        // "ngikut administrator". Saat off, admin+operator tak menerima push untuk OLT ini.
        // OLT PRIVAT milik partner (`owner_user_id`) tak pernah memberi tahu admin/operator —
        // hanya partner pemiliknya (ditangani blok partner di bawah).
        if ($olt->owner_user_id === null && $olt->alarms_enabled) {
            $ids = User::query()
                ->where(function ($q) use ($olt) {
                    // Admin: semua OLT.
                    $q->where('role', UserRole::Admin->value)
                        // Operator tanpa assignment: semua OLT.
                        ->orWhere(fn ($p) => $p->where('role', UserRole::Operator->value)
                            ->whereDoesntHave('partnerOlts'))
                        // Operator dengan assignment: hanya OLT yang di-assign.
                        ->orWhere(fn ($p) => $p->where('role', UserRole::Operator->value)
                            ->whereHas('partnerOlts', fn ($r) => $r->whereKey($olt->id)));
                })
                ->pluck('id')
                ->all();
        }

        // Partner: independen dari saklar admin — hanya bila di-assign ke OLT ini DAN saklar
        // alarm partner utk OLT itu on (`olt_user.alarms_enabled`).
        $partnerIds = User::query()
            ->where('role', UserRole::Partner->value)
            ->whereHas('partnerOlts', fn ($r) => $r->whereKey($olt->id)
                ->where('olt_user.alarms_enabled', true))
            ->pluck('id')
            ->all();

        return array_values(array_unique([...$ids, ...$partnerIds]));
    }

    private function buildMessage(SnmpOlt $olt, AlarmEvent $alarm, string $event): CloudMessage
    {
        // Label jenis menyadari teknologi PON OLT (EPON tak tertulis "GPON") untuk judul notifikasi.
        $typeLabel = AlarmEvent::typeLabel($alarm->type, SmartOltSupport::ponLabel($olt));
        $emoji = $event === 'cleared' ? '✅' : $this->severityEmoji($alarm->severity);

        $title = $event === 'cleared'
            ? "$emoji CLEAR · $typeLabel"
            : "$emoji ".strtoupper($alarm->severity)." · $typeLabel";

        $body = trim(($alarm->message ?? $typeLabel).' — '.$olt->name);

        // Sertakan nama pelanggan (dari meta alarm) di body & payload agar teknisi tahu ONU siapa.
        $customer = SmartOltSupport::cleanCustomerName(
            data_get($alarm->meta, 'customer_name'),
            (string) $alarm->serial_number,
        );

        if ($customer !== null) {
            $body .= "\n👤 ".$customer;
        }

        // Semua nilai data payload wajib string.
        $data = array_map(
            fn ($v) => (string) ($v ?? ''),
            [
                'alarm_id' => $alarm->id,
                'event' => $event,
                'type' => $alarm->type,
                'severity' => $alarm->severity,
                'olt_id' => $olt->id,
                'slot' => $alarm->slot,
                'port' => $alarm->port,
                'onu_id' => $alarm->onu_id,
                'serial_number' => $alarm->serial_number,
                'customer_name' => $customer,
            ],
        );

        return CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($data)
            ->withDefaultSounds()
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => ['channel_id' => 'alarms'],
            ]);
    }

    /**
     * Satu push untuk sekumpulan ONU down di satu ODP.
     *
     * @param  array<string, mixed>  $item
     */
    private function buildOdpMessage(SnmpOlt $olt, array $item): CloudMessage
    {
        $emoji = $this->severityEmoji($item['severity']);
        $name = (string) $item['odp_name'];
        $count = count($item['members']);

        // Semua ONU 1 ODP down → cukup "ODP ini down (semua)".
        if ($item['all_down']) {
            $title = "$emoji ODP DOWN · $name";
            $body = "ODP $name — semua {$item['total']} pelanggan offline. — {$olt->name}";
        } else {
            $title = "$emoji ODP gangguan · $name ($count ONU)";
            $names = array_map(
                fn (AlarmEvent $alarm) => OdpAlarmGrouper::memberLabel($alarm),
                $item['members'],
            );
            $body = "ODP $name — $count ONU down: ".implode(', ', $names)." — {$olt->name}";
        }

        $data = array_map(
            fn ($v) => (string) ($v ?? ''),
            [
                'event' => 'raised',
                'group' => 'odp',
                'odp_id' => $item['odp_id'],
                'odp_name' => $name,
                'olt_id' => $olt->id,
                'down_count' => $count,
                'all_down' => $item['all_down'] ? '1' : '0',
            ],
        );

        return CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($data)
            ->withDefaultSounds()
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => ['channel_id' => 'alarms'],
            ]);
    }

    private function severityEmoji(string $severity): string
    {
        return match ($severity) {
            AlarmEvent::SEVERITY_CRITICAL => '🔴',
            AlarmEvent::SEVERITY_MAJOR => '🟠',
            AlarmEvent::SEVERITY_MINOR => '🟡',
            default => '🔵',
        };
    }
}
