<?php

namespace App\Services;

/**
 * Builds a *delta* CLI script for reconfiguring an existing ONU: only the lines
 * that differ between the live baseline and the edited target are emitted
 * (guide Section 5.4). Also returns a human-readable change list for the UI.
 */
class ZteOnuReconfigureScriptBuilder
{
    /**
     * @param  array<string, mixed>  $baseline  parsed live running-config
     * @param  array<string, mixed>  $target  edited form values
     * @param  array{onu_iface:string}  $context
     * @return array{script:string, changes:array<int, array{label:string, from:string, to:string}>}
     */
    public function build(array $baseline, array $target, array $context): array
    {
        $changes = [];
        $ifaceLines = [];
        $mngLines = [];

        $this->diffName($baseline, $target, $ifaceLines, $changes);
        $this->diffTconts($baseline, $target, $ifaceLines, $changes);
        $this->diffGemports($baseline, $target, $ifaceLines, $changes);
        $this->diffServicePorts($baseline, $target, $ifaceLines, $changes);

        $this->diffServices($baseline, $target, $mngLines, $changes);
        $this->diffVlanPorts($baseline, $target, $mngLines, $changes);
        $this->diffWanServices($baseline, $target, $mngLines, $changes);
        $this->diffWanIps($baseline, $target, $mngLines, $changes);
        $this->diffTr069($baseline, $target, $mngLines, $changes);
        $this->diffRemoteOnt($baseline, $target, $mngLines, $changes);

        if ($ifaceLines === [] && $mngLines === []) {
            return ['script' => '', 'changes' => []];
        }

        $iface = $context['onu_iface'];
        $lines = ['conf t'];

        if ($ifaceLines !== []) {
            $lines[] = '';
            $lines[] = "interface {$iface}";
            $lines = array_merge($lines, $ifaceLines);
            $lines[] = 'exit';
        }

        if ($mngLines !== []) {
            $lines[] = '';
            $lines[] = "pon-onu-mng {$iface}";
            $lines = array_merge($lines, $mngLines);
            $lines[] = 'exit';
        }

        return ['script' => implode("\n", $lines), 'changes' => $changes];
    }

    /**
     * Build a *complete* (non-delta) provisioning script that reproduces a parsed
     * running-config onto a (possibly different) interface — backs the
     * "copy ONU config to another port" batch feature.
     *
     * Unlike build(), this diffs the config against an empty baseline (so every
     * directive is emitted) and prepends the OLT-side `onu N type T sn S`
     * registration line, so the target port ends up with the same services as
     * the source. `encrypt 1 enable downstream` is emitted to match the standard
     * registration produced by {@see ZteProvisioningScriptBuilder}.
     *
     * @param  array<string, mixed>  $config  parsed source running-config
     * @param  array{olt_iface:string, onu_iface:string, onu_id:int, sn:string, onu_type:string, is_c600?:bool}  $context
     */
    /**
     * Build a complete registration script from a granular config shape — backs
     * the "Register ONU · mode Lanjutan" page where the operator composes
     * tcont/gemport/service-port/service rows one by one instead of the fixed
     * single-service template of {@see ZteProvisioningScriptBuilder}. Same engine
     * as {@see buildForCopy} (full build from empty baseline + `onu N type T sn S`).
     *
     * @param  array<string, mixed>  $config  granular form values (name, tconts[], gemports[], …)
     * @param  array{olt_iface:string, onu_iface:string, onu_id:int, sn:string, onu_type:string, is_c600?:bool}  $context
     */
    public function buildForRegistration(array $config, array $context): string
    {
        return $this->buildForCopy($config, $context);
    }

