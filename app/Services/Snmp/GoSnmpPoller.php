<?php

namespace App\Services\Snmp;

use App\Models\SnmpOlt;
use RuntimeException;
use Symfony\Component\Process\Process;

class GoSnmpPoller
{
    public function enabled(): bool
    {
        $binary = $this->binaryPath();

        return config('services.snmp_poller.driver') === 'go'
            && is_file($binary)
            && is_executable($binary);
    }

    /**
     * @return array<string, mixed>
     */
    public function poll(SnmpOlt $olt, bool $includeRx): array
    {
        if ($olt->snmp_version === 'v3') {
            throw new RuntimeException('Go SNMP poller belum mendukung SNMP v3.');
        }

        $command = [
            $this->binaryPath(),
            '--host',
            $olt->ip,
            '--port',
            (string) $olt->snmp_port,
            '--version',
            $olt->snmp_version,
            '--timeout',
            (string) config('services.snmp_poller.request_timeout', '5s'),
            '--retries',
            (string) config('services.snmp_poller.retries', 2),
            '--walk-mode',
            (string) config('services.snmp_poller.walk_mode', 'auto'),
            '--max-repetitions',
            (string) config('services.snmp_poller.max_repetitions', 25),
        ];

        if ($includeRx) {
            $command[] = '--include-rx';
        }

        $process = new Process(
            $command,
            base_path(),
            ['KV_SNMP_COMMUNITY' => $olt->snmp_read_community],
            null,
            (float) config('services.snmp_poller.process_timeout', 300),
        );
        $process->run();

        $output = trim($process->getOutput());

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: $output ?: 'Go SNMP poller failed.';
            throw new RuntimeException($error);
        }

        $data = json_decode($output, true);
        if (! is_array($data)) {
            throw new RuntimeException('Go SNMP poller returned invalid JSON.');
        }

        if (($data['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($data['error'] ?? 'Go SNMP poller returned failed result.'));
        }

        return $data;
    }

    private function binaryPath(): string
    {
        $path = (string) config('services.snmp_poller.binary', base_path('bin/kv-snmp-poller'));

        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }
}
