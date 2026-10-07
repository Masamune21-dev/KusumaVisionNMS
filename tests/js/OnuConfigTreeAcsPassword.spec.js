import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { nextTick } from "vue";

vi.mock("axios", () => ({ default: { post: vi.fn() } }));
vi.mock("vue-i18n", async (importOriginal) => ({
    ...(await importOriginal()),
    useI18n: () => ({ t: (key) => key }),
}));
vi.mock("@inertiajs/vue3", () => ({ usePage: () => ({ props: {} }), router: { reload: vi.fn() } }));
// <dialog>.showModal() tak ada di jsdom — cukup slot-nya.
vi.mock("@/Components/Modal.vue", () => ({
    default: { props: ["show"], template: "<div v-if=\"show\"><slot /></div>" },
}));

import OnuConfigTree from "@/Components/SmartOlt/OnuConfigTree.vue";

globalThis.route = () => "/configure/item";

// Server tak pernah mengirim sandi ACS: hanya `acs_password_set` (lihat SmartOltController::maskLiveConfig).
const config = {
    name: "Uji-0800", tconts: [], gemports: [], service_ports: [], services: [], vlan_ports: [],
    wan_services: [], wan_ips: [], security_mgmts: [], extra_mgmt: [], profile_lines: [],
    tr069: true, acs_url: "http://acs.contoh.test:7547", acs_username: "acspusat",
    acs_password: null, acs_password_set: true,
};

const mountTree = () => mount(OnuConfigTree, {
    props: { olt: { id: 1 }, slot: 3, port: 16, onuId: 51, config, canWrite: true },
    global: { mocks: { $t: (key) => key } },
});

const openTr069 = async (wrapper) => {
    const nav = wrapper.findAll("button").find((b) => b.text().includes("TR069 ACS Configuration"));
    expect(nav, "item navigasi TR069").toBeTruthy();
    await nav.trigger("click");
};

describe("OnuConfigTree — sandi ACS tersamar", () => {
    it("menampilkan sandi sebagai terisi walau nilainya tak dikirim server", async () => {
        const wrapper = mountTree();
        await openTr069(wrapper);

        const card = wrapper.findAll("dl > div").find((d) => d.text().includes("ACS password"));
        expect(card.text()).toContain("••••••••");
    });

    it("isian edit kosong dengan petunjuk 'kosongkan untuk mempertahankan'", async () => {
        const wrapper = mountTree();
        await openTr069(wrapper);

        const edit = wrapper.findAll("button").find((b) => b.text().includes("onucfg.action_edit"));
        await edit.trigger("click");
        await nextTick();

        const input = wrapper.find("#onucfg-acs_password");
        expect(input.exists()).toBe(true);
        expect(input.element.value).toBe("");
        expect(input.attributes("placeholder")).toBe("onucfg.pw_keep");
    });
});
