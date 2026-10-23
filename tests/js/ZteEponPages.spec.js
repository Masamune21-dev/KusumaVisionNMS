import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { reactive } from "vue";

// Port EPON ZTE (kartu ETG, eksperimental): PortOnus dipakai juga oleh SEMUA port GPON produksi,
// jadi error render apa pun (mis. variabel tak terdeklarasi — Vue menelannya diam-diam) harus
// menggagalkan test, bukan hanya tombol yang hilang.
vi.mock("@inertiajs/vue3", () => ({
    Head: { template: "<div />" },
    Link: { props: ["href"], template: "<a :href=\"href\"><slot /></a>" },
    router: { post: vi.fn(), get: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { can: { manage_olt: true } } } }),
    useForm: (data) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            reset: vi.fn(),
            clearErrors: vi.fn(),
            post: vi.fn(),
        });
        form.data = () => ({ ...data });
        form.transform = () => form;
        return form;
    },
}));
vi.mock("vue-i18n", async (importOriginal) => ({
    ...(await importOriginal()),
    useI18n: () => ({ t: (key) => key }),
}));
vi.mock("@/Layouts/AuthenticatedLayout.vue", () => ({ default: { template: "<main><slot name=\"header\" /><slot /></main>" } }));

import PortOnus from "@/Pages/SmartOlt/PortOnus.vue";
import RegisterEponOnu from "@/Pages/SmartOlt/RegisterEponOnu.vue";

const errors = [];
const globalConfig = {
    mocks: { $t: (key) => key, route: (name) => `/__${name}` },
    stubs: {
        teleport: true,
        ConnectedDevicesModal: true,
        WifiSettingsModal: true,
        PinDeviceModal: true,
        Tr069BulkModal: true,
        AcsPinCell: true,
        OnuOdpCell: true,
    },
    config: {
        errorHandler: (err) => { errors.push(err); },
        warnHandler: (msg) => { if (/Unhandled error|is not defined|Property .* was accessed/.test(msg)) errors.push(new Error(msg)); },
    },
};

const gponCaps = {
    driver: "zte", supports_reboot: true, supports_onu_delete: true, supports_onu_info_write: true,
    supports_onu_toggle: true, supports_cli_onu_detail: true, supports_cli_onu_configure: true,
    supports_onu_config_write: true, supports_onu_preregister: true, supports_provisioning: true,
};
const eponCaps = {
    ...gponCaps, is_epon_port: true, supports_onu_toggle: false, supports_cli_onu_detail: false,
    supports_cli_onu_configure: false, supports_onu_config_write: false, supports_onu_preregister: false,
};

const olt = (capabilities) => ({
    id: 1, name: "OLT-UJI", ip: "10.0.0.1", cli_transport: "telnet", capabilities,
    last_test_result: { ports: [{ slot: 1, port: 1, name: "gpon_1/1/1" }, { slot: 3, port: 1, name: "epon-olt_1/3/1", pon_type: "epon" }] },
});

const snapshot = (iface, serial) => ({
    ok: true, count: 1, refreshed_at: null, rx_power: { ok: true },
    onus: [{ onu_id: 1, slot: 3, port: 1, interface: iface, serial_number: serial, name: "Uji", online: true, phase_state: "Online", admin_state: null }],
});

beforeEach(() => {
    errors.length = 0;
    globalThis.route = (name) => `/__${name}`;
    window.axios = { post: vi.fn(() => Promise.resolve({ data: { script: "conf t" } })) };
    HTMLDialogElement.prototype.showModal = function () {};
    HTMLDialogElement.prototype.close = function () {};
});
afterEach(() => {
    document.body.innerHTML = "";
});

describe("PortOnus — port GPON & EPON", () => {
    it("port GPON tetap tanpa lencana EPON dan tanpa error render", async () => {
        const wrapper = mount(PortOnus, {
            props: { olt: olt(gponCaps), slot: 1, port: 1, snapshot: snapshot("gpon-onu_1/1/1:1", "ZTEG08000001") },
            global: globalConfig,
        });
        await flushPromises();

        expect(errors).toEqual([]);
        expect(wrapper.text()).not.toContain("EPON");
        expect(wrapper.text()).toContain("portonus.col_serial");
        wrapper.unmount();
    });

    // Banner «eksperimental» dibuang 24 Okt 2026 setelah jalur baca (SNMP + CLI) terverifikasi di OLT ETGO
    // asli; peringatan sintaks tulis tetap di form RegisterEponOnu.
    it("port EPON menampilkan lencana, tombol daftar, dan kolom MAC tanpa banner eksperimental", async () => {
        const wrapper = mount(PortOnus, {
            props: { olt: olt(eponCaps), pon_type: "epon", slot: 3, port: 1, snapshot: snapshot("epon-onu_1/3/1:1", "1C:87:79:12:0C:39") },
            global: globalConfig,
        });
        await flushPromises();

        expect(errors).toEqual([]);
        expect(wrapper.text()).toContain("EPON");
        expect(wrapper.text()).not.toContain("portonus.epon_title");
        expect(wrapper.text()).toContain("portonus.register_epon");
        expect(wrapper.text()).toContain("portonus.col_mac");
        expect(wrapper.text()).not.toContain("Pre-Register ONU");
        wrapper.unmount();
    });
});

describe("RegisterEponOnu", () => {
    it("dirender tanpa error dan meminta pratinjau skrip saat dibuka", async () => {
        const wrapper = mount(RegisterEponOnu, {
            props: {
                olt: olt(eponCaps),
                epon_ports: [{ slot: 3, port: 1, name: "epon-olt_1/3/1" }],
                odps: [],
                suggestions: { onu_types: ["ALL-EPON"], sla_profiles: [] },
                defaults: {
                    slot: 3, port: 1, onu_id: 2, mac: "1C:18:4A:0D:D3:0F", onu_type: "ALL-EPON", customer_name: "",
                    description: "", sla_profile: "", vlan: 100, service_style: "service-port", onu_vlan_mode: "none",
                    onu_eth_port: 1, odp_id: null,
                },
            },
            global: globalConfig,
        });
        await flushPromises();

        expect(errors).toEqual([]);
        expect(window.axios.post).toHaveBeenCalledWith("/__smartolt.register-epon.preview", expect.objectContaining({ mac: "1C:18:4A:0D:D3:0F" }));
        expect(wrapper.text()).toContain("conf t");
        expect(wrapper.text()).toContain("registerepon.unverified_title");
        wrapper.unmount();
    });
});
