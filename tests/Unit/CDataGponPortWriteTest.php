<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\CData\CDataGponPortService;
use RuntimeException;
use Tests\TestCase;

/** CLI tiruan berskrip: rekam perintah, balas dari peta (string atau closure). */
class ScriptedCDataGponPortService extends CDataGponPortService
{
    /** @var list<string> */
    public array $sent = [];

    /**
     * @param  array<string, string|callable(self): string>  $responses
     */
    public function __construct(private array $responses) {}

    protected function openCliSession(SnmpOlt $olt)
    {
        return fopen('php://memory', 'r+');
    }

    protected function cliCommand($connection, string $command, float $max, bool $answerPager = false): string
    {
        $this->sent[] = $command;
        $reply = $this->responses[$command] ?? "{$command}\r\nOLT(config)# ";

        return is_callable($reply) ? $reply($this) : $reply;
    }

    public function sentTrunk(): bool
    {
        return (bool) preg_grep('/^vlan trunk /', $this->sent);
    }
}

class CDataGponPortWriteTest extends TestCase
{
    private const VLAN_28 = "show vlan 28\r\n  VLAN ID: 28\r\n  VLAN Description: UJI\r\n  VLAN Type: Normal vlan\r\n  Tagged Ports: \r\n    gpon 0/0/1\r\n  Untagged Ports:  none\r\n\r\nOLT(config)# ";

    private function olt(): SnmpOlt
    {
        return (new SnmpOlt)->forceFill(['name' => 'OLT-UJI', 'cli_password' => 'rahasia']);
    }

    private function portVlan(string $mode, string $tagged): string
    {
        return "show port vlan 1\r\n---------\r\n Port: xge 0/0/1   Mode: {$mode}  Native-Vlan: 1   Priority: 0 \r\n---------\r\n Tagged-Vlan:\r\n {$tagged}\r\n---------\r\n Untagged-Vlan:\r\n 1\r\n---------\r\nOLT(config-xge-0/0)# ";
    }

    /**
     * @param  callable(ScriptedCDataGponPortService): string  $portVlan
     */
    private function trunkService(callable $portVlan, string $vlanShow = self::VLAN_28): ScriptedCDataGponPortService
    {
        return new ScriptedCDataGponPortService([
            'show vlan 28' => $vlanShow,
            'interface xge 0/0' => "interface xge 0/0\r\nOLT(config-xge-0/0)# ",
            'show port vlan 1' => $portVlan,
        ]);
    }

    public function test_tag_sends_union_of_existing_and_new_vlan_then_verifies(): void
    {
        $svc = $this->trunkService(fn (ScriptedCDataGponPortService $s) => $s->sentTrunk()
            ? $this->portVlan('Trunk', '28,210,220,230,300')
            : $this->portVlan('Trunk', '210,220,230,300'));

        $result = $svc->tagPortVlan($this->olt(), 'xge', 0, 1, 28);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertFalse($result['already']);
        $this->assertContains('vlan trunk 1 28,210,220,230,300', $svc->sent);
        $this->assertSame(['28', '210', '220', '230', '300'], $result['tagged']);
    }

    public function test_tag_reports_missing_existing_vlans_after_command(): void
    {
        // Seolah `vlan trunk` MENGGANTI daftar dan hanya menyisakan VLAN baru.
        $svc = $this->trunkService(fn (ScriptedCDataGponPortService $s) => $s->sentTrunk()
            ? $this->portVlan('Trunk', '28')
            : $this->portVlan('Trunk', '210,220'));

        $result = $svc->tagPortVlan($this->olt(), 'xge', 0, 1, 28);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('210,220 hilang', $result['error']);
    }

    public function test_tag_refuses_non_trunk_port(): void
    {
        $svc = $this->trunkService(fn () => $this->portVlan('Access', '--'));

        $result = $svc->tagPortVlan($this->olt(), 'xge', 0, 1, 28);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Access', $result['error']);
        $this->assertFalse($svc->sentTrunk());
    }

    public function test_tag_refuses_unknown_vlan_before_entering_interface(): void
    {
        $svc = $this->trunkService(fn () => $this->portVlan('Trunk', '22'), "show vlan 28\r\nOLT(config)# ");

        $result = $svc->tagPortVlan($this->olt(), 'xge', 0, 1, 28);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('belum ada', $result['error']);
        $this->assertNotContains('interface xge 0/0', $svc->sent);
    }

