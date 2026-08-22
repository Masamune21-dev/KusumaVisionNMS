<?php

namespace Tests\Unit;

use App\Models\SnmpOlt;
use App\Services\ZteCliProvisioningExecutor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Sesi CLI yang mendarat di user-mode prompt (`ZXAN>`) — akun CLI ber-privilege rendah.
 * Di mode itu `terminal length 0` ditolak `%Error 20200`, padahal `show` sesudahnya jalan.
 */
class ZteCliExecutorPrivilegeTest extends TestCase
{
    private function invoke(string $method, array $args): mixed
    {
        $ref = new ReflectionMethod(ZteCliProvisioningExecutor::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs(new ZteCliProvisioningExecutor, $args);
    }

    /** Transkrip nyata dari OLT pengguna: pager off ditolak, `show` sesudahnya tetap berhasil. */
    private const USER_MODE_TRANSCRIPT = <<<'CLI'
ZXAN>
> terminal length 0

      ^
%Error 20200: Invalid input detected at '^' marker.Invalid command
ZXAN>
> show running-config interface gpon-onu_1/3/5:1

Building configuration...
interface gpon-onu_1/3/5:1
  tcont 1 profile GPON-1G
ZXAN>
CLI;

    public function test_rejected_pager_command_does_not_fail_the_session(): void
    {
        $this->assertNull($this->invoke('detectError', [self::USER_MODE_TRANSCRIPT]));
    }

    public function test_rejected_enable_does_not_fail_the_session(): void
    {
        $output = "\n> enable\n%Error 20200: Invalid input detected at '^' marker.Invalid command\nZXAN>";

        $this->assertNull($this->invoke('detectError', [$output]));
    }

    public function test_real_command_error_still_fails_the_session(): void
    {
        $output = "> terminal length 0\n%Error 20200: Invalid input detected at '^' marker.Invalid command\n"
            ."> show running-config interface gpon-onu_1/3/5:1\n%Error 3005: The interface does not exist.\nZXAN>";

        $error = $this->invoke('detectError', [$output]);

        $this->assertIsString($error);
        $this->assertStringContainsString('show running-config interface gpon-onu_1/3/5:1', $error);
        $this->assertStringNotContainsString('terminal length 0', $error);
    }

    public function test_detects_user_mode_prompt_only(): void
    {
        $this->assertTrue($this->invoke('hasUserModePrompt', ["Welcome\r\nZXAN>"]));
        $this->assertTrue($this->invoke('hasUserModePrompt', ["Welcome\r\nOLT-C320-PATI> "]));
        $this->assertFalse($this->invoke('hasUserModePrompt', ["Welcome\r\nBMKV-C300#"]));
        $this->assertFalse($this->invoke('hasUserModePrompt', ["password is not strong\r\n"]));
    }

    public function test_password_prompt_detected_only_at_tail(): void
    {
        $this->assertTrue($this->invoke('hasPasswordPrompt', ["\n> enable\nPassword: "]));
        // Banner login firmware ini memuat kata "password" — jangan dikira prompt.
        $this->assertFalse($this->invoke('hasPasswordPrompt', ["% The password is not strong, please change the password.\r\nZXAN>"]));
    }

    /** @return array{0:resource,1:resource} */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return $pair;
    }

    public function test_enable_is_sent_when_session_lands_in_user_mode(): void
    {
        [$ours, $peer] = $this->socketPair();
        fwrite($peer, "\r\nBMKV-C300#"); // balasan OLT untuk `enable`

        $output = $this->invoke('enterPrivileged', [$ours, new SnmpOlt, "Welcome\r\nZXAN>"]);

        $this->assertSame("enable\n", stream_get_contents($peer));
        $this->assertStringContainsString('> enable', $output);
        $this->assertStringContainsString('BMKV-C300#', $output);

        fclose($ours);
        fclose($peer);
    }

    public function test_enable_is_skipped_when_already_privileged(): void
    {
        [$ours, $peer] = $this->socketPair();

        $output = $this->invoke('enterPrivileged', [$ours, new SnmpOlt, "Welcome\r\nBMKV-C300#"]);

        $this->assertSame('', $output);
        $this->assertSame('', stream_get_contents($peer));

        fclose($ours);
        fclose($peer);
    }
}
