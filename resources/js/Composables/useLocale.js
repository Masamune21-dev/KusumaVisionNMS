import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { setI18nLocale } from '@/i18n';

/**
 * Sumber kebenaran locale = prop Inertia `locale` (di-set backend SetLocale).
 * `change()` mengubah UI seketika lalu mempersistkan ke server (session + user).
 */
export function useLocale() {
    const page = usePage();

    const current = computed(() => page.props.locale ?? 'id');
    const options = computed(() => page.props.locales ?? []);

    function change(locale) {
        if (!locale || locale === current.value) {
            return;
        }

        // Flip UI seketika; server dipersist di latar (redirect back menyegarkan prop).
        setI18nLocale(locale);

        router.post(
            route('locale.update'),
            { locale },
            { preserveScroll: true, preserveState: true },
        );
    }

    return { current, options, change };
}