    public function test_tag_already_present_is_a_no_op(): void
    {
        $svc = $this->trunkService(fn () => $this->portVlan('Trunk', '210,27-30'));

        $result = $svc->tagPortVlan($this->olt(), 'xge', 0, 1, 28);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['already']);
        $this->assertFalse($svc->sentTrunk());
    }

    public function test_tag_is_not_offered_for_gpon_ports(): void
    {
        // Port GPON otomatis ikut VLAN baru — tak ada yang perlu di-tag.
        $this->expectException(RuntimeException::class);

        $this->trunkService(fn () => '')->tagPortVlan($this->olt(), 'gpon', 0, 1, 28);
    }

    public function test_tag_hybrid_port_uses_vlan_hybrid_tagged_and_keeps_untagged(): void
    {
        // Uplink EPON FD1304E: Hybrid, tagged 225-227,450 + untagged 1.
        $svc = $this->trunkService(fn (ScriptedCDataGponPortService $s) => preg_grep('/^vlan hybrid /', $s->sent)
            ? $this->portVlan('Hybrid', '28,225-227,450')
            : $this->portVlan('Hybrid', '225-227,450'));

        $result = $svc->tagPortVlan($this->olt(), 'xge', 0, 1, 28);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertContains('vlan hybrid 1 tagged 28,225-227,450', $svc->sent);
        $this->assertFalse($svc->sentTrunk());
    }

    public function test_tag_reports_lost_untagged_vlan_on_hybrid_port(): void
    {
        $svc = $this->trunkService(fn (ScriptedCDataGponPortService $s) => preg_grep('/^vlan hybrid /', $s->sent)
            ? str_replace("Untagged-Vlan:\r\n 1", "Untagged-Vlan:\r\n --", $this->portVlan('Hybrid', '28,225-227'))
            : $this->portVlan('Hybrid', '225-227'));

        $result = $svc->tagPortVlan($this->olt(), 'xge', 0, 1, 28);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('VLAN 1 hilang', $result['error']);
    }

    public function test_tag_refuses_vlan_that_is_untagged_on_the_port(): void
    {
        $svc = new ScriptedCDataGponPortService([
            'show vlan 1' => str_replace('28', '1', self::VLAN_28),
            'interface xge 0/0' => "interface xge 0/0\r\nOLT(config-xge-0/0)# ",
            'show port vlan 1' => $this->portVlan('Hybrid', '225-227'),
        ]);

        $result = $svc->tagPortVlan($this->olt(), 'xge', 0, 1, 1);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('untagged', $result['error']);
        $this->assertSame([], preg_grep('/^vlan (trunk|hybrid) /', $svc->sent));
    }

    public function test_tag_epon_pon_port_enters_epon_slot(): void
    {
        $svc = new ScriptedCDataGponPortService([
            'show vlan 28' => self::VLAN_28,
            'interface epon 0/2' => "interface epon 0/2\r\nOLT(config-epon-0/2)# ",
            'show port vlan 3' => fn (ScriptedCDataGponPortService $s) => preg_grep('/^vlan hybrid /', $s->sent)
                ? $this->portVlan('Hybrid', '28,226-227,820')
                : $this->portVlan('Hybrid', '226-227,820'),
        ]);

        $result = $svc->tagPortVlan($this->olt(), 'epon', 2, 3, 28);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertContains('interface epon 0/2', $svc->sent);
        $this->assertContains('vlan hybrid 3 tagged 28,226-227,820', $svc->sent);
    }

    public function test_failed_interface_entry_aborts(): void
    {
        $svc = new ScriptedCDataGponPortService([
            'show vlan 28' => self::VLAN_28,
            'interface xge 0/0' => "interface xge 0/0\r\n Incorrect F/S parameters [0/0]!\r\nOLT(config)# ",
        ]);

        $this->expectException(RuntimeException::class);
        $svc->tagPortVlan($this->olt(), 'xge', 0, 1, 28);
    }

    public function test_create_vlan_sends_vlan_and_description_then_verifies(): void
    {
        $created = false;
        $svc = new ScriptedCDataGponPortService([
            'vlan 28' => function () use (&$created) {
                $created = true;

                return "vlan 28\r\n Create vlan successfully:\r\n   28 \r\nOLT(config)# ";
            },
            'show vlan 28' => function () use (&$created) {
                return $created ? self::VLAN_28 : "show vlan 28\r\nOLT(config)# ";
            },
        ]);

        $result = $svc->createVlan($this->olt(), 28, 'UJI dan spasi!');

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame(28, $result['vlan']['id']);
        // Karakter di luar [A-Za-z0-9._-] dibuang — CLI C-Data menerima satu kata.
        $this->assertContains('vlan description 28 UJIdanspasi', $svc->sent);
    }

    public function test_create_vlan_refuses_existing_vlan(): void
    {
        $svc = new ScriptedCDataGponPortService(['show vlan 28' => self::VLAN_28]);

        $result = $svc->createVlan($this->olt(), 28, null);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sudah ada', $result['error']);
        $this->assertNotContains('vlan 28', $svc->sent);
    }
}
