import { computed, ref, watch } from 'vue';

/**
 * Paginasi sisi-klien untuk daftar yang sudah dimuat penuh ke props
 * (mis. ONU dari cache `port_onus`). Tanpa request ke server.
 *
 * @param {import('vue').Ref<Array>|import('vue').ComputedRef<Array>} source - array sumber (boleh sudah terfilter).
 * @param {{ pageSize?: number }} [options]
 */
export function usePagination(source, options = {}) {
    const pageSize = ref(options.pageSize ?? 50);
    const page = ref(1);

    const total = computed(() => source.value.length);
    const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)));

    const pageItems = computed(() => {
        const start = (page.value - 1) * pageSize.value;
        return source.value.slice(start, start + pageSize.value);
    });

    const rangeStart = computed(() => (total.value === 0 ? 0 : (page.value - 1) * pageSize.value + 1));
    const rangeEnd = computed(() => Math.min(page.value * pageSize.value, total.value));

    const setPage = (n) => { page.value = Math.min(Math.max(1, n), pageCount.value); };
    const next = () => setPage(page.value + 1);
    const prev = () => setPage(page.value - 1);

    // Filter/data berubah → array sumber jadi referensi baru → balik ke halaman 1.
    watch(source, () => { page.value = 1; });
    // Ganti ukuran halaman juga reset.
    watch(pageSize, () => { page.value = 1; });
    // Jaga page tetap valid bila pageCount menyusut.
    watch(pageCount, () => { if (page.value > pageCount.value) page.value = pageCount.value; });

    return { page, pageSize, total, pageCount, pageItems, rangeStart, rangeEnd, setPage, next, prev };
}
