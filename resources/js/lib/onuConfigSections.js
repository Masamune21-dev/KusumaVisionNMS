// Skema editor ONU per-bagian (gaya pohon NetNumen: Line Configurations → T-CONT, …).
//
// Tiap bagian menunjuk ke satu kunci di bentuk config hasil ZteOnuRunningConfigService::parse().
// Editor TIDAK menyusun CLI sendiri: ia mengubah satu baris di salinan config lalu mengirim
// {baseline, config} ke server, dan ZteOnuReconfigureScriptBuilder yang menghitung delta-nya —
// mesin yang sama dengan editor lengkap, jadi aturan ZTE yang sudah teruji tetap berlaku.
//
// kind: 'table'   → daftar baris (Tambah/Ubah/Hapus), `path` = array di config
//       'form'    → kumpulan field tunggal di root config (Ubah saja)
//       'profile' → onu-profile C300 (lihat + Lepas profile)
//       'readonly'→ baris mentah yang belum dimodelkan

const nextId = (rows, max = 128) => {
    const used = new Set((rows ?? []).map((r) => Number(r.id) || 0));
    for (let id = 1; id <= max; id += 1) {
        if (!used.has(id)) return id;
    }
    return null;
};

const profileNames = (profiles, type) => (profiles?.[type] ?? []).map((p) => p.name);

const WAN_SERVICE_TYPES = ['internet', 'tr069', 'voip', 'other'];

