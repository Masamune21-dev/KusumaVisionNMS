import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";

const visit = vi.fn();
const get = vi.fn();

vi.mock("@inertiajs/vue3", () => ({
    Head: { template: "<div><slot /></div>" },
    Link: { props: ["href"], template: "<a :href=\"href\"><slot /></a>" },
    router: { get: (...args) => get(...args), visit: (...args) => visit(...args) },
}));
vi.mock("vue-i18n", async (importOriginal) => ({
    ...(await importOriginal()),
    useI18n: () => ({ t: (key) => key }),
}));
vi.mock("@/Layouts/AuthenticatedLayout.vue", () => ({ default: { template: "<main><slot name=\"header\" /><slot /></main>" } }));
vi.mock("@/Components/Pagination.vue", () => ({ default: { template: "<nav />" } }));
vi.mock("@/Components/Shell/FilterCard.vue", () => ({ default: { template: "<section><slot /></section>" } }));

import AlarmsPage from "@/Pages/SmartOlt/Alarms.vue";

const makeAlarm = (id, contextualNavigation) => ({
    id,
    olt: { id: 2, name: contextualNavigation ? "ZTE C600" : "OTHER OLT" },
    type: "onu_offline", severity: "minor", status: "active", scope: "onu",
    slot: 3, port: 13, onu_id: 37, serial_number: `SN${id}`, customer_name: null,
    message: "ONU offline", first_seen_at: "2026-07-24T12:00:00Z",
    last_seen_at: "2026-07-24T12:05:00Z", cleared_at: null,
    contextual_navigation: contextualNavigation,
    dismiss_on_read: true,
});

const mountPage = () => mount(AlarmsPage, {
    props: {
        alarms: { data: [makeAlarm(10, true), makeAlarm(20, false)], from: 1, to: 2, total: 2, links: [] },
        summary: { critical: 0, major: 0, minor: 2, warning: 0, total: 2 },
        filter: { status: "active", severity: "all", scope: "all", type: "all", olt_id: null, q: "" },
        filterOptions: { olts: [], types: [], severities: [], scopes: [] },
    },
    global: { mocks: { $t: (key) => key, route: (...args) => global.route(...args) } },
});

beforeEach(() => {
    visit.mockClear();
    get.mockClear();
    global.route = vi.fn((name, id) => {
        if (name === "notifications.alarms.open") return `/notifications/alarms/${id}/open`;
        if (name === "smartolt.detail") return `/smartolt/${id}/detail`;
        if (name === "alarms.index") return "/alarms";
        return `/__${name}`;
    });
    window.axios = { post: vi.fn() };
});

describe("Alarms contextual navigation limited to ZTE", () => {
    it("opens a ZTE alarm using the server validated destination", async () => {
        window.axios.post.mockResolvedValue({ data: { data: { target_url: "/smartolt/2/ports/3/13/onus/37/detail" } } });
        const wrapper = mountPage();
        await wrapper.findAll("tbody tr")[0].trigger("click");
        await flushPromises();
        expect(window.axios.post).toHaveBeenCalledWith("/notifications/alarms/10/open");
        expect(visit).toHaveBeenCalledWith("/smartolt/2/ports/3/13/onus/37/detail");
    });

    it("does nothing for a non ZTE alarm", async () => {
        const wrapper = mountPage();
        const otherRow = wrapper.findAll("tbody tr")[1];
        await otherRow.trigger("click");
        await flushPromises();
        expect(window.axios.post).not.toHaveBeenCalled();
        expect(visit).not.toHaveBeenCalled();
        expect(otherRow.attributes("role")).toBeUndefined();
        expect(otherRow.attributes("tabindex")).toBeUndefined();
    });

    it("opens by keyboard and blocks a duplicate request while loading", async () => {
        let resolveRequest;
        window.axios.post.mockImplementation(() => new Promise((resolve) => { resolveRequest = resolve; }));
        const wrapper = mountPage();
        const zteRow = wrapper.findAll("tbody tr")[0];
        await zteRow.trigger("keydown", { key: "Enter" });
        await zteRow.trigger("click");
        expect(window.axios.post).toHaveBeenCalledTimes(1);
        resolveRequest({ data: { data: { target_url: "/smartolt/2/detail" } } });
        await flushPromises();
    });

    it("keeps the OLT link as an independent secondary control", async () => {
        const wrapper = mountPage();
        const oltLink = wrapper.findAll("tbody tr")[0].find("a");
        await oltLink.trigger("click");
        await flushPromises();
        expect(window.axios.post).not.toHaveBeenCalled();
        expect(oltLink.attributes("href")).toBe("/smartolt/2/detail");
    });

    it("shows the server fallback when the target is unavailable", async () => {
        window.axios.post.mockResolvedValue({ data: { data: {
            target_url: null, fallback_url: "/alarms?olt_id=2&scope=onu", message: "ONU no encontrada",
        } } });
        const wrapper = mountPage();
        await wrapper.findAll("tbody tr")[0].trigger("click");
        await flushPromises();
        expect(wrapper.text()).toContain("ONU no encontrada");
        expect(wrapper.findAll("tbody tr")).toHaveLength(1);
        await wrapper.find("[role=\"alert\"] button").trigger("click");
        expect(visit).toHaveBeenCalledWith("/alarms?olt_id=2&scope=onu");
    });
});
