<script setup>
// Foto dokumentasi ODP — satu foto per ODP (unggah baru menimpa yang lama).
// Dipakai kartu detail ODP di peta dan modal foto di halaman ODP.
//
// Berkasnya TIDAK dilayani /storage publik: `photo_url` menunjuk rute ber-auth
// (`odp.photo`), jadi <img> hanya berhasil untuk pengguna yang berhak atas OLT-nya.
// Server mengonversi gambar ke WebP (biner cwebp) — klien cukup mengirim file aslinya.
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Modal from '@/Components/Modal.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { router } from '@inertiajs/vue3';
import { Camera, ImageOff, Trash2, Upload } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n({ useScope: 'global' });

const props = defineProps({
    odp: { type: Object, required: true },
    // true = tampilan ringkas (kartu detail di peta); false = lebih lega (modal halaman ODP).
    compact: { type: Boolean, default: false },
});

const { confirmState, confirm, handleConfirm, handleCancel } = useConfirm();

const fileInput = ref(null);
const busy = ref(false);
const progress = ref(0);
const error = ref('');
const lightbox = ref(false);

const pick = () => {
    error.value = '';
    fileInput.value?.click();
};

const onPicked = (event) => {
    const file = event.target.files?.[0];
    // Reset input supaya memilih berkas yang sama dua kali tetap memicu change.
    event.target.value = '';
    if (!file) return;

    busy.value = true;
    progress.value = 0;
    router.post(
        route('map.odps.photo.store', props.odp.id),
        { photo: file },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            // Prop `odps` ada di halaman Peta maupun halaman ODP; `flash` wajib ikut
            // supaya toast tidak tersaring oleh `only`.
            only: ['odps', 'flash'],
            onProgress: (event) => (progress.value = event.percentage ?? 0),
            onError: (errors) => (error.value = errors.photo ?? t('map.odp_photo_failed')),
            onFinish: () => {
                busy.value = false;
                progress.value = 0;
            },
        },
    );
};

const remove = async () => {
    const ok = await confirm({
        title: t('map.odp_photo_delete_title'),
        message: t('map.odp_photo_delete_msg'),
        confirmLabel: t('common.delete'),
        variant: 'danger',
    });
    if (!ok) return;

    busy.value = true;
    router.delete(route('map.odps.photo.destroy', props.odp.id), {
        preserveScroll: true,
        preserveState: true,
        only: ['odps', 'flash'],
        onFinish: () => (busy.value = false),
    });
};
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-2">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $t('map.odp_photo_title') }}</span>
            <span v-if="busy && progress" class="text-[11px] tabular-nums text-cyan-300">{{ progress }}%</span>
        </div>

        <!-- Pratinjau / kondisi kosong -->
        <button
            v-if="odp.photo_url"
            type="button"
            class="group relative block w-full overflow-hidden rounded-xl border border-white/10 bg-black/30"
            :title="$t('map.odp_photo_open')"
            @click="lightbox = true"
        >
            <img :src="odp.photo_url" :alt="odp.name" class="w-full object-cover transition group-hover:opacity-90" :class="compact ? 'h-28' : 'h-48'" loading="lazy" />
        </button>
        <div
            v-else
            class="flex flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-white/15 bg-white/[0.03] text-slate-500"
            :class="compact ? 'h-20' : 'h-32'"
        >
            <ImageOff class="h-5 w-5" />
            <span class="text-[11px]">{{ $t('map.odp_photo_empty') }}</span>
        </div>

        <p v-if="error" class="rounded-lg bg-red-500/10 px-2 py-1 text-[11px] text-red-300">{{ error }}</p>
        <p v-else class="text-[10px] leading-snug text-slate-500">{{ $t('map.odp_photo_hint') }}</p>

        <div class="flex gap-2">
            <button type="button" class="kv-photo-btn flex-1" :disabled="busy" @click="pick">
                <component :is="odp.photo_url ? Upload : Camera" class="h-3.5 w-3.5" />
                {{ odp.photo_url ? $t('map.odp_photo_replace') : $t('map.odp_photo_upload') }}
            </button>
            <button v-if="odp.photo_url" type="button" class="kv-photo-btn kv-photo-btn--danger" :disabled="busy" @click="remove">
                <Trash2 class="h-3.5 w-3.5" />
            </button>
        </div>

        <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="hidden"
            @change="onPicked"
        />

        <!-- Foto ukuran penuh -->
        <Modal :show="lightbox" max-width="2xl" @close="lightbox = false">
            <button type="button" class="block w-full bg-black" @click="lightbox = false">
                <img :src="odp.photo_url" :alt="odp.name" class="max-h-[80vh] w-full object-contain" />
            </button>
        </Modal>

        <ConfirmModal :state="confirmState" @confirm="handleConfirm" @cancel="handleCancel" />
    </div>
</template>

<style scoped>
.kv-photo-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    border-radius: 0.5rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.04);
    padding: 0.4rem 0.6rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: #cbd5e1;
    transition: background-color 0.15s, color 0.15s;
}

.kv-photo-btn:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}

.kv-photo-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.kv-photo-btn--danger {
    color: #fca5a5;
    border-color: rgba(248, 113, 113, 0.25);
}

.kv-photo-btn--danger:hover:not(:disabled) {
    background: rgba(248, 113, 113, 0.12);
    color: #fecaca;
}
</style>
