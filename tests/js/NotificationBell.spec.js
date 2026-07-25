import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// --- Dobles de las dependencias del shell -----------------------------------------------

const visit = vi.fn();
const reload = vi.fn();
let pageProps;

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ get props() { return pageProps; } }),
    router: {
        visit: (...args) => visit(...args),
        reload: (...args) => reload(...args),
        post: vi.fn(),
    },
    // <Link> se usa solo en el pie del desplegable; un ancla basta.
    Link: { template: '<a><slot /></a>' },
}));

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key) => key }),
}));

import NotificationBell from '@/Components/Shell/NotificationBell.vue';

const alarm = {
    id: 6502,
    alarm_id: 6502,
    olt_name: 'LAS GALERAS C600',
    severity: 'minor',
    message: 'ONU offline',
    created_at: new Date().toISOString(),
    resource_type: 'onu',
    smartolt_id: 2,
    board_id: 3,
    port_id: 13,
    resource_id: 37,
    serial_number: 'ZTEGC1F9E618',
    is_read: false,
};

/** Monta la campana con el desplegable ya abierto. */
const openBell = async () => {
    const wrapper = mount(NotificationBell, {
        global: {
            // `route` va en mocks porque en la app real lo aporta el plugin ZiggyVue como
            // propiedad global, y el TEMPLATE lo resuelve por el contexto del componente
            // (el `<script setup>` en cambio lo toma de globalThis, ver beforeEach).
            mocks: { $t: (key) => key, route: (...args) => global.route(...args) },
            // Se deja el stub por defecto de <Transition> (sin animación): con la transición
            // real, en jsdom el evento de salida nunca dispara y el panel seguiría en el DOM.
        },
    });

    await wrapper.find('button').trigger('click'); // el botón de la campana
    await flushPromises();

    return wrapper;
};

/** Primer botón de una fila de notificación (la superficie de navegación). */
const rowButton = (wrapper) => wrapper.findAll('li button')[0];

/**
 * Botón "ver alarmas" DEL AVISO. Se busca entre <button> a propósito: el enlace del pie
 * del desplegable usa el mismo texto pero es un <Link> (→ <a>), así que buscar el texto
 * suelto daría un falso positivo aunque el fallback no existiera.
 */
const fallbackButton = (wrapper) => wrapper
    .findAll('button')
    .find((b) => b.text().includes('shell.view_all_alarms'));

const noticeText = (wrapper) => wrapper.text();

beforeEach(() => {
    pageProps = { notifications: { items: [alarm], unread_count: 1 } };
    visit.mockClear();
    reload.mockClear();
    // Ziggy expone route() como global; en el componente se llama sin importar.
    global.route = vi.fn((name) => (name === 'alarms.index' ? '/alarms' : `/__${name}`));
    window.axios = { post: vi.fn() };
});

describe('NotificationBell — navegación', () => {
    it('navega al destino que resolvió el servidor y cierra el panel', async () => {
        window.axios.post.mockResolvedValue({
            data: { data: { target_url: '/smartolt/2/ports/3/13/onus/37/detail', reason: null } },
        });

        const wrapper = await openBell();
        await rowButton(wrapper).trigger('click');
        await flushPromises();

        expect(visit).toHaveBeenCalledWith('/smartolt/2/ports/3/13/onus/37/detail');
        // Panel cerrado antes de navegar.
        expect(wrapper.findAll('li')).toHaveLength(0);
    });

    it('ante 403 muestra el aviso y ofrece la lista de alarmas', async () => {
        window.axios.post.mockRejectedValue({
            response: { status: 403, data: { message: 'sin permiso' } },
        });

        const wrapper = await openBell();
        await rowButton(wrapper).trigger('click');
        await flushPromises();

        // No navega a ciegas…
        expect(visit).not.toHaveBeenCalled();
        // …pero el aviso queda visible con salida al listado.
        expect(noticeText(wrapper)).toContain('sin permiso');
        expect(noticeText(wrapper)).toContain('shell.view_all_alarms');

        // Y ese botón sí lleva a /alarms.
        expect(fallbackButton(wrapper)).toBeTruthy();

        await fallbackButton(wrapper).trigger('click');
        await flushPromises();
        expect(visit).toHaveBeenCalledWith('/alarms');
    });

    it('ante 404 (alarma borrada / OLT ajeno) también ofrece salida', async () => {
        window.axios.post.mockRejectedValue({ response: { status: 404, data: {} } });

        const wrapper = await openBell();
        await rowButton(wrapper).trigger('click');
        await flushPromises();

        expect(visit).not.toHaveBeenCalled();
        // Sin mensaje del servidor cae al texto traducido genérico.
        expect(noticeText(wrapper)).toContain('shell.notif_target_unavailable');

        // OJO: no vale buscar el texto 'shell.view_all_alarms' suelto — el pie del
        // desplegable siempre lo muestra (como <Link>, o sea <a>). Hay que exigir el
        // BOTÓN del aviso, que es lo que aparece solo cuando hay fallback.
        expect(fallbackButton(wrapper)).toBeTruthy();

        await fallbackButton(wrapper).trigger('click');
        await flushPromises();
        expect(visit).toHaveBeenCalledWith('/alarms');
    });

    it('cuando el servidor no puede resolver destino usa su fallback_url', async () => {
        window.axios.post.mockResolvedValue({
            data: {
                data: {
                    target_url: null,
                    fallback_url: '/alarms?olt_id=2&scope=onu',
                    reason: 'position_reused',
                    message: 'otra ONU ocupa esa posición',
                },
            },
        });

        const wrapper = await openBell();
        await rowButton(wrapper).trigger('click');
        await flushPromises();

        expect(visit).not.toHaveBeenCalled();
        expect(noticeText(wrapper)).toContain('otra ONU ocupa esa posición');

        await fallbackButton(wrapper).trigger('click');
        await flushPromises();

        expect(visit).toHaveBeenCalledWith('/alarms?olt_id=2&scope=onu');
    });
});

describe('NotificationBell — marcar leída optimista', () => {
    it('marca al instante y revierte si el POST falla', async () => {
        window.axios.post.mockRejectedValue({ response: { status: 500, data: {} } });

        const wrapper = await openBell();
        // Badge visible con 1 sin leer.
        expect(wrapper.text()).toContain('1');

        const checkBtn = wrapper.findAll('li button')[1]; // hermano, no anidado
        await checkBtn.trigger('click');
        await flushPromises();

        // Revertido: el badge vuelve a 1 y se avisa del fallo.
        expect(wrapper.text()).toContain('1');
        expect(noticeText(wrapper)).toContain('shell.mark_read_failed');
    });
});
