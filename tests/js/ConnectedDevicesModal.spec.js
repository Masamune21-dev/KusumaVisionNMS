import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";

const axiosGet = vi.fn();

vi.mock("axios", () => ({ default: { get: (...args) => axiosGet(...args) } }));
vi.mock("vue-i18n", async (importOriginal) => ({
    ...(await importOriginal()),
    useI18n: () => ({ t: (key) => key }),
}));
// Modal asli memakai <dialog>.showModal() yang tidak ada di jsdom; di sini
// cukup slot-nya saja karena yang diuji isi panel, bukan mekanika dialog.
vi.mock("@/Components/Modal.vue", () => ({
    default: { props: ["show"], template: "<div v-if=\"show\"><slot /></div>" },
}));
vi.mock("@/Components/SecondaryButton.vue", () => ({
    default: { template: "<button><slot /></button>" },
}));

import ConnectedDevicesModal from "@/Components/Genieacs/ConnectedDevicesModal.vue";

globalThis.route = () => "/olts/1/onus/1/1/5/acs-clients";

const mountModal = (props = {}) => mount(ConnectedDevicesModal, {
    props: { show: false, oltId: 1, slot: 1, port: 1, onuId: 5, ...props },
    global: { mocks: { $t: (key) => key } },
});

describe("ConnectedDevicesModal", () => {
    beforeEach(() => {
        axiosGet.mockReset();
        axiosGet.mockResolvedValue({
            data: {
                ok: true,
                device_id: "dev-1",
                hosts: [
                    { hostname: "Laptop-Budi", ip_address: "192.168.1.10", mac_address: "AA:BB", interface_type: "WiFi", active: true, active_source: "wifi" },
                    { hostname: "TV", ip_address: "192.168.1.11", mac_address: "AA:CC", interface_type: "Ethernet", active: null, active_source: null },
                    { hostname: "HP-Lama", ip_address: "192.168.1.12", mac_address: "AA:DD", interface_type: "WiFi", active: false, active_source: "device" },
                ],
                active_count: 1,
                unknown_count: 1,
                total_count: 3,
                wifi_networks: [{ ssid: "KUSUMANET", channel: 6 }],
            },
        });
    });

    // Regresi nyata: modal pernah dibungkus v-if pada keadaan terbuka, sehingga
    // dipasang saat `show` SUDAH true. Watcher `show` tak pernah terpicu, data
    // tak pernah dimuat, dan panelnya tampak kosong.
    it("memuat data saat show berubah dari false ke true", async () => {
        const wrapper = mountModal();
        expect(axiosGet).not.toHaveBeenCalled();

        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(axiosGet).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain("Laptop-Budi");
    });

    // Tabel host ONU adalah tabel sewa DHCP yang menumpuk: satu unit nyata
    // berisi 64 entri padahal hanya 3 yang benar-benar tersambung. Default
    // panel karena itu HANYA yang terbukti aktif.
    it("secara bawaan hanya menampilkan perangkat yang terbukti aktif", async () => {
        const wrapper = mountModal();
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(wrapper.text()).toContain("Laptop-Budi");
        expect(wrapper.text()).not.toContain("TV");
        expect(wrapper.text()).not.toContain("HP-Lama");
    });

    it("menampilkan sisanya setelah sakelar 'tampilkan semua' ditekan", async () => {
        const wrapper = mountModal();
        await wrapper.setProps({ show: true });
        await flushPromises();

        await wrapper.find("button.text-cyan-300").trigger("click");

        expect(wrapper.text()).toContain("TV");
        expect(wrapper.text()).toContain("HP-Lama");
    });

    it("tidak memanggil ACS bila belum ada ONU yang dipilih", async () => {
        const wrapper = mountModal({ onuId: 0 });

        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(axiosGet).not.toHaveBeenCalled();
    });

    it("membedakan ONU yang belum berpasangan dari kegagalan sistem", async () => {
        axiosGet.mockRejectedValue({ response: { data: { error: "not_linked" } } });

        const wrapper = mountModal();
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(wrapper.text()).toContain("acsclients.err_not_linked");
    });
});
