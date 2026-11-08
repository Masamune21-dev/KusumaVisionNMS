<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Services\AlarmEvaluator;
use App\Support\SystemText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teks alarm disimpan ke DB & dikirim ke Telegram/push, jadi bahasanya mengikuti bahasa
 * instalasi (APP_LOCALE → config app.system_locale), bukan bahasa UI user yang sedang aktif.
 * Laporan pengguna publik (issue #3): instalasi berbahasa Inggris masih menerima
 * "OLT tidak dapat dihubungi: …".
 */
class AlarmSystemLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(array $lastTestResult): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'DEMO-OLT-01',
            'vendor' => 'ZTE C320',
            'ip' => '10.30.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => $lastTestResult,
        ]);
    }

    public function test_english_installation_writes_english_alarm_messages(): void
    {
        config(['app.system_locale' => 'en']);

        $unreachable = ['ok' => false, 'error' => 'SNMP timeout'];
        $olt = $this->makeOlt($unreachable);
        $evaluator = new AlarmEvaluator;
        $evaluator->evaluate($olt, ['ok' => true]);
        $evaluator->evaluate($olt, $unreachable);

        $alarm = AlarmEvent::where('type', 'olt_unreachable')->firstOrFail();
        $this->assertSame('OLT unreachable: SNMP timeout', $alarm->message);
    }

    public function test_indonesian_installation_keeps_indonesian_alarm_messages(): void
    {
        config(['app.system_locale' => 'id']);

        $unreachable = ['ok' => false, 'error' => 'SNMP timeout'];
        $olt = $this->makeOlt($unreachable);
        $evaluator = new AlarmEvaluator;
        $evaluator->evaluate($olt, ['ok' => true]);
        $evaluator->evaluate($olt, $unreachable);

        $alarm = AlarmEvent::where('type', 'olt_unreachable')->firstOrFail();
        $this->assertSame('OLT tidak dapat dihubungi: SNMP timeout', $alarm->message);
    }

    public function test_system_text_ignores_the_ui_locale_of_the_current_request(): void
    {
        config(['app.system_locale' => 'en']);
        app()->setLocale('id'); // user yang memicu aksi memakai UI Indonesia

        $this->assertSame('en', SystemText::locale());
        $this->assertSame(
            'EPON port 1/2 enabled again from the NMS by admin.',
            SystemText::get('alarm.msg.port_enabled_nms', [
                'pon' => 'EPON',
                'port' => '1/2',
                'by' => SystemText::get('alarm.msg.by_actor', ['actor' => 'admin']),
            ]),
        );
    }

    public function test_type_label_follows_the_given_locale_and_pon_technology(): void
    {
        $this->assertSame('EPON port down', AlarmEvent::typeLabel(AlarmEvent::TYPE_PORT_DOWN, 'EPON', 'en'));
        $this->assertSame('Port EPON down', AlarmEvent::typeLabel(AlarmEvent::TYPE_PORT_DOWN, 'EPON', 'id'));
        $this->assertSame('High RX attenuation / out of range', AlarmEvent::typeLabel(AlarmEvent::TYPE_HIGH_RX, 'GPON', 'en'));
        $this->assertSame('mystery_type', AlarmEvent::typeLabel('mystery_type', 'GPON', 'en'));
    }

    public function test_every_alarm_type_has_a_translation_in_both_languages(): void
    {
        foreach (['id', 'en'] as $locale) {
            foreach (AlarmEvent::types() as $type) {
                $this->assertNotSame(
                    "alarm.type.{$type}",
                    __("alarm.type.{$type}", [], $locale),
                    "Label alarm '{$type}' belum ada di lang/{$locale}/alarm.php",
                );
            }
        }
    }
}
