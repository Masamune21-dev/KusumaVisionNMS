<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AlarmEvent;
use App\Models\PollingEvent;
use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Mengisi database DEMO dengan data contoh realistis.
 *
 * PERINGATAN: jangan jalankan di database produksi — seeder ini membuat OLT,
 * ONU, alarm, dan registrasi palsu. Tujukan untuk instance/DB demo terpisah.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUsers();

        $oltPati = $this->seedOlt('OLT-DEMO-PATI', '10.10.10.1', 'ZTE ZXA10 C320', slots: [1], portsPerSlot: 4);
        $oltJuwana = $this->seedOlt('OLT-DEMO-JUWANA', '10.10.20.1', 'ZTE ZXA10 C300', slots: [1, 2], portsPerSlot: 2);

        foreach ([$oltPati, $oltJuwana] as $olt) {
            $this->seedPolling($olt);
            $this->seedAlarms($olt);
            $this->seedRegistrations($olt);
        }
    }

    private function seedUsers(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@kusumavision.test'],
            ['name' => 'Admin Demo', 'role' => UserRole::Admin, 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        User::updateOrCreate(
            ['email' => 'demo@kusumavision.test'],
            ['name' => 'Pengguna Demo', 'role' => UserRole::Demo, 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
    }

    /**
     * @param  array<int,int>  $slots
     */
    private function seedOlt(string $name, string $ip, string $model, array $slots, int $portsPerSlot): SnmpOlt
    {
        $ports = [];
        $portOnus = [];
        $serialCounter = 1;

        foreach ($slots as $slot) {
            for ($port = 1; $port <= $portsPerSlot; $port++) {
                $portUp = ! ($slot === $slots[0] && $port === $portsPerSlot); // satu port sengaja down
                $ports[] = [
                    'slot' => $slot,
                    'port' => $port,
                    'name' => "gpon-olt_{$slot}/1/{$port}",
                    'oper_status' => $portUp ? 'up' : 'down',
                    'admin_status' => 'up',
                ];

                $onus = [];
                $onuCount = $portUp ? random_int(3, 6) : 0;
                for ($onuId = 1; $onuId <= $onuCount; $onuId++) {
                    $online = ! ($onuId === 1 && $port === 1); // sebagian offline untuk variasi
                    $rx = $this->demoRxPower($onuId, $port);
                    $sn = sprintf('ZTEGC%06d', $serialCounter++);
                    $onus[] = [
                        'onu_id' => $onuId,
                        'interface' => "gpon-onu_{$slot}/1/{$port}:{$onuId}",
                        'serial_number' => $sn,
                        'name' => "Pelanggan {$slot}/{$port}-{$onuId}",
                        'description' => 'Demo ONU',
                        'online' => $online,
                        'rx_power' => $online ? $rx : null,
                        'rx_power_dbm' => $online ? $rx : null,
                        'rx_power_label' => $online ? sprintf('%.3f dBm', $rx) : null,
                        'rx_power_source' => 'snmp_onu_rx',
                    ];
                }

                $portOnus["{$slot}_{$port}"] = [
                    'ok' => true,
                    'slot' => $slot,
                    'port' => $port,
                    'count' => count($onus),
                    'onus' => $onus,
                    'rx_power' => [],
                    'refreshed_at' => now()->toIso8601String(),
                ];
            }
        }

        $lastTestResult = [
            'ok' => true,
            'driver' => 'zte',
            'latency_ms' => random_int(8, 40),
            'system' => [
                'sysDescr' => "{$model} Software Version V2.1.0 (Demo)",
                'sysName' => $name,
                'descr' => $model,
                'uptime' => '15 days, 4 hours',
            ],
            'ports' => $ports,
            'port_onus' => $portOnus,
            'error' => null,
        ];

        return SnmpOlt::withoutGlobalScopes()->updateOrCreate(
            ['ip' => $ip],
            [
                'name' => $name,
                'vendor' => 'zte',
                'is_demo' => true,
                'snmp_port' => 161,
                'snmp_read_community' => 'public',
                'snmp_write_community' => 'private',
                'snmp_version' => 'v2c',
                'cli_transport' => 'telnet',
                'cli_port' => 23,
                'cli_username' => 'demo',
                'cli_password' => 'demo',
                'polling_enabled' => true,
                'poll_interval_minutes' => 5,
                'rx_poll_interval_minutes' => 15,
                'last_test_result' => $lastTestResult,
                'last_tested_at' => now(),
                'last_polled_at' => now()->subMinutes(random_int(1, 5)),
                'last_rx_polled_at' => now()->subMinutes(random_int(1, 10)),
            ],
        );
    }

    private function demoRxPower(int $onuId, int $port): float
    {
        $base = -20 - (($onuId + $port) % 10);          // -20 .. -29

        return round($base - (random_int(0, 30) / 10), 2); // sebagian masuk warning/critical
    }

    private function seedPolling(SnmpOlt $olt): void
    {
        $now = now();
        for ($i = 0; $i < 200; $i++) {
            $createdAt = $now->copy()->subMinutes($i * 30);
            $success = random_int(1, 100) > 8; // ~8% gagal
            PollingEvent::create([
                'snmp_olt_id' => $olt->id,
                'kind' => PollingEvent::KIND_OLT_POLL,
                'success' => $success,
                'message' => $success ? null : 'Demo: SNMP timeout',
                'duration_ms' => random_int(50, 800),
                'is_demo' => true,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    private function seedAlarms(SnmpOlt $olt): void
    {
        $samples = [
            ['type' => 'onu_offline', 'severity' => AlarmEvent::SEVERITY_MAJOR, 'status' => AlarmEvent::STATUS_ACTIVE, 'message' => 'ONU offline terdeteksi pada PON 1', 'slot' => 1, 'port' => 1],
            ['type' => 'rx_power_low', 'severity' => AlarmEvent::SEVERITY_WARNING, 'status' => AlarmEvent::STATUS_ACTIVE, 'message' => 'RX power rendah (< -27 dBm)', 'slot' => 1, 'port' => 2],
            ['type' => 'port_down', 'severity' => AlarmEvent::SEVERITY_CRITICAL, 'status' => AlarmEvent::STATUS_ACTIVE, 'message' => 'GPON port down', 'slot' => 1, 'port' => 4],
            ['type' => 'olt_unreachable', 'severity' => AlarmEvent::SEVERITY_MINOR, 'status' => AlarmEvent::STATUS_CLEARED, 'message' => 'OLT sempat tidak terjangkau', 'slot' => null, 'port' => null],
        ];

        foreach ($samples as $i => $sample) {
            $firstSeen = now()->subDays($i + 1)->subHours(random_int(0, 12));
            AlarmEvent::create([
                'snmp_olt_id' => $olt->id,
                'signature' => "demo-{$olt->id}-{$sample['type']}-{$i}",
                'type' => $sample['type'],
                'severity' => $sample['severity'],
                'status' => $sample['status'],
                'scope' => $sample['port'] ? 'pon' : 'olt',
                'slot' => $sample['slot'],
                'port' => $sample['port'],
                'message' => $sample['message'],
                'is_demo' => true,
                'first_seen_at' => $firstSeen,
                'last_seen_at' => $sample['status'] === AlarmEvent::STATUS_ACTIVE ? now()->subHours(random_int(0, 5)) : $firstSeen->copy()->addHour(),
                'cleared_at' => $sample['status'] === AlarmEvent::STATUS_CLEARED ? $firstSeen->copy()->addHour() : null,
            ]);
        }
    }

    private function seedRegistrations(SnmpOlt $olt): void
    {
        $statuses = ['executed', 'executed', 'generated', 'failed'];
        foreach ($statuses as $i => $status) {
            $createdAt = now()->subDays($i)->subHours(random_int(0, 10));
            $port = ($i % 4) + 1;
            SmartOltOnuRegistration::create([
                'snmp_olt_id' => $olt->id,
                'serial_number' => sprintf('ZTEGC9%05d', $olt->id * 10 + $i),
                'slot' => 1,
                'port' => $port,
                'pon_port' => "1/1/{$port}",
                'onu_id' => $i + 1,
                'customer_name' => "Pelanggan Baru {$i}",
                'onu_type' => 'ZTE-F660',
                'vlan' => 100 + $i,
                'wan_mode' => $i % 2 === 0 ? 'pppoe' : 'dhcp',
                'pppoe_username' => $i % 2 === 0 ? "user{$i}@demo" : null,
                'status' => $status,
                'cli_script' => '! demo provisioning script',
                'is_demo' => true,
                'executed_at' => in_array($status, ['executed'], true) ? $createdAt : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
