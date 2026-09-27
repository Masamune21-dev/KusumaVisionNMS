import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { reactive } from "vue";

vi.mock("@inertiajs/vue3", () => ({
    Head: { template: "<div />" },
    Link: { props: ["href"], template: "<a :href=\"href\"><slot /></a>" },
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
    useForm: (data) => reactive({
        ...data,
        errors: {},
        processing: false,
        reset: vi.fn(),
        clearErrors: vi.fn(),
        transform: vi.fn(),
        put: vi.fn(),
        post: vi.fn(),
    }),
}));
vi.mock("vue-i18n", async (importOriginal) => ({
    ...(await importOriginal()),
    useI18n: () => ({ t: (key) => key }),
}));
vi.mock("@/Layouts/AuthenticatedLayout.vue", () => ({ default: { template: "<main><slot name=\"header\" /><slot /></main>" } }));
vi.mock("@/Components/Map/OdpPhotoField.vue", () => ({ default: { template: "<div />" } }));

import OdpPage from "@/Pages/Odp/Index.vue";

const odp = {
    id: 7, snmp_olt_id: 1, olt_name: "OLT-A", name: "ODP-01", slot: 1, port: 4,
    latitude: -6.48, longitude: 110.87, color: null, photo_url: null, locked: true,
    notes: null, onu_count: 3,
};

const mountPage = () => mount(OdpPage, {
    props: { odps: [odp], olts: [{ id: 1, name: "OLT-A" }], odp_color_palette: ["#f59e0b", "#3b82f6"] },
    global: { mocks: { $t: (key) => key }, stubs: { teleport: true } },
    attachTo: document.body,
});

// jsdom tak punya <dialog>.showModal(); dicatat supaya mekanika Modal.vue asli ikut teruji.
let opened;
beforeEach(() => {
    opened = [];
    globalThis.route = (name) => `/__${name}`;
    HTMLDialogElement.prototype.showModal = function () { opened.push(this); };
    HTMLDialogElement.prototype.close = function () {};
});
afterEach(() => {
    document.body.innerHTML = "";
});

describe("Halaman ODP — tombol warna", () => {
    // Regresi nyata: modal warna dibungkus v-if dengan :show="true", jadi dipasang saat
    // `show` SUDAH true. Watcher `show` di Modal.vue tak pernah terpicu, showModal() tak
    // pernah dipanggil, dan tombol warna tampak tidak berfungsi.
    it("membuka dialog warna saat tombol warna ditekan", async () => {
        const wrapper = mountPage();
        const before = opened.length;

        await wrapper.find("button[title='map.odp_color']").trigger("click");
        await flushPromises();

        expect(opened.length).toBe(before + 1);
        expect(wrapper.text()).toContain("map.odp_color_title");
        wrapper.unmount();
    });

    it("bisa dibuka lagi setelah ditutup", async () => {
        const wrapper = mountPage();
        const button = wrapper.find("button[title='map.odp_color']");

        await button.trigger("click");
        await flushPromises();
        const colorDialog = wrapper.findAll("dialog").find((d) => d.text().includes("map.odp_color_title"));
        await colorDialog.findAll("button").find((b) => b.text() === "common.cancel").trigger("click");
        await flushPromises();

        const before = opened.length;
        await button.trigger("click");
        await flushPromises();

        expect(opened.length).toBe(before + 1);
        wrapper.unmount();
    });
});
