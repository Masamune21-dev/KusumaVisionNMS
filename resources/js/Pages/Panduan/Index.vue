<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    BellRing, BookOpen, Cable, Compass, FileBarChart, KeyRound, LayoutDashboard,
    LifeBuoy, ListChecks, MapPin, PlugZap, Radar, Rocket, ScrollText, Send,
    ShieldCheck, Smartphone, Sparkles, Terminal, Users, Wrench, WifiOff,
} from '@lucide/vue';

const page = usePage();
const appName = computed(() => page.props.branding?.name ?? 'KusumaVision NMS');

/*
 * Preset warna aksen per-bagian. Kelas ditulis sebagai string literal penuh
 * (bukan interpolasi `bg-${c}-...`) supaya JIT Tailwind memindainya — jangan
 * mengubah jadi template dinamis atau kelasnya akan ter-purge.
 */
const ACCENTS = {
    cyan: { icon: 'text-cyan-300', tile: 'bg-cyan-500/15 text-cyan-300 ring-cyan-500/30', num: 'text-cyan-300', bullet: 'bg-cyan-400/80', step: 'bg-cyan-500/15 text-cyan-200 ring-cyan-500/30', active: 'bg-cyan-500/10 text-cyan-100 ring-cyan-500/40', bar: 'from-cyan-500/70' },
    sky: { icon: 'text-sky-300', tile: 'bg-sky-500/15 text-sky-300 ring-sky-500/30', num: 'text-sky-300', bullet: 'bg-sky-400/80', step: 'bg-sky-500/15 text-sky-200 ring-sky-500/30', active: 'bg-sky-500/10 text-sky-100 ring-sky-500/40', bar: 'from-sky-500/70' },
    blue: { icon: 'text-blue-300', tile: 'bg-blue-500/15 text-blue-300 ring-blue-500/30', num: 'text-blue-300', bullet: 'bg-blue-400/80', step: 'bg-blue-500/15 text-blue-200 ring-blue-500/30', active: 'bg-blue-500/10 text-blue-100 ring-blue-500/40', bar: 'from-blue-500/70' },
    teal: { icon: 'text-teal-300', tile: 'bg-teal-500/15 text-teal-300 ring-teal-500/30', num: 'text-teal-300', bullet: 'bg-teal-400/80', step: 'bg-teal-500/15 text-teal-200 ring-teal-500/30', active: 'bg-teal-500/10 text-teal-100 ring-teal-500/40', bar: 'from-teal-500/70' },
    emerald: { icon: 'text-emerald-300', tile: 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30', num: 'text-emerald-300', bullet: 'bg-emerald-400/80', step: 'bg-emerald-500/15 text-emerald-200 ring-emerald-500/30', active: 'bg-emerald-500/10 text-emerald-100 ring-emerald-500/40', bar: 'from-emerald-500/70' },
    violet: { icon: 'text-violet-300', tile: 'bg-violet-500/15 text-violet-300 ring-violet-500/30', num: 'text-violet-300', bullet: 'bg-violet-400/80', step: 'bg-violet-500/15 text-violet-200 ring-violet-500/30', active: 'bg-violet-500/10 text-violet-100 ring-violet-500/40', bar: 'from-violet-500/70' },
    fuchsia: { icon: 'text-fuchsia-300', tile: 'bg-fuchsia-500/15 text-fuchsia-300 ring-fuchsia-500/30', num: 'text-fuchsia-300', bullet: 'bg-fuchsia-400/80', step: 'bg-fuchsia-500/15 text-fuchsia-200 ring-fuchsia-500/30', active: 'bg-fuchsia-500/10 text-fuchsia-100 ring-fuchsia-500/40', bar: 'from-fuchsia-500/70' },
    amber: { icon: 'text-amber-300', tile: 'bg-amber-500/15 text-amber-300 ring-amber-500/30', num: 'text-amber-300', bullet: 'bg-amber-400/80', step: 'bg-amber-500/15 text-amber-200 ring-amber-500/30', active: 'bg-amber-500/10 text-amber-100 ring-amber-500/40', bar: 'from-amber-500/70' },
    orange: { icon: 'text-orange-300', tile: 'bg-orange-500/15 text-orange-300 ring-orange-500/30', num: 'text-orange-300', bullet: 'bg-orange-400/80', step: 'bg-orange-500/15 text-orange-200 ring-orange-500/30', active: 'bg-orange-500/10 text-orange-100 ring-orange-500/40', bar: 'from-orange-500/70' },
    rose: { icon: 'text-rose-300', tile: 'bg-rose-500/15 text-rose-300 ring-rose-500/30', num: 'text-rose-300', bullet: 'bg-rose-400/80', step: 'bg-rose-500/15 text-rose-200 ring-rose-500/30', active: 'bg-rose-500/10 text-rose-100 ring-rose-500/40', bar: 'from-rose-500/70' },
    indigo: { icon: 'text-indigo-300', tile: 'bg-indigo-500/15 text-indigo-300 ring-indigo-500/30', num: 'text-indigo-300', bullet: 'bg-indigo-400/80', step: 'bg-indigo-500/15 text-indigo-200 ring-indigo-500/30', active: 'bg-indigo-500/10 text-indigo-100 ring-indigo-500/40', bar: 'from-indigo-500/70' },
};

/*
 * Panduan penggunaan — konten data-driven supaya TOC & section sinkron.
 * Tiap section: id, icon, title, accent (kunci ACCENTS), badges?, intro,
 * ordered? (daftar bernomor), list[{ strong?, text }], tip?.
 */
const sections = [
    {
        id: 'pengantar', icon: Rocket, accent: 'cyan', title: 'Sekilas Aplikasi',
        intro: 'Sistem manajemen jaringan FTTH/GPON untuk memantau OLT dan memprovisioning ONU dari satu dashboard web — alternatif SmartOLT/NetNumen untuk ISP.',
        ordered: false,
        list: [
            { strong: 'Multi-vendor OLT', text: 'ZTE (C300/C320), C-Data (EPON/GPON), dan HiOSO/V-Sol (EPON) dikelola berdampingan.' },
            { strong: 'Pemantauan otomatis', text: 'polling SNMP terjadwal mengumpulkan status port PON, ONU online/offline, dan redaman RX; alarm otomatis dikirim ke Telegram & aplikasi mobile.' },
            { strong: 'Provisioning', text: 'daftarkan ONU baru, atur WAN (PPPoE/DHCP/static), VLAN, TR069, sampai reboot/rename/hapus — langsung dari web.' },
        ],
        tip: 'Tekan Ctrl/⌘ + K di mana saja untuk pencarian global (cari OLT atau ONU by nama/serial/interface).',
    },
    {
        id: 'peran', icon: ShieldCheck, accent: 'emerald', title: 'Masuk & Peran Pengguna',
        intro: 'Login memakai email & kata sandi yang diberikan admin. Menu dan tombol yang muncul menyesuaikan peran akun Anda.',
        ordered: false,
        list: [
            { strong: 'Admin', text: 'akses penuh: kelola OLT, pengguna, pengaturan sistem, audit log, dan semua aksi ONU.' },
            { strong: 'Operator', text: 'operasional harian: lihat OLT/ONU, monitoring, registrasi & aksi ONU. Tidak bisa mengelola pengguna/pengaturan.' },
            { strong: 'Partner', text: 'hanya melihat OLT yang di-assign untuk-nya (OLT privat tersembunyi dari admin/operator lain), plus bot Telegram sendiri.' },
            { strong: 'Mode Demo', text: 'akun peraga: bisa menjelajah, tetapi aksi tulis (registrasi, reboot, hapus, dsb.) diblokir.' },
        ],
    },
    {
        id: 'navigasi', icon: Compass, accent: 'sky', title: 'Navigasi & Pencarian',
        intro: 'Sidebar kiri adalah menu utama. Bilah atas berisi pencarian, lonceng notifikasi, dan menu akun.',
        ordered: false,
        list: [
            { strong: 'Sidebar', text: 'klik ikon panah untuk melipat/melebarkan; di HP, ketuk ikon menu untuk membukanya.' },
            { strong: 'Pencarian global (⌘/Ctrl + K)', text: 'ketik nama/IP OLT atau serial/nama/interface ONU untuk melompat langsung.' },
            { strong: 'Lonceng notifikasi', text: 'menampilkan alarm terbaru; angka merah = jumlah alarm aktif.' },
            { strong: 'Menu akun', text: 'ubah profil/kata sandi dan keluar (logout).' },
        ],
    },
    {
        id: 'dashboard', icon: LayoutDashboard, accent: 'violet', title: 'Dashboard',
        intro: 'Halaman pertama setelah login: ringkasan kesehatan jaringan secara menyeluruh.',
        ordered: false,
        list: [
            { text: 'Kartu statistik: jumlah OLT, ONU online/offline, port PON, dan alarm aktif.' },
            { text: 'Sorotan alarm terbaru dan OLT bermasalah untuk tindak lanjut cepat.' },
            { text: 'Angka bersumber dari hasil polling terakhir — jadi mencerminkan kondisi beberapa menit terakhir, bukan real-time detik-per-detik.' },
        ],
    },
    {
        id: 'olt', icon: Cable, accent: 'blue', title: 'Mengelola OLT',
        intro: 'Menu SmartOLT memuat daftar OLT dalam tab per-vendor (ZTE, C-Data, HiOSO). Tambah OLT lewat tombol Tambah OLT.',
        ordered: true,
        list: [
            { strong: 'Isi data koneksi', text: 'nama, IP, port & community SNMP (read/write), serta kredensial CLI/telnet bila akan provisioning.' },
            { strong: 'Test SNMP', text: 'klik untuk memverifikasi OLT terjangkau — akan menampilkan info sistem, port PON, dan jumlah ONU.' },
            { strong: 'Aktifkan polling', text: 'agar OLT ikut pemantauan terjadwal (status & RX ONU diperbarui berkala oleh mesin poller).' },
            { strong: 'Saklar alarm per-OLT', text: 'tombol On/Off di daftar OLT mengatur apakah notifikasi alarm OLT ini dikirim (evaluasi tetap jalan & tercatat).' },
        ],
        tip: 'Kolom rahasia (community write, password CLI) bisa dikosongkan saat mengedit untuk mempertahankan nilai lama — tidak perlu mengetik ulang.',
    },
    {
        id: 'port-onu', icon: Radar, accent: 'teal', title: 'Melihat Port PON & ONU',
        intro: 'Dari detail OLT, buka sebuah port PON untuk melihat daftar ONU di bawahnya beserta status dan redaman.',
        ordered: false,
        list: [
            { strong: 'Status ONU', text: 'online/offline, penyebab down terakhir (LOS, dying gasp), dan interface.' },
            { strong: 'Redaman RX (dBm)', text: 'indikator kualitas sinyal; nilai di luar rentang sehat ditandai sebagai peringatan.' },
            { strong: 'Refresh', text: 'tombol refresh melakukan pembacaan SNMP langsung untuk port/OLT tersebut (dibatasi rate agar tak membebani OLT).' },
        ],
    },
    {
        id: 'unconfigured', icon: WifiOff, accent: 'amber', title: 'ONU Belum Terdaftar (Unconfigured)',
        intro: 'Menu Unconfigured mengumpulkan ONU yang terdeteksi OLT tetapi belum diregistrasi — kandidat pelanggan baru.',
        ordered: true,
        list: [
            { text: 'Temukan ONU baru berdasarkan serial number pada port PON-nya.' },
            { text: 'Klik Daftarkan untuk membuka form registrasi (provisioning) dengan serial sudah terisi.' },
        ],
    },
    {
        id: 'provisioning', icon: PlugZap, accent: 'fuchsia', title: 'Registrasi / Provisioning ONU (ZTE)',
        intro: 'Mendaftarkan ONU baru ke OLT ZTE: pilih profil, tentukan layanan WAN, lalu jalankan skrip CLI yang dihasilkan.',
        ordered: true,
        list: [
            { strong: 'Pilih profil', text: 'tipe ONU, T-CONT, dan profil VLAN/IP (disinkron per-OLT dari OLT).' },
            { strong: 'Atur WAN', text: 'PPPoE (isi user/pass), DHCP, atau IP statis; tambahkan VLAN dan TR069 bila perlu.' },
            { strong: 'Pratinjau skrip', text: 'sistem membuat skrip CLI registrasi — periksa sebelum eksekusi.' },
            { strong: 'Eksekusi', text: 'skrip dijalankan ke OLT via telnet; hasilnya dicatat sebagai audit registrasi.' },
        ],
        tip: 'Setiap provisioning menulis baris audit (skrip dibuat dulu, dieksekusi kemudian) sehingga jejaknya bisa ditelusuri.',
    },
    {
        id: 'aksi-onu', icon: Wrench, accent: 'orange', title: 'Aksi pada ONU',
        intro: 'Dari halaman ONU per port tersedia aksi pemeliharaan. Ketersediaan tergantung kapabilitas vendor OLT.',
        ordered: false,
        list: [
            { strong: 'Reboot', text: 'nyalakan ulang ONU dari jarak jauh.' },
            { strong: 'Ganti nama (rename)', text: 'ubah deskripsi/nama ONU (mis. nama pelanggan).' },
            { strong: 'Hapus ONU', text: 'cabut registrasi ONU dari port (tersedia bila vendor mendukung).' },
            { strong: 'Salin config antar port', text: 'baca running-config ONU sumber lalu bangun ulang registrasi di port tujuan (batch, dengan modal progres).' },
            { strong: 'TR069 Massal', text: 'aktifkan TR069/ACS untuk semua ONU dalam satu port PON sekaligus (OLT ZTE).' },
        ],
    },
    {
        id: 'monitoring', icon: ListChecks, accent: 'cyan', title: 'ONU Monitoring (Lintas OLT)',
        intro: 'Menu ONU Monitoring menggabungkan ONU dari semua OLT dalam satu tampilan untuk pemantauan menyeluruh.',
        ordered: false,
        list: [
            { text: 'Melihat sebaran ONU online/offline lintas OLT tanpa membuka satu-satu.' },
            { text: 'Refresh menjalankan pembacaan SNMP penuh per OLT dan memperbarui cache per-port.' },
        ],
    },
    {
        id: 'peta', icon: MapPin, accent: 'rose', title: 'Peta ONU',
        intro: 'Menu Peta ONU menampilkan pin lokasi pelanggan-ONU di peta (Leaflet) untuk semua OLT.',
        ordered: true,
        list: [
            { strong: 'Tambah pin', text: 'klik pada peta lalu pilih OLT → port → ONU, atau gunakan tombol Add Map di halaman ONU per port.' },
            { strong: 'Dari link Google Maps', text: 'tempel tautan lokasi dan koordinat akan otomatis diambil.' },
            { strong: 'Aksi dari pin', text: 'kartu detail pin bisa ganti-nama atau reboot ONU langsung dari peta.' },
        ],
    },
    {
        id: 'alarm', icon: BellRing, accent: 'amber', title: 'Alarm & Notifikasi',
        intro: 'Menu Alarms adalah pusat alarm. Sistem menaikkan alarm hanya pada transisi dari sehat ke gangguan (mis. ONU online → offline, port up → down, RX keluar rentang).',
        ordered: false,
        list: [
            { strong: 'Jenis alarm', text: 'OLT tak terhubung, port PON down, LOS, dying gasp, ONU offline, dan redaman RX tinggi.' },
            { strong: 'Realtime vs Konfirmasi 2 poll', text: 'di Pengaturan → tab Alarm, admin memilih apakah notifikasi dikirim langsung (realtime) atau menunggu gangguan terkonfirmasi di 2 pengecekan (anti-flap, default).' },
            { strong: 'Saklar penerima', text: 'saklar alarm per-OLT (admin/operator) dan per-partner mengatur siapa yang menerima kiriman — evaluasi alarm tetap berjalan.' },
            { strong: 'Kanal', text: 'notifikasi diteruskan ke Bot Telegram dan Push aplikasi mobile (FCM) sesuai pengaturan.' },
        ],
        tip: 'Mode default "Konfirmasi 2 poll" menahan alarm sampai gangguan terlihat di dua polling beruntun (~10 menit) supaya kedip sesaat tidak memicu notifikasi palsu. Butuh notif secepatnya? Ubah ke Realtime.',
    },
    {
        id: 'telnet', icon: Terminal, accent: 'indigo', title: 'Terminal Telnet Browser',
        intro: 'Buka sesi CLI ke OLT langsung dari browser (jendela terminal xterm.js) tanpa aplikasi telnet terpisah.',
        ordered: false,
        list: [
            { text: 'Jendela bisa digeser, di-minimize, dan di-maximize.' },
            { text: 'Berguna untuk perintah CLI manual/diagnostik pada OLT ZTE.' },
        ],
    },
    {
        id: 'report', icon: FileBarChart, accent: 'emerald', title: 'Report & Ekspor',
        intro: 'Menu Report membuat laporan yang bisa difilter dan diekspor.',
        ordered: false,
        list: [
            { strong: 'Jenis laporan', text: 'antara lain ONU, alarm, dan provisioning.' },
            { strong: 'Filter', text: 'per OLT, per port PON, rentang waktu, status, dan status redaman RX.' },
            { strong: 'Ekspor', text: 'unduh sebagai CSV atau PDF melalui tombol di kanan atas.' },
        ],
    },
    {
        id: 'pengaturan', icon: KeyRound, accent: 'sky', title: 'Pengaturan', badges: ['Admin'],
        intro: 'Menu Pengaturan (khusus admin) berisi beberapa tab konfigurasi sistem.',
        ordered: false,
        list: [
            { strong: 'Umum', text: 'nama aplikasi dan logo (white-label).' },
            { strong: 'ACS / TR069', text: 'endpoint ACS default untuk fitur TR069 massal.' },
            { strong: 'Alarm', text: 'pilih perilaku notifikasi: Realtime atau Konfirmasi 2 poll (anti-flap).' },
            { strong: 'Bot Telegram', text: 'token bot, chat id, severity minimum, pemicu (raise/clear), filter jenis alarm, dan webhook perintah bot.' },
            { strong: 'Notifikasi Mobile', text: 'pengaturan push FCM ke aplikasi Android + kirim notifikasi manual.' },
            { strong: 'API & Token', text: 'terbitkan/cabut token API (untuk integrasi & aplikasi mobile).' },
        ],
    },
    {
        id: 'users', icon: Users, accent: 'violet', title: 'Pengguna & Audit Log', badges: ['Admin'],
        intro: 'Admin mengelola akun dan menelusuri aktivitas sistem.',
        ordered: false,
        list: [
            { strong: 'Users', text: 'tambah/ubah/hapus pengguna dan tetapkan peran (admin/operator/partner).' },
            { strong: 'Audit Logs', text: 'jejak perubahan penting (siapa mengubah apa dan kapan).' },
        ],
    },
    {
        id: 'partner', icon: Send, accent: 'fuchsia', title: 'Partner Self-Service', badges: ['Partner'],
        intro: 'Akun partner hanya melihat OLT yang di-assign untuknya dan mengelola bot Telegram sendiri.',
        ordered: false,
        list: [
            { strong: 'OLT privat', text: 'OLT milik partner tersembunyi dari admin/operator lain.' },
            { strong: 'Bot Telegram Saya', text: 'partner memasang bot & penerima notifikasi sendiri, independen dari bot global.' },
        ],
    },
    {
        id: 'mobile', icon: Smartphone, accent: 'teal', title: 'Aplikasi Android',
        intro: 'Tersedia aplikasi Android untuk pemantauan di perangkat mobile.',
        ordered: false,
        list: [
            { text: 'Unduh APK dari tautan di Pengaturan (bila tersedia di server).' },
            { text: 'Login dengan akun yang sama; dukung dashboard, pencarian, OLT→port→ONU, unconfigured+registrasi dasar, reboot/rename, alarm, dan push notifikasi.' },
        ],
    },
    {
        id: 'troubleshooting', icon: LifeBuoy, accent: 'rose', title: 'Tips & Pemecahan Masalah',
        intro: 'Beberapa hal yang sering ditanyakan.',
        ordered: false,
        list: [
            { strong: 'Test SNMP jumlah ONU 0', text: 'periksa IP/port/community SNMP dan pastikan OLT mengizinkan akses SNMP dari server.' },
            { strong: 'Alarm terasa telat', text: 'itu efek mode Konfirmasi 2 poll (default). Untuk lebih cepat, aktifkan Realtime di Pengaturan → Alarm.' },
            { strong: 'Tidak menerima notifikasi', text: 'cek saklar alarm OLT On, dan pengaturan Telegram/Mobile aktif dengan severity/jenis yang sesuai.' },
            { strong: 'Tombol aksi hilang/terkunci', text: 'kemungkinan peran Anda tidak berwenang, akun mode demo, atau vendor OLT tak mendukung aksi tersebut.' },
        ],
    },
];

const accentOf = (sec) => ACCENTS[sec.accent] ?? ACCENTS.cyan;

const activeId = ref(sections[0].id);
const activeIndex = computed(() => Math.max(0, sections.findIndex((s) => s.id === activeId.value)));
const scrollTo = (id) => {
    activeId.value = id;
    document.getElementById(`sec-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

// Scroll-spy: sorot bagian yang sedang di sekitar sepertiga atas viewport.
let observer = null;
onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((e) => {
                if (e.isIntersecting) activeId.value = e.target.id.replace('sec-', '');
            });
        },
        { rootMargin: '-25% 0px -65% 0px', threshold: 0 },
    );
    sections.forEach((s) => {
        const el = document.getElementById(`sec-${s.id}`);
        if (el) observer.observe(el);
    });
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <Head title="Panduan Penggunaan" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold leading-tight text-white sm:text-xl">Panduan Penggunaan</h2>
        </template>

        <div class="min-h-[60vh] pt-5 pb-16 sm:pt-8">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <!-- Hero -->
                <div class="relative mb-7 overflow-hidden rounded-2xl border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl">
                    <div class="pointer-events-none absolute -right-20 -top-24 h-72 w-72 rounded-full bg-cyan-500/20 blur-3xl"></div>
                    <div class="pointer-events-none absolute -bottom-24 left-10 h-56 w-56 rounded-full bg-violet-500/15 blur-3xl"></div>
                    <div class="relative p-6 sm:p-8">
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1 text-xs font-medium text-cyan-200">
                            <Sparkles class="h-3.5 w-3.5" /> Pusat Bantuan
                        </span>
                        <h1 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">
                            <span class="bg-gradient-to-r from-white via-cyan-100 to-sky-300 bg-clip-text text-transparent">Cara menggunakan {{ appName }}</span>
                        </h1>
                        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-300 sm:text-base">
                            Panduan lengkap fitur aplikasi — dari login, mengelola OLT, registrasi ONU, pemantauan &amp; alarm, sampai pengaturan.
                            Gunakan daftar isi untuk melompat ke bagian yang Anda butuhkan.
                        </p>
                        <div class="mt-5 flex flex-wrap items-center gap-2.5">
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-200">
                                <BookOpen class="h-3.5 w-3.5 text-cyan-300" /> {{ sections.length }} topik
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-200">
                                <Users class="h-3.5 w-3.5 text-emerald-300" /> Untuk semua peran
                            </span>
                            <span class="hidden items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-200 sm:inline-flex">
                                <kbd class="rounded border border-white/15 bg-slate-800 px-1.5 py-0.5 font-mono text-[10px] text-slate-300">⌘K</kbd> Pencarian cepat
                            </span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[268px_minmax(0,1fr)]">
                    <!-- Daftar isi (sticky di bawah header desktop 72px) -->
                    <aside class="lg:sticky lg:top-[84px] lg:self-start">
                        <div class="rounded-2xl border border-white/10 bg-slate-900/40 p-3 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="flex items-center justify-between px-2 pb-2 pt-1">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Daftar Isi</p>
                                <span class="rounded-full bg-white/5 px-2 py-0.5 text-[10px] font-medium text-slate-400">{{ activeIndex + 1 }}/{{ sections.length }}</span>
                            </div>
                            <nav class="flex flex-row flex-wrap gap-1 lg:max-h-[calc(100vh-7rem)] lg:flex-col lg:flex-nowrap lg:overflow-y-auto lg:pr-1">
                                <button
                                    v-for="(sec, idx) in sections"
                                    :key="sec.id"
                                    type="button"
                                    class="group flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm ring-1 ring-transparent transition-all duration-150"
                                    :class="activeId === sec.id ? accentOf(sec).active : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                                    @click="scrollTo(sec.id)"
                                >
                                    <component :is="sec.icon" class="h-4 w-4 flex-shrink-0" :class="activeId === sec.id ? accentOf(sec).icon : 'text-slate-500 group-hover:text-slate-300'" />
                                    <span class="hidden w-4 flex-shrink-0 text-right text-xs tabular-nums text-slate-500 sm:inline">{{ idx + 1 }}</span>
                                    <span class="truncate">{{ sec.title }}</span>
                                </button>
                            </nav>
                        </div>
                    </aside>

                    <!-- Konten -->
                    <div class="space-y-5">
                        <section
                            v-for="(sec, idx) in sections"
                            :id="`sec-${sec.id}`"
                            :key="sec.id"
                            class="kv-spotlight kv-ring group relative scroll-mt-24 overflow-hidden rounded-2xl border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl transition-all duration-200 hover:border-white/20"
                        >
                            <!-- Aksen atas per-bagian -->
                            <div class="h-1 bg-gradient-to-r to-transparent" :class="accentOf(sec).bar"></div>

                            <div class="flex items-center gap-3.5 border-b border-white/10 px-5 py-4 sm:px-6">
                                <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl ring-1" :class="accentOf(sec).tile">
                                    <component :is="sec.icon" class="h-5 w-5" />
                                </div>
                                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-sm font-bold tabular-nums" :class="accentOf(sec).num">{{ String(idx + 1).padStart(2, '0') }}</span>
                                        <h3 class="text-base font-semibold leading-snug text-white sm:text-lg">{{ sec.title }}</h3>
                                    </div>
                                    <div v-if="(sec.badges ?? []).length" class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="badge in sec.badges"
                                            :key="badge"
                                            class="rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-300 ring-1 ring-amber-500/30"
                                        >
                                            Khusus {{ badge }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="px-5 py-5 sm:px-6">
                                <p class="text-sm leading-relaxed text-slate-300">{{ sec.intro }}</p>

                                <component
                                    :is="sec.ordered ? 'ol' : 'ul'"
                                    class="mt-4 space-y-3"
                                >
                                    <li
                                        v-for="(item, i) in sec.list"
                                        :key="i"
                                        class="flex gap-3 text-sm leading-relaxed text-slate-300"
                                    >
                                        <span
                                            v-if="sec.ordered"
                                            class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full text-[11px] font-bold tabular-nums ring-1"
                                            :class="accentOf(sec).step"
                                        >{{ i + 1 }}</span>
                                        <span v-else class="mt-2 h-1.5 w-1.5 flex-shrink-0 rounded-full" :class="accentOf(sec).bullet"></span>
                                        <span>
                                            <span v-if="item.strong" class="font-semibold text-white">{{ item.strong }} — </span>{{ item.text }}
                                        </span>
                                    </li>
                                </component>

                                <div v-if="sec.tip" class="mt-5 flex items-start gap-2.5 rounded-xl border border-cyan-500/25 bg-cyan-500/[0.07] px-4 py-3">
                                    <LifeBuoy class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-300" />
                                    <p class="text-xs leading-relaxed text-cyan-100/90"><span class="font-semibold text-cyan-200">Tips —</span> {{ sec.tip }}</p>
                                </div>
                            </div>
                        </section>

                        <!-- Penutup -->
                        <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-slate-900/40 px-5 py-4 text-sm text-slate-400 shadow-lg shadow-black/30 backdrop-blur-xl">
                            <div class="kv-icon-tile-sm"><ScrollText class="h-4 w-4" /></div>
                            <p>Butuh detail teknis lebih lanjut? Dokumentasi pengembang tersedia di <span class="font-mono text-xs text-slate-300">docs/handbook/</span>. Untuk bantuan langsung, hubungi admin sistem Anda.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
