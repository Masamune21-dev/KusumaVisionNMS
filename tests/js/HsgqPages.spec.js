import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { reactive } from "vue";

// Halaman OLT HSGQ (driver eksperimental, tanpa perangkat uji): error render apa pun — mis. variabel
// tak terdeklarasi yang ditelan Vue — harus menggagalkan test, bukan sekadar tombol yang hilang.
vi.mock("@inertiajs/vue3", () => ({
    Head: { template: "<div />" },
    Link: { props: ["href"], template: "<a :href=\"href\"><slot /></a>" },
    router: { post: vi.fn(), get: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { can: { manage_olt: true, add_olt: true } } } }),
    useForm: (data) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            post: vi.fn(),
            put: vi.fn(),
        });
        form.transform = () => form;
        return form;
    },
}));
vi.mock("vue-i18n", async (importOriginal) => ({
    ...(await importOriginal()),
    useI18n: () => ({ t: (key) => key }),
}));
vi.mock("@/Layouts/AuthenticatedLayout.vue", () => ({ default: { template: "<main><slot name=\"header\" /><slot /></main>" } }));

import Detail from "@/Pages/Hsgq/Detail.vue";
import PortOnus from "@/Pages/Hsgq/PortOnus.vue";
import HsgqOltForm from "@/Pages/Hsgq/Partials/HsgqOltForm.vue";
import SmartOltIndex from "@/Pages/SmartOlt/Index.vue";

const errors = [];
const globalConfig = {
    mocks: { $t: (key) => key, route: (name) => `/__${name}` },
    stubs: {
        teleport: true,
        ConnectedDevicesModal: true,
        WifiSettingsModal: true,
        PinDeviceModal: true,
        AcsPinCell: true,
        OnuOdpCell: true,
        OltImage: true,
        OltPortLabel: true,
    },
    config: {
        errorHandler: (err) => { errors.push(err); },
        warnHandler: (msg) => { if (/Unhandled error|is not defined|Property .* was accessed/.test(msg)) errors.push(new Error(msg)); },
    },
};

const caps = {
    driver: "hsgq-50224", vendor_family: "HSGQ GPON", pon_label: "GPON", hsgq_unverified: true,
    supports_reboot: true, supports_onu_info_write: true, supports_config_save: true, supports_port_label: true,
    supports_onu_delete: false, supports_onu_toggle: false,
};

const olt = (overrides = {}) => ({
    id: 7, name: "OLT-HSGQ-UJI", ip: "10.0.0.7", snmp_port: 161, snmp_version: "v2c", web_port: 80,
    driver: "hsgq-50224", capabilities: caps, connection_locked: false,
    last_test_result: { ports: [{ slot: 1, port: 1, name: "PON01" }, { slot: 1, port: 2, name: "PON02" }] },
    ...overrides,
});

const portSnapshot = {
    ok: true, count: 2, refreshed_at: null,
    onus: [
        { onu_key: "1.0", onu_id: 0, slot: 1, port: 1, interface: "PON01/0", serial_number: "HWTC00080001", name: "uji0800-a", description: "Tiang 3", online: true, phase_state: "Online", rx_power_dbm: -20, rx_power_label: "-20.00 dBm", distance_m: 3718, uptime: "3h 58m", status_verified: true },
        { onu_key: "1.1", onu_id: 1, slot: 1, port: 1, interface: "PON01/1", serial_number: "ZTEG00080002", name: "uji0800-b", online: false, phase_state: "LOS", last_down_cause: "Laser out", rx_power_dbm: null, status_verified: true },
    ],
};

beforeEach(() => {
    errors.length = 0;
    globalThis.route = (name) => `/__${name}`;
    HTMLDialogElement.prototype.showModal = function () {};
    HTMLDialogElement.prototype.close = function () {};
});
afterEach(() => {
    document.body.innerHTML = "";
});

