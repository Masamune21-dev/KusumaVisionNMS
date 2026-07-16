import { createI18n } from 'vue-i18n';
import id from './lang/id.json';
import en from './lang/en.json';

// Daftar locale yang didukung UI. Sinkron dengan App\Support\Locale::SUPPORTED (backend).
export const SUPPORTED_LOCALES = ['id', 'en'];
export const DEFAULT_LOCALE = 'id';

// Composition mode (legacy:false) + globalInjection agar $t tersedia di template.
// Pesan berasal dari JSON statis (bukan blok <i18n> SFC) → tak butuh plugin compiler,
// dan JIT vue-i18n v9+ tidak memakai eval/Function sehingga lolos CSP nonce ketat app.
// Compiler pesan kustom: perlakukan tiap pesan sebagai TEKS LITERAL dengan hanya
// interpolasi `{param}`. Ini SENGAJA mematikan sintaks khusus vue-i18n bawaan
// (@linked, |plural, {'literal'}) yang menjadikan karakter biasa sebagai ranjau —
// mis. "@" pada email (name@company.com) atau "|" akan melempar SyntaxError di
// compiler bawaan dan membuat halaman blank. Karena pesan UI kita cuma butuh
// interpolasi sederhana, pendekatan literal ini paling aman untuk 750+ string.
// Pure JS (String.replace), tanpa eval → tetap lolos CSP nonce.
function literalMessageCompiler(message) {
    if (typeof message !== 'string') {
        // Sudah berupa fungsi (mis. pesan terkompilasi) → teruskan apa adanya.
        return message;
    }

    return (ctx) => message.replace(/\{\s*(\w+)\s*\}/g, (whole, key) => {
        const value = /^\d+$/.test(key)
            ? (typeof ctx.list === 'function' ? ctx.list(Number(key)) : undefined)
            : (typeof ctx.named === 'function' ? ctx.named(key) : undefined);

        // Param tak diberikan → biarkan token utuh (aman untuk brace literal & debug).
        return value === undefined || value === null ? whole : String(value);
    });
}

export const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: DEFAULT_LOCALE,
    fallbackLocale: 'en',
    // Diamkan warning "key tidak ditemukan" untuk halaman yang belum diterjemahkan
    // (rollout bertahap) — key yang belum ada otomatis fallback ke teks Indonesia asli.
    missingWarn: false,
    fallbackWarn: false,
    messageCompiler: literalMessageCompiler,
    messages: { id, en },
});

/**
 * Set locale aktif secara instan (tanpa reload) + sinkronkan atribut <html lang>.
 */
export function setI18nLocale(locale) {
    if (!SUPPORTED_LOCALES.includes(locale)) {
        return;
    }

    i18n.global.locale.value = locale;

    if (typeof document !== 'undefined') {
        document.documentElement.setAttribute('lang', locale);
    }
}