    public function buildForCopy(array $config, array $context): string
    {
        $ifaceLines = [];
        $mngLines = [];
        $discard = [];

        $this->diffName([], $config, $ifaceLines, $discard);

        // C600 has no separate description OID (mirror ZteProvisioningScriptBuilder).
        if ($context['is_c600'] ?? false) {
            $ifaceLines = array_values(array_filter(
                $ifaceLines,
                static fn (string $line): bool => ! str_starts_with($line, 'description '),
            ));
        }

        $this->diffTconts([], $config, $ifaceLines, $discard);
        $this->diffGemports([], $config, $ifaceLines, $discard);
        $ifaceLines[] = 'encrypt 1 enable downstream';
        $this->diffServicePorts([], $config, $ifaceLines, $discard);

        $this->diffServices([], $config, $mngLines, $discard);
        $this->diffVlanPorts([], $config, $mngLines, $discard);
        // NB: the `wan N service …` binding is intentionally NOT emitted. ZTE shows
        // it in running-config but rejects it as an input command on C300
        // ("Invalid command key word"); the WAN connection is fully created by
        // `wan-ip N mode …` below — matching the verified ZteProvisioningScriptBuilder.
        $this->diffWanIps([], $config, $mngLines, $discard);
        $this->diffTr069([], $config, $mngLines, $discard);
        $this->diffRemoteOnt([], $config, $mngLines, $discard);

        $onuId = (int) $context['onu_id'];
        $type = strtoupper($this->str($context['onu_type'] ?? '')) ?: 'ALL-ONT';
        $sn = strtoupper($this->str($context['sn'] ?? ''));

        $lines = [
            'conf t',
            '',
            "interface {$context['olt_iface']}",
            "onu {$onuId} type {$type} sn {$sn}",
            'exit',
        ];

        if ($ifaceLines !== []) {
            $lines[] = '';
            $lines[] = "interface {$context['onu_iface']}";
            $lines = array_merge($lines, $ifaceLines);
            $lines[] = 'exit';
        }

        if ($mngLines !== []) {
            $lines[] = '';
            $lines[] = "pon-onu-mng {$context['onu_iface']}";
            $lines = array_merge($lines, $mngLines);
            $lines[] = 'exit';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<int, array{label:string, from:string, to:string}>  $changes
     */
    private function diffName(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $old = $this->str($baseline['name'] ?? null);
        $new = $this->str($target['name'] ?? null);

        if ($new === '' || $this->same($old, $new)) {
            return;
        }

        $lines[] = "name {$new}";
        $lines[] = "description {$new}";
        $changes[] = $this->change('Name', $old, $new);
    }

    private function diffTconts(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $base = $this->keyById($baseline['tconts'] ?? []);
        $next = $this->keyById($target['tconts'] ?? []);

        foreach ($next as $id => $row) {
            $name = $this->str($row['name'] ?? '1');
            $profile = $this->str($row['profile'] ?? '');
            if ($profile === '') {
                continue;
            }

            $prev = $base[$id] ?? null;
            if ($prev === null
                || ! $this->same($this->str($prev['name'] ?? ''), $name)
                || ! $this->same($this->str($prev['profile'] ?? ''), $profile)) {
                $lines[] = "tcont {$id} name {$name} profile {$profile}";
                $changes[] = $this->change("T-CONT {$id}", $this->fmtTcont($prev), "name {$name} profile {$profile}");
            }

            $gap = $this->str($row['gap'] ?? '');
            if ($gap !== '' && ! $this->same($gap, $this->str($prev['gap'] ?? ''))) {
                $lines[] = "tcont {$id} gap {$gap}";
                $changes[] = $this->change("T-CONT {$id} gap", $this->str($prev['gap'] ?? null), $gap);
            }
        }

        foreach ($base as $id => $row) {
            if (! isset($next[$id])) {
                $lines[] = "no tcont {$id}";
                $changes[] = $this->change("T-CONT {$id}", $this->fmtTcont($row), 'dihapus');
            }
        }
    }

    private function diffGemports(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $base = $this->keyById($baseline['gemports'] ?? []);
        $next = $this->keyById($target['gemports'] ?? []);

        foreach ($next as $id => $row) {
            $name = $this->str($row['name'] ?? '1');
            $tcont = $this->str($row['tcont'] ?? '1');
            $prev = $base[$id] ?? null;

            if ($prev === null
                || ! $this->same($name, $this->str($prev['name'] ?? ''))
                || ! $this->same($tcont, $this->str($prev['tcont'] ?? ''))) {
                $lines[] = "gemport {$id} name {$name} tcont {$tcont}";
                $changes[] = $this->change("GEM Port {$id}", $this->fmtGemport($prev), "name {$name} tcont {$tcont}");
            }

            $up = $this->str($row['traffic_up'] ?? '');
            $down = $this->str($row['traffic_down'] ?? '');
            if ($up !== '' && $down !== ''
                && (! $this->same($up, $this->str($prev['traffic_up'] ?? '')) || ! $this->same($down, $this->str($prev['traffic_down'] ?? '')))) {
                $lines[] = "gemport {$id} traffic-limit upstream {$up} downstream {$down}";
                $changes[] = $this->change("GEM Port {$id} traffic", '', "up {$up} down {$down}");
            }
        }

        foreach ($base as $id => $row) {
            if (! isset($next[$id])) {
                $lines[] = "no gemport {$id}";
                $changes[] = $this->change("GEM Port {$id}", $this->fmtGemport($row), 'dihapus');
            }
        }
    }

    private function diffServicePorts(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $base = $this->keyById($baseline['service_ports'] ?? []);
        $next = $this->keyById($target['service_ports'] ?? []);

        foreach ($next as $id => $row) {
            $vport = (int) ($row['vport'] ?? 1);
            $userVlan = (int) ($row['user_vlan'] ?? 0);
            $vlan = (int) ($row['vlan'] ?? 0);
            if ($userVlan < 1 || $vlan < 1) {
                continue;
            }

            $prev = $base[$id] ?? null;
            $newDesc = "vport {$vport} user-vlan {$userVlan} vlan {$vlan}";
            if ($prev === null || $this->fmtServicePort($prev) !== $newDesc) {
                // ZTE menolak membuat ulang service-port dengan id yang sudah ada
                // (%Code 66661: The service is already existed). Untuk MENGUBAH entri
                // yang sudah ada, hapus dulu lalu buat ulang. Untuk id baru (prev null,
                // mis. path registrasi/copy) cukup tambah langsung.
                if ($prev !== null) {
                    $lines[] = "no service-port {$id}";
                }
                $lines[] = "service-port {$id} {$newDesc}";
                $changes[] = $this->change("Service-port {$id}", $this->fmtServicePort($prev), $newDesc);
            }
        }

        foreach ($base as $id => $row) {
            if (! isset($next[$id])) {
                $lines[] = "no service-port {$id}";
                $changes[] = $this->change("Service-port {$id}", $this->fmtServicePort($row), 'dihapus');
            }
        }
    }

    private function diffServices(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $base = $this->keyBy($baseline['services'] ?? [], 'name');
        $next = $this->keyBy($target['services'] ?? [], 'name');

        foreach ($next as $name => $row) {
            $gem = (int) ($row['gem'] ?? 1);
            $cos = (int) ($row['cos'] ?? 0);
            $vlan = (int) ($row['vlan'] ?? 0);
            $mode = strtolower($this->str($row['mode'] ?? 'vlanpri')) === 'transparent' ? 'transparent' : 'vlanpri';
            // Mode transparent tidak memetakan vlan/cos, jadi vlan boleh kosong.
            if ($name === '' || ($mode !== 'transparent' && $vlan < 1)) {
                continue;
            }

            $prev = $base[$name] ?? null;
            // Pertahankan `type …` (mis. `type internet` gaya bridge Bulumanis Lor):
            // pakai type dari target, atau warisi dari baseline bila form tak
            // membawanya — supaya reconfigure tak menghapusnya secara diam-diam.
            $type = $this->str($row['type'] ?? '') ?: $this->str($prev['type'] ?? '');
            $newDesc = $this->serviceDesc($type, $mode, $gem, $cos, $vlan);
            if ($prev === null || $this->fmtService($prev) !== $newDesc) {
                // ZTE menolak menimpa service yang sudah ada di pon-onu-mng
                // (%Code 64007: forbidden for conflicting with applied u-profile).
                // Untuk MENGUBAH service yang sudah ada, hapus dulu lalu buat ulang.
                // Service baru (prev null) cukup ditambah langsung.
                if ($prev !== null) {
                    $lines[] = "no service {$name}";
                }
                $lines[] = "service {$name} {$newDesc}";
                $changes[] = $this->change("Service {$name}", $this->fmtService($prev), $newDesc);
            }
        }

        foreach ($base as $name => $row) {
            if (! isset($next[$name])) {
                $lines[] = "no service {$name}";
                $changes[] = $this->change("Service {$name}", $this->fmtService($row), 'dihapus');
            }
        }
    }

    private function diffVlanPorts(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $base = $this->keyByUni($baseline['vlan_ports'] ?? []);
        $next = $this->keyByUni($target['vlan_ports'] ?? []);

        foreach ($next as $key => $row) {
            $prev = $base[$key] ?? null;

            // Mode "na" = hapus mapping. ZTE menolak `mode na` (%Error 20202) dan
            // `no vlan port {token}` saja masih incomplete (%Error 20203) — yang
            // diterima OLT adalah `no vlan port {token} mode` (keyword `mode`
            // tanpa nilai, verifikasi live di C300).
            if (strtolower($this->str($row['mode'] ?? '')) === 'na') {
                $this->emitUniVlanDelete($key, $prev, $lines, $changes);

                continue;
            }

            $line = $this->vlanPortLine($row);
            if ($line === null) {
                continue;
            }

            if ($prev === null || $this->vlanPortLine($prev) !== $line) {
                $lines[] = $line;
                $changes[] = $this->change("UNI VLAN {$key}", $prev ? ($this->vlanPortLine($prev) ?? '') : '', $line);
            }
        }

        // Baris yang dibuang dari editor → hapus juga (negasi baris asli).
        foreach ($base as $key => $row) {
            if (! isset($next[$key])) {
                $this->emitUniVlanDelete($key, $row, $lines, $changes);
            }
        }
    }

    /**
     * Emit penghapusan UNI VLAN: `no vlan port {token} mode` — keyword `mode`
     * tanpa nilai (yang diterima ZTE C300; `mode na` invalid & `no vlan port
     * {token}` saja incomplete). Butuh baris baseline hanya untuk label & token.
     *
     * @param  array<string, mixed>|null  $original  baris baseline (running-config)
     * @param  array<int, string>  $lines
     * @param  array<int, array{label:string, from:string, to:string}>  $changes
     */
    private function emitUniVlanDelete(string $key, ?array $original, array &$lines, array &$changes): void
    {
        if ($original === null || ($token = $this->uniToken($original)) === null) {
            return;
        }

        $lines[] = "no vlan port {$token} mode";
        $changes[] = $this->change("UNI VLAN {$key}", $this->vlanPortLine($original) ?? '', 'dihapus');
    }

    private function diffWanServices(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $base = $this->keyById($baseline['wan_services'] ?? []);
        $next = $this->keyById($target['wan_services'] ?? []);

        foreach ($next as $id => $row) {
            $newLine = $this->wanServiceLine($id, $row);
            if ($newLine === null) {
                continue;
            }

            $prev = $base[$id] ?? null;
            $oldLine = $prev !== null ? $this->wanServiceLine($id, $prev) : null;

            if ($oldLine !== $newLine) {
                $lines[] = $newLine;
                $changes[] = $this->change("WAN binding {$id}", $oldLine ?? '', $newLine);
            }
        }

        foreach ($base as $id => $row) {
            if (! isset($next[$id])) {
                $lines[] = "no wan {$id}";
                $changes[] = $this->change("WAN binding {$id}", $this->wanServiceLine($id, $row) ?? '', 'dihapus');
            }
        }
    }

    /**
     * Build a `wan {id} service ...` line with only the tokens that are set.
     * MVLAN is only meaningful when the `other` service type is selected.
     *
     * @param  array<string, mixed>  $row
     */
    private function wanServiceLine(int $id, array $row): ?string
    {
        $services = $this->normalizeServices($row['services'] ?? ($row['service'] ?? []));
        if ($services === []) {
            return null;
        }

        $line = "wan {$id} service ".implode(' ', $services);

        $mvlan = $this->str($row['mvlan'] ?? '');
        if (in_array('other', $services, true) && $mvlan !== '') {
            $line .= " mvlan {$mvlan}";
        }

        foreach (['ethuni', 'ssid', 'host'] as $token) {
            $value = $this->str($row[$token] ?? '');
            if ($value !== '') {
                $line .= " {$token} {$value}";
            }
        }

        return $line;
    }

    /**
     * @param  mixed  $value  array of types or a legacy space/comma string
     * @return array<int, string>
     */
    private function normalizeServices(mixed $value): array
    {
        $allowed = ['internet', 'tr069', 'voip', 'other'];
        $tokens = is_array($value)
            ? $value
            : (preg_split('/[\s,]+/', $this->str($value)) ?: []);

        $tokens = array_map(static fn ($t): string => strtolower(trim((string) $t)), $tokens);

        return array_values(array_filter($allowed, static fn (string $type): bool => in_array($type, $tokens, true)));
    }

    private function diffWanIps(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $base = $this->keyById($baseline['wan_ips'] ?? []);
        $next = $this->keyById($target['wan_ips'] ?? []);

        foreach ($next as $id => $row) {
            $mode = strtolower($this->str($row['mode'] ?? 'pppoe'));
            if ($mode === '' || $mode === 'none') {
                continue;
            }

            $prev = $base[$id] ?? null;

            $newLine = $this->wanIpLine($row, $mode, $id);
            $oldLine = $prev !== null ? $this->wanIpLine($prev, strtolower($this->str($prev['mode'] ?? 'pppoe')), $id) : null;

            if ($oldLine !== $newLine) {
                $lines[] = $newLine;
                $changes[] = $this->change("WAN-IP {$id} mode", $prev !== null ? strtolower($this->str($prev['mode'] ?? '')) : '', $mode);
            }

            $newPing = (bool) ($row['ping_response'] ?? false);
            $newTrace = (bool) ($row['traceroute_response'] ?? false);
            $oldPing = $prev !== null && (bool) ($prev['ping_response'] ?? false);
            $oldTrace = $prev !== null && (bool) ($prev['traceroute_response'] ?? false);

            $probeChanged = $prev === null
                ? ($newPing || $newTrace)
                : ($newPing !== $oldPing || $newTrace !== $oldTrace);

            if ($probeChanged) {
                $lines[] = sprintf(
                    'wan-ip %d ping-response %s traceroute-response %s',
                    $id,
                    $newPing ? 'enable' : 'disable',
                    $newTrace ? 'enable' : 'disable',
                );
                $changes[] = $this->change(
                    "WAN-IP {$id} probe",
                    $prev !== null ? $this->fmtProbe($oldPing, $oldTrace) : '',
                    $this->fmtProbe($newPing, $newTrace),
                );
            }
        }

        foreach ($base as $id => $row) {
            if (! isset($next[$id])) {
                $lines[] = "no wan-ip {$id}";
                $changes[] = $this->change("WAN-IP {$id}", strtolower($this->str($row['mode'] ?? '')) ?: 'aktif', 'dihapus');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function wanIpLine(array $row, string $mode, int $id): string
    {
        if ($mode === 'dhcp') {
            $line = "wan-ip {$id} mode dhcp";
        } elseif ($mode === 'static') {
            $line = sprintf(
                'wan-ip %d mode static ip-profile %s ip-address %s mask %s',
                $id,
                $this->str($row['ip_profile'] ?? ''),
                $this->str($row['static_ip'] ?? ''),
                $this->lengthToMask((int) ($row['static_mask_length'] ?? 24)),
            );
        } else {
            $user = $this->str($row['pppoe_username'] ?? '');
            $pass = $this->str($row['pppoe_password'] ?? '') ?: $user;
            $line = "wan-ip {$id} mode pppoe username {$user} password {$pass}";
        }

        $vlanProfile = $this->str($row['vlan_profile'] ?? '');
        if ($vlanProfile !== '') {
            $line .= " vlan-profile {$vlanProfile}";
        }

        $host = (int) ($row['host'] ?? 1);

        return $line.' host '.($host > 0 ? $host : 1);
    }

    private function fmtProbe(bool $ping, bool $trace): string
    {
        return sprintf('ping %s / traceroute %s', $ping ? 'on' : 'off', $trace ? 'on' : 'off');
    }

    private function diffTr069(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $old = (bool) ($baseline['tr069'] ?? false);
        $new = (bool) ($target['tr069'] ?? false);

        if ($old === $new) {
            // Even when enabled state is unchanged, re-emit ACS if its credentials changed.
            if ($new && ! $this->same($this->str($baseline['acs_url'] ?? null), $this->str($target['acs_url'] ?? null))) {
                $lines[] = $this->acsLine($target);
                $changes[] = $this->change('TR069 ACS', $this->str($baseline['acs_url'] ?? null), $this->str($target['acs_url'] ?? null));
            }

            return;
        }

        if ($new) {
            $lines[] = 'tr069-mgmt 1 state unlock';
            $lines[] = $this->acsLine($target);
            $changes[] = $this->change('TR069', 'off', 'on');
        } else {
            $lines[] = 'tr069-mgmt 1 state lock';
            $changes[] = $this->change('TR069', 'on', 'off');
        }
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function acsLine(array $target): string
    {
        return sprintf(
            'tr069-mgmt 1 acs %s validate basic username %s password %s',
            $this->str($target['acs_url'] ?? ''),
            $this->str($target['acs_username'] ?? ''),
            $this->str($target['acs_password'] ?? ''),
        );
    }

    private function diffRemoteOnt(array $baseline, array $target, array &$lines, array &$changes): void
    {
        $old = (bool) ($baseline['remote_ont'] ?? false);
        $new = (bool) ($target['remote_ont'] ?? false);
        $id = (int) ($target['remote_ont_id'] ?? $baseline['remote_ont_id'] ?? 1);

        if ($old === $new) {
            if ($new && (
                ! $this->same($this->str($baseline['remote_ont_mode'] ?? null), $this->str($target['remote_ont_mode'] ?? null))
                || ! $this->same($this->str($baseline['remote_ont_protocol'] ?? null), $this->str($target['remote_ont_protocol'] ?? null))
            )) {
                $lines[] = sprintf(
                    'security-mgmt %d state enable mode %s protocol %s',
                    $id,
                    $this->str($target['remote_ont_mode'] ?? 'forward'),
                    $this->str($target['remote_ont_protocol'] ?? 'web'),
                );
                $changes[] = $this->change('Remote ONT', 'diperbarui', 'diperbarui');
            }

            return;
        }

        if ($new) {
            $lines[] = sprintf(
                'security-mgmt %d state enable mode %s protocol %s',
                $id,
                $this->str($target['remote_ont_mode'] ?? 'forward'),
                $this->str($target['remote_ont_protocol'] ?? 'web'),
            );
            $changes[] = $this->change('Remote ONT', 'off', 'on');
        } else {
            $lines[] = "security-mgmt {$id} state disable";
            $changes[] = $this->change('Remote ONT', 'on', 'off');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function uniToken(array $row): ?string
    {
        $port = (int) ($row['port'] ?? 0);
        if ($port < 1) {
            return null;
        }

        // VEIP memakai token `veip_{N}` (tanpa `0/`); eth/wifi memakai `{type}_0/{N}`.
        return match (strtolower($this->str($row['port_type'] ?? 'eth'))) {
            'wifi' => "wifi_0/{$port}",
            'veip' => "veip_{$port}",
            default => "eth_0/{$port}",
        };
    }

    private function vlanPortLine(array $row): ?string
    {
        $token = $this->uniToken($row);
        $mode = strtolower($this->str($row['mode'] ?? ''));
        // Mode "na" adalah sentinel UI (hapus) — bukan baris set; ditangani di
        // diffVlanPorts (negasi baris asli). Di sini ia bukan baris valid.
        if ($token === null || $mode === '' || $mode === 'na') {
            return null;
        }

        $line = "vlan port {$token} mode {$mode}";

        if (($row['vlan'] ?? null) !== null && $row['vlan'] !== '') {
            $line .= ' vlan '.(int) $row['vlan'];
        }

        // Def-VLAN & priority hanya relevan untuk mode bertag (tag/hybrid). Mode
        // trunk & transparent tidak memetakan keduanya, jadi tidak di-emit.
        if (! in_array(strtolower($mode), ['trunk', 'transparent'], true)) {
            if (($row['def_vlan'] ?? null) !== null && $row['def_vlan'] !== '') {
                $line .= ' def-vlan '.(int) $row['def_vlan'];
            }
            if (($row['priority'] ?? null) !== null && $row['priority'] !== '') {
                $line .= ' priority '.(int) $row['priority'];
            }
        }

        return $line;
    }

    // --- formatting helpers for the change list ---

    private function fmtTcont(?array $row): string
    {
        return $row ? sprintf('name %s profile %s', $this->str($row['name'] ?? ''), $this->str($row['profile'] ?? '')) : '';
    }

    private function fmtGemport(?array $row): string
    {
        return $row ? sprintf('name %s tcont %s', $this->str($row['name'] ?? ''), $this->str($row['tcont'] ?? '')) : '';
    }

    private function fmtServicePort(?array $row): string
    {
        return $row ? sprintf('vport %d user-vlan %d vlan %d', (int) ($row['vport'] ?? 0), (int) ($row['user_vlan'] ?? 0), (int) ($row['vlan'] ?? 0)) : '';
    }

    private function fmtService(?array $row): string
    {
        if (! $row) {
            return '';
        }

        return $this->serviceDesc(
            $this->str($row['type'] ?? ''),
            strtolower($this->str($row['mode'] ?? '')),
            (int) ($row['gem'] ?? 0),
            (int) ($row['cos'] ?? 0),
            (int) ($row['vlan'] ?? 0),
        );
    }

    /**
     * Descriptor service pon-onu-mng (tanpa nama). `type` opsional — hanya
     * di-emit bila di-set (mis. `type internet`). Dipakai bersama untuk build
     * baris CLI & pembanding diff, agar keduanya konsisten.
     */
    private function serviceDesc(string $type, string $mode, int $gem, int $cos, int $vlan): string
    {
        $typePart = $type !== '' ? "type {$type} " : '';

        if ($mode === 'transparent') {
            return "{$typePart}gemport {$gem}";
        }

        return sprintf('%sgemport %d cos %d vlan %d', $typePart, $gem, $cos, $vlan);
    }

    // --- generic helpers ---

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function keyById(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (($row['id'] ?? null) !== null && $row['id'] !== '') {
                $out[(int) $row['id']] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyBy(array $rows, string $field): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $this->str($row[$field] ?? '');
            if ($key !== '') {
                $out[$key] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyByUni(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $port = (int) ($row['port'] ?? 0);
            if ($port < 1) {
                continue;
            }
            $out[strtolower($this->str($row['port_type'] ?? 'eth')).'_'.$port] = $row;
        }

        return $out;
    }

    private function str(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return 'true';
        }

        // Skrip rekonfigurasi dieksekusi baris-per-baris ke telnet OLT; buang CR/LF
        // & karakter kontrol lain dari nilai yang disisipkan (nama/profil hasil baca
        // running-config atau form) agar tak bisa menyisipkan perintah CLI tambahan.
        return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value) ?? '');
    }

    private function same(string $a, string $b): bool
    {
        return $a === $b;
    }

    /**
     * @return array{label:string, from:string, to:string}
     */
    private function change(string $label, ?string $from, string $to): array
    {
        return [
            'label' => $label,
            'from' => ($from === null || $from === '') ? '(kosong)' : $from,
            'to' => $to === '' ? '(kosong)' : $to,
        ];
    }

    private function lengthToMask(int $length): string
    {
        $length = max(0, min(32, $length));
        $mask = $length === 0 ? 0 : (0xFFFFFFFF << (32 - $length)) & 0xFFFFFFFF;

        return long2ip($mask);
    }
}