describe("Hsgq/PortOnus", () => {
    it("dirender tanpa error, ONU 0 tampil, aksi web tersedia bila port web terisi", async () => {
        const wrapper = mount(PortOnus, {
            props: { olt: olt(), slot: 1, port: 1, snapshot: portSnapshot },
            global: globalConfig,
        });
        await flushPromises();

        expect(errors).toEqual([]);
        expect(wrapper.text()).toContain("PON01/0");
        expect(wrapper.text()).toContain("Laser out");
        expect(wrapper.text()).toContain("hsgq.save_config");
        expect(wrapper.find('[title="portonus.act_reboot"]').exists()).toBe(true);
        expect(wrapper.find('[title="portonus.act_delete"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain("hsgq.status_unverified");
        wrapper.unmount();
    });

    it("tanpa port web: tombol tulis disembunyikan dan ada petunjuk", async () => {
        const unverified = { ...portSnapshot, onus: portSnapshot.onus.map((o) => ({ ...o, status_verified: false })) };
        const wrapper = mount(PortOnus, {
            props: { olt: olt({ web_port: null }), slot: 1, port: 1, snapshot: unverified },
            global: globalConfig,
        });
        await flushPromises();

        expect(errors).toEqual([]);
        expect(wrapper.find('[title="portonus.act_reboot"]').exists()).toBe(false);
        expect(wrapper.text()).toContain("hsgq.web_not_configured");
        expect(wrapper.text()).toContain("hsgq.status_unverified");
        wrapper.unmount();
    });
});

describe("Hsgq/Detail", () => {
    it("dirender tanpa error dengan tabel port, SFP, dan laporan diagnostik", async () => {
        const wrapper = mount(Detail, {
            props: {
                olt: olt(),
                snapshot: {
                    system: { model: "HSGQ-G02ID", firmware: "IGC_V1.2.9ID_Rel", hsgq_schema: "id" },
                    ports: [
                        { slot: 1, port: 1, name: "PON01", label: "PON01", oper_status: "up", onu_registered_snmp: 2, onu_online_snmp: 1, sfp: { temperature_c: 44.5, voltage_v: 3.3, bias_ma: 27.36, tx_dbm: 8.09 } },
                        { slot: 1, port: 2, name: "PON02", label: "PON02", oper_status: "down", onu_registered_snmp: null, sfp: null },
                    ],
                    port_counts: { "1_1": { count: 2, online: 1 } },
                    status_unverified: true,
                    diag: { tested_at: "2026-10-23T00:00:00+07:00", snmp: { snmp_ok: true }, web: { configured: true, login: true } },
                },
            },
            global: globalConfig,
        });
        await flushPromises();

        expect(errors).toEqual([]);
        expect(wrapper.text()).toContain("IGC_V1.2.9ID_Rel");
        expect(wrapper.text()).toContain("44.5 °C");
        expect(wrapper.text()).toContain("hsgq.status_unverified");
        expect(wrapper.text()).toContain('"login": true');
        wrapper.unmount();
    });
});

describe("Hsgq/Partials/HsgqOltForm", () => {
    it("dirender tanpa error dengan port web bawaan 80", async () => {
        const wrapper = mount(HsgqOltForm, {
            props: { defaults: { vendor: "HSGQ 50224", web_port: 80 }, submitLabel: "Simpan" },
            global: globalConfig,
        });
        await flushPromises();

        expect(errors).toEqual([]);
        expect(wrapper.find("#web_port").element.value).toBe("80");
        expect(wrapper.text()).toContain("hsgq.web_http_warning");
        wrapper.unmount();
    });
});

describe("SmartOlt/Index — tab HSGQ", () => {
    it("menampilkan OLT HSGQ dengan lencana belum terverifikasi tanpa error", async () => {
        window.history.replaceState({}, "", "/?tab=hsgq");
        const row = { ...olt(), can: { write_config: true, delete: true }, alarms_enabled: true, polling_enabled: true };
        const wrapper = mount(SmartOltIndex, {
            props: { olts: [], cdataOlts: [], hiosoOlts: [], hsgqOlts: [row] },
            global: globalConfig,
        });
        await flushPromises();

        expect(errors).toEqual([]);
        expect(wrapper.text()).toContain("OLT-HSGQ-UJI");
        expect(wrapper.text()).toContain("hsgq.unverified_badge");
        expect(wrapper.text()).toContain("hsgq.inventory_title");
        window.history.replaceState({}, "", "/");
        wrapper.unmount();
    });
});