export const sections = [
    {
        group: 'basic',
        items: [
            {
                key: 'name',
                kind: 'form',
                desc: 'onucfg.desc_name',
                cli: 'interface gpon-onu · name',
                icon: 'Tag',
                label: 'onucfg.item_name',
                fields: [{ key: 'name', label: 'Name', type: 'text', required: true, max: 191 }],
            },
        ],
    },
    {
        group: 'line',
        items: [
            {
                key: 'tconts',
                desc: 'onucfg.desc_tconts',
                cli: 'interface gpon-onu · tcont',
                kind: 'table',
                path: 'tconts',
                icon: 'Cpu',
                label: 'T-CONT Configuration',
                lockPrefix: (row) => `tcont ${row.id} `,
                columns: [
                    { key: 'id', label: 'ID' },
                    { key: 'name', label: 'Name' },
                    { key: 'profile', label: 'Profile' },
                    { key: 'gap', label: 'Gap' },
                ],
                fields: [
                    { key: 'id', label: 'T-CONT ID', type: 'number', min: 1, max: 8, immutable: true },
                    { key: 'name', label: 'Name', type: 'text' },
                    { key: 'profile', label: 'Bandwidth profile', type: 'select', options: (p) => profileNames(p, 'tcont'), allowCustom: true },
                    { key: 'gap', label: 'Gap mode', type: 'text' },
                ],
                newRow: (cfg, profiles) => ({
                    id: nextId(cfg.tconts, 8),
                    name: '1',
                    profile: profileNames(profiles, 'tcont')[0] ?? '',
                    gap: 'mode0',
                }),
            },
            {
                key: 'gemports',
                desc: 'onucfg.desc_gemports',
                cli: 'interface gpon-onu · gemport',
                kind: 'table',
                path: 'gemports',
                icon: 'Network',
                label: 'GEM Port Configuration',
                lockPrefix: (row) => `gemport ${row.id} `,
                columns: [
                    { key: 'id', label: 'ID' },
                    { key: 'name', label: 'Name' },
                    { key: 'tcont', label: 'T-CONT' },
                    { key: 'traffic_up', label: 'Upstream' },
                    { key: 'traffic_down', label: 'Downstream' },
                ],
                fields: [
                    { key: 'id', label: 'GEM port ID', type: 'number', min: 1, max: 128, immutable: true },
                    { key: 'name', label: 'Name', type: 'text' },
                    { key: 'tcont', label: 'T-CONT', type: 'number', min: 1, max: 8 },
                    { key: 'traffic_up', label: 'Traffic limit upstream', type: 'text' },
                    { key: 'traffic_down', label: 'Traffic limit downstream', type: 'text' },
                ],
                newRow: (cfg) => ({
                    id: nextId(cfg.gemports),
                    name: '1',
                    tcont: cfg.tconts?.[0]?.id ?? 1,
                    traffic_up: '',
                    traffic_down: '',
                }),
            },
        ],
    },
    {
        group: 'vport',
        items: [
            {
                key: 'service_ports',
                desc: 'onucfg.desc_service_ports',
                cli: 'interface gpon-onu · service-port',
                kind: 'table',
                path: 'service_ports',
                icon: 'ListChecks',
                label: 'Service Port',
                columns: [
                    { key: 'id', label: 'ID' },
                    { key: 'vport', label: 'VPort' },
                    { key: 'user_vlan', label: 'User VLAN' },
                    { key: 'vlan', label: 'VLAN' },
                ],
                fields: [
                    { key: 'id', label: 'Service-port ID', type: 'number', min: 1, max: 128, immutable: true },
                    { key: 'vport', label: 'VPort', type: 'number', min: 1, max: 128 },
                    { key: 'user_vlan', label: 'User VLAN', type: 'number', min: 1, max: 4094 },
                    { key: 'vlan', label: 'VLAN', type: 'number', min: 1, max: 4094 },
                ],
                newRow: (cfg) => ({ id: nextId(cfg.service_ports), vport: 1, user_vlan: null, vlan: null }),
            },
        ],
    },
    {
        group: 'pon',
        items: [
            { key: 'profile', kind: 'profile', icon: 'Lock', label: 'ONU Profile Configuration', desc: 'onucfg.desc_profile', cli: 'interface gpon-olt · onu N profile' },
            {
                key: 'services',
                desc: 'onucfg.desc_services',
                cli: 'pon-onu-mng · service',
                kind: 'table',
                path: 'services',
                icon: 'Settings',
                label: 'ONU Service',
                rowKey: 'name',
                lockPrefix: (row) => `service ${row.name} `,
                columns: [
                    { key: 'name', label: 'Name' },
                    { key: 'type', label: 'Type' },
                    { key: 'mode', label: 'Mode' },
                    { key: 'gem', label: 'GEM' },
                    { key: 'cos', label: 'CoS' },
                    { key: 'vlan', label: 'VLAN' },
                ],
                fields: [
                    { key: 'name', label: 'Service name', type: 'text', required: true, pattern: /^[A-Za-z0-9._-]+$/, immutable: true },
                    { key: 'type', label: 'onucfg.f_type_optional', type: 'select', options: () => ['', 'internet', 'iptv', 'voip', 'tr069'] },
                    { key: 'mode', label: 'Mode', type: 'select', options: () => ['vlanpri', 'transparent'] },
                    { key: 'gem', label: 'GEM port', type: 'number', min: 1, max: 128 },
                    { key: 'cos', label: 'CoS', type: 'number', min: 0, max: 7, showIf: (r) => r.mode !== 'transparent' },
                    { key: 'vlan', label: 'VLAN', type: 'number', min: 1, max: 4094, required: true, showIf: (r) => r.mode !== 'transparent' },
                ],
                newRow: (cfg) => ({
                    name: `ServiceName${(cfg.services ?? []).length + 1}`,
                    type: null,
                    mode: 'vlanpri',
                    gem: cfg.gemports?.[0]?.id ?? 1,
                    cos: 0,
                    vlan: null,
                }),
            },
            {
                key: 'vlan_ports',
                desc: 'onucfg.desc_vlan_ports',
                cli: 'pon-onu-mng · vlan port',
                kind: 'table',
                path: 'vlan_ports',
                icon: 'Globe',
                label: 'UNI VLAN',
                columns: [
                    { key: 'port_type', label: 'Port', fmt: (r) => `${r.port_type ?? 'eth'}_0/${r.port ?? '?'}` },
                    { key: 'mode', label: 'Mode' },
                    { key: 'def_vlan', label: 'Def VLAN' },
                    { key: 'priority', label: 'Priority' },
                ],
                fields: [
                    { key: 'port_type', label: 'Port type', type: 'select', options: () => ['eth', 'wifi'], immutable: true },
                    { key: 'port', label: 'Port', type: 'number', min: 1, max: 8, immutable: true },
                    { key: 'mode', label: 'Mode', type: 'select', options: () => ['tag', 'hybrid', 'trunk', 'transparent'] },
                    { key: 'def_vlan', label: 'Def VLAN', type: 'number', min: 1, max: 4094, showIf: (r) => ['tag', 'hybrid'].includes(r.mode) },
                    { key: 'priority', label: 'Priority', type: 'number', min: 0, max: 7, showIf: (r) => ['tag', 'hybrid'].includes(r.mode) },
                ],
                newRow: () => ({ port_type: 'eth', port: 1, mode: 'tag', def_vlan: null, priority: 0 }),
                // Hapus UNI VLAN = `mode na` di builder (ZTE menolak `no vlan port` polos).
                deleteAs: (row) => ({ ...row, mode: 'na' }),
            },
            {
                key: 'tr069',
                desc: 'onucfg.desc_tr069',
                cli: 'pon-onu-mng · tr069-mgmt',
                kind: 'form',
                icon: 'Cloud',
                label: 'TR069 ACS Configuration',
                fields: [
                    { key: 'tr069', label: 'onucfg.f_tr069_on', type: 'bool' },
                    { key: 'acs_url', label: 'ACS URL', type: 'text', showIf: (c) => c.tr069 },
                    { key: 'acs_username', label: 'ACS username', type: 'text', showIf: (c) => c.tr069 },
                    { key: 'acs_password', label: 'ACS password', type: 'password', showIf: (c) => c.tr069 },
                ],
            },
        ],
    },
    {
        group: 'wan',
        items: [
            {
                key: 'wan_ips',
                desc: 'onucfg.desc_wan_ips',
                cli: 'pon-onu-mng · wan-ip',
                kind: 'table',
                path: 'wan_ips',
                icon: 'Router',
                label: 'ONU WAN IP Configuration',
                columns: [
                    { key: 'id', label: 'ID' },
                    { key: 'mode', label: 'Mode' },
                    { key: 'pppoe_username', label: 'PPPoE user' },
                    { key: 'vlan_profile', label: 'VLAN profile' },
                    { key: 'static_ip', label: 'IP', fmt: (r) => (r.mode === 'static' && r.static_ip ? `${r.static_ip}/${r.static_mask_length ?? ''}` : '') },
                    { key: 'host', label: 'Host' },
                ],
                fields: [
                    { key: 'id', label: 'WAN-IP ID', type: 'number', min: 1, max: 8, immutable: true },
                    { key: 'mode', label: 'Mode', type: 'select', options: () => ['pppoe', 'dhcp', 'static'] },
                    { key: 'pppoe_username', label: 'PPPoE username', type: 'text', showIf: (r) => r.mode === 'pppoe' },
                    { key: 'pppoe_password', label: 'PPPoE password', type: 'password', showIf: (r) => r.mode === 'pppoe' },
                    { key: 'static_ip', label: 'onucfg.f_static_ip', type: 'text', showIf: (r) => r.mode === 'static' },
                    { key: 'static_mask_length', label: 'Mask length', type: 'number', min: 1, max: 32, showIf: (r) => r.mode === 'static' },
                    { key: 'ip_profile', label: 'IP profile', type: 'select', options: (p) => ['', ...profileNames(p, 'ip')], allowCustom: true, showIf: (r) => r.mode === 'static' },
                    { key: 'vlan_profile', label: 'VLAN profile', type: 'select', options: (p) => ['', ...profileNames(p, 'vlan')], allowCustom: true },
                    { key: 'host', label: 'Host', type: 'number', min: 1, max: 16 },
                    { key: 'ping_response', label: 'Ping response', type: 'bool' },
                    { key: 'traceroute_response', label: 'Traceroute response', type: 'bool' },
                ],
                newRow: (cfg) => ({
                    id: nextId(cfg.wan_ips, 8),
                    mode: 'pppoe',
                    vlan_profile: null,
                    pppoe_username: '',
                    pppoe_password: '',
                    ip_profile: null,
                    static_ip: '',
                    static_mask_length: 24,
                    host: 1,
                    ping_response: true,
                    traceroute_response: true,
                }),
            },
            {
                key: 'wan_services',
                desc: 'onucfg.desc_wan_services',
                cli: 'pon-onu-mng · wan',
                kind: 'table',
                path: 'wan_services',
                icon: 'Link2',
                label: 'WAN Configuration',
                columns: [
                    { key: 'id', label: 'ID' },
                    { key: 'services', label: 'Service', fmt: (r) => (r.services ?? []).join(' ') },
                    { key: 'mvlan', label: 'MVLAN' },
                    { key: 'ethuni', label: 'ETH UNI' },
                    { key: 'ssid', label: 'SSID' },
                    { key: 'host', label: 'Host' },
                ],
                fields: [
                    { key: 'id', label: 'WAN ID', type: 'number', min: 1, max: 128, immutable: true },
                    { key: 'services', label: 'Service type', type: 'multi', options: () => WAN_SERVICE_TYPES, required: true },
                    { key: 'mvlan', label: 'MVLAN', type: 'text', showIf: (r) => (r.services ?? []).includes('other') },
                    { key: 'ethuni', label: 'ETH UNI', type: 'text' },
                    { key: 'ssid', label: 'SSID', type: 'text' },
                    { key: 'host', label: 'Host', type: 'text' },
                ],
                newRow: (cfg) => ({ id: nextId(cfg.wan_services), services: ['internet'], mvlan: '', ethuni: '', ssid: '', host: '1' }),
            },
        ],
    },
    {
        group: 'security',
        items: [
            {
                key: 'remote',
                desc: 'onucfg.desc_remote',
                cli: 'pon-onu-mng · security-mgmt',
                kind: 'form',
                icon: 'ShieldCheck',
                label: 'Remote ONT (security-mgmt)',
                fields: [
                    { key: 'remote_ont', label: 'onucfg.f_remote_on', type: 'bool' },
                    { key: 'remote_ont_id', label: 'Entry ID', type: 'number', min: 1, max: 4095, showIf: (c) => c.remote_ont },
                    { key: 'remote_ont_mode', label: 'Mode', type: 'select', options: () => ['forward', 'discard'], showIf: (c) => c.remote_ont },
                    { key: 'remote_ont_protocol', label: 'Protocol', type: 'select', options: () => ['web', 'telnet', 'ssh', 'ftp', 'tftp', 'snmp'], showIf: (c) => c.remote_ont },
                ],
            },
            { key: 'extra', kind: 'readonly', icon: 'FileText', label: 'onucfg.item_extra', desc: 'onucfg.desc_extra', cli: 'pon-onu-mng' },
        ],
    },
];

/** Identitas baris (untuk sorotan "baru berubah" & cek duplikat). */
export const rowIdentity = (item, row) => (item.key === 'vlan_ports' ? `${row.port_type}_${row.port}` : String(row?.[item.rowKey ?? 'id']));

export const allItems = sections.flatMap((g) => g.items);

export const findItem = (key) => allItems.find((item) => item.key === key) ?? allItems[0];

/** Baris ini berasal dari blok onu-profile (dikunci OLT selama profile terpasang)? */
export const isLockedRow = (item, row, config) => {
    if (!config?.onu_profile || typeof item.lockPrefix !== 'function') return false;
    const prefix = item.lockPrefix(row);

    return (config.profile_lines ?? []).some((line) => `${line} `.startsWith(prefix));
};

/** Nilai kosong dari form dinormalisasi supaya cocok dengan aturan validasi server. */
export const normalizeRow = (item, row) => {
    const out = { ...row };
    for (const field of item.fields ?? []) {
        const value = out[field.key];
        if (field.type === 'number') {
            out[field.key] = value === '' || value === null || value === undefined ? null : Number(value);
        } else if (field.type === 'select' && value === '') {
            out[field.key] = null;
        } else if (field.type === 'bool') {
            out[field.key] = !!value;
        }
    }
    return out;
};

/**
 * Validasi ringan di klien — server tetap memvalidasi ulang.
 * Mengembalikan null atau { key, params } untuk diterjemahkan komponen (i18n `onucfg.v_*`).
 */
export const validateRow = (item, row, rows, originalIndex) => {
    for (const field of item.fields ?? []) {
        if (field.showIf && !field.showIf(row)) continue;
        const value = row[field.key];
        const empty = value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
        if (field.required && empty) return { key: 'onucfg.v_required', params: { field: field.label } };
        if (field.type === 'number' && !empty) {
            const n = Number(value);
            if (!Number.isInteger(n) || (field.min !== undefined && n < field.min) || (field.max !== undefined && n > field.max)) {
                return { key: 'onucfg.v_range', params: { field: field.label, min: field.min, max: field.max } };
            }
        }
        if (field.pattern && !empty && !field.pattern.test(String(value))) return { key: 'onucfg.v_pattern', params: { field: field.label } };
    }

    if (item.kind === 'table') {
        const clash = (rows ?? []).some((r, i) => i !== originalIndex && rowIdentity(item, r) === rowIdentity(item, row));
        if (clash) return { key: 'onucfg.v_duplicate', params: { value: rowIdentity(item, row) } };
    }

    return null;
};
