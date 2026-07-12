<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    BellRing, BookOpen, Cable, Compass, FileBarChart, KeyRound, LayoutDashboard,
    LifeBuoy, ListChecks, MapPin, PlugZap, Radar, Rocket, ScrollText, Send,
    ShieldCheck, Smartphone, Terminal, Users, Wrench, WifiOff,
} from '@lucide/vue';

const page = usePage();
const appName = computed(() => page.props.branding?.name ?? 'KusumaVision NMS');

/*
 * Panduan penggunaan — konten statis (data-driven) supaya TOC & section
 * tetap sinkron dari satu sumber. Tiap section:
 *   id     : anchor
 *   icon   : ikon Lucide
 *   title  : judul
 *   badges : label akses (opsional) — mis. 'Admin', 'Partner'
 *   intro  : paragraf pembuka
 *   ordered: true → daftar bernomor (langkah), false → poin bullet
 *   list   : [{ strong?, text }]
 *   tip    : callout catatan (opsional)
 */
const sections = [
    {
        id: 'pengantar',
        icon: Rocket,
        title: 'Sekilas Aplikasi',
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
        id: 'peran',
        icon: ShieldCheck,
        title: 'Masuk & Peran Pengguna',
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
        id: 'navigasi',
        icon: Compass,
        title: 'Navigasi & Pencarian',
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
        id: 'dashboard',
        icon: LayoutDashboard,
        title: 'Dashboard',
        intro: 'Halaman pertama setelah login: ringkasan kesehatan jaringan secara menyeluruh.',
        ordered: false,
        list: [
            { text: 'Kartu statistik: jumlah OLT, ONU online/offline, port PON, dan alarm aktif.' },
            { text: 'Sorotan alarm terbaru dan OLT bermasalah untuk tindak lanjut cepat.' },
            { text: 'Angka bersumber dari hasil polling terakhir — jadi mencerminkan kondisi beberapa menit terakhir, bukan real-time detik-per-detik.' },
        ],
    },
    {
        id: 'olt',
        icon: Cable,
        title: 'Mengelola OLT',
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
        id: 'port-onu',
        icon: Radar,
        title: 'Melihat Port PON & ONU',
        intro: 'Dari detail OLT, buka sebuah port PON untuk melihat daftar ONU di bawahnya beserta status dan redaman.',
        ordered: false,
        list: [
            { strong: 'Status ONU', text: 'online/offline, penyebab down terakhir (LOS, dying gasp), dan interface.' },
            { strong: 'Redaman RX (dBm)', text: 'indikator kualitas sinyal; nilai di luar rentang sehat ditandai sebagai peringatan.' },
            { strong: 'Refresh', text: 'tombol refresh melakukan pembacaan SNMP langsung untuk port/OLT tersebut (dibatasi rate agar tak membebani OLT).' },
        ],
    },
    {
        id: 'unconfigured',
        icon: WifiOff,
        title: 'ONU Belum Terdaftar (Unconfigured)',
        intro: 'Menu Unconfigured mengumpulkan ONU yang terdeteksi OLT tetapi belum diregistrasi — kandidat pelanggan baru.',
        ordered: true,
        list: [
            { text: 'Temukan ONU baru berdasarkan serial number pada port PON-nya.' },
            { text: 'Klik Daftarkan untuk membuka form registrasi (provisioning) dengan serial sudah terisi.' },
        ],
    },
    {
        id: 'provisioning',
        icon: PlugZap,
        title: 'Registrasi / Provisioning ONU (ZTE)',
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
        id: 'aksi-onu',
        icon: Wrench,
        title: 'Aksi pada ONU',
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
        id: 'monitoring',
        icon: ListChecks,
        title: 'ONU Monitoring (Lintas OLT)',
        intro: 'Menu ONU Monitoring menggabungkan ONU dari semua OLT dalam satu tampilan untuk pemantauan menyeluruh.',
        ordered: false,
        list: [
            { text: 'Melihat sebaran ONU online/offline lintas OLT tanpa membuka satu-satu.' },
            { text: 'Refresh menjalankan pembacaan SNMP penuh per OLT dan memperbarui cache per-port.' },
        ],
    },
    {
        id: 'peta',
        icon: MapPin,
        title: 'Peta ONU',
        intro: 'Menu Peta ONU menampilkan pin lokasi pelanggan-ONU di peta (Leaflet) untuk semua OLT.',
        ordered: true,
        list: [
            { strong: 'Tambah pin', text: 'klik pada peta lalu pilih OLT → port → ONU, atau gunakan tombol Add Map di halaman ONU per port.' },
            { strong: 'Dari link Google Maps', text: 'tempel tautan lokasi dan koordinat akan otomatis diambil.' },
            { strong: 'Aksi dari pin', text: 'kartu detail pin bisa ganti-nama atau reboot ONU langsung dari peta.' },
        ],
    },
    {
        id: 'alarm',
        icon: BellRing,
        title: 'Alarm & Notifikasi',
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
        id: 'telnet',
        icon: Terminal,
        title: 'Terminal Telnet Browser',
        intro: 'Buka sesi CLI ke OLT langsung dari browser (jendela terminal xterm.js) tanpa aplikasi telnet terpisah.',
        ordered: false,
        list: [
            { text: 'Jendela bisa digeser, di-minimize, dan di-maximize.' },
            { text: 'Berguna untuk perintah CLI manual/diagnostik pada OLT ZTE.' },
        ],
    },
    {
        id: 'report',
        icon: FileBarChart,
        title: 'Report & Ekspor',
        intro: 'Menu Report membuat laporan yang bisa difilter dan diekspor.',
        ordered: false,
        list: [
            { strong: 'Jenis laporan', text: 'antara lain ONU, alarm, dan provisioning.' },
            { strong: 'Filter', text: 'per OLT, per port PON, rentang waktu, status, dan status redaman RX.' },
            { strong: 'Ekspor', text: 'unduh sebagai CSV atau PDF melalui tombol di kanan atas.' },
        ],
    },
    {
        id: 'pengaturan',
        icon: KeyRound,
        title: 'Pengaturan',
        badges: ['Admin'],
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
        id: 'users',
        icon: Users,
        title: 'Pengguna & Audit Log',
        badges: ['Admin'],
        intro: 'Admin mengelola akun dan menelusuri aktivitas sistem.',
        ordered: false,
        list: [
            { strong: 'Users', text: 'tambah/ubah/hapus pengguna dan tetapkan peran (admin/operator/partner).' },
            { strong: 'Audit Logs', text: 'jejak perubahan penting (siapa mengubah apa dan kapan).' },
        ],
    },
    {
        id: 'partner',
        icon: Send,
        title: 'Partner Self-Service',
        badges: ['Partner'],
        intro: 'Akun partner hanya melihat OLT yang di-assign untuknya dan mengelola bot Telegram sendiri.',
        ordered: false,
        list: [
            { strong: 'OLT privat', text: 'OLT milik partner tersembunyi dari admin/operator lain.' },
            { strong: 'Bot Telegram Saya', text: 'partner memasang bot & penerima notifikasi sendiri, independen dari bot global.' },
        ],
    },
    {
        id: 'mobile',
        icon: Smartphone,
        title: 'Aplikasi Android',
        intro: 'Tersedia aplikasi Android untuk pemantauan di perangkat mobile.',
        ordered: false,
        list: [
            { text: 'Unduh APK dari tautan di Pengaturan (bila tersedia di server).' },
            { text: 'Login dengan akun yang sama; dukung dashboard, pencarian, OLT→port→ONU, unconfigured+registrasi dasar, reboot/rename, alarm, dan push notifikasi.' },
        ],
    },
    {
        id: 'troubleshooting',
        icon: LifeBuoy,
        title: 'Tips & Pemecahan Masalah',
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

const activeId = ref(sections[0].id);
const scrollTo = (id) => {
    activeId.value = id;
    document.getElementById(`sec-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
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
                <div class="mb-6 overflow-hidden rounded-xl border border-white/10 bg-slate-900/40 p-5 shadow-lg shadow-black/30 backdrop-blur-xl sm:p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-lg bg-cyan-500/15 ring-1 ring-cyan-500/30">
                            <BookOpen class="h-6 w-6 text-cyan-300" />
                        </div>
                        <div>
                            <h1 class="text-xl font-semibold text-white sm:text-2xl">Cara menggunakan {{ appName }}</h1>
                            <p class="mt-1 max-w-3xl text-sm text-slate-400">
                                Panduan lengkap fitur aplikasi — dari login, mengelola OLT, registrasi ONU, pemantauan &amp; alarm, sampai pengaturan.
                                Gunakan daftar isi untuk melompat ke bagian yang Anda butuhkan.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
                    <!-- Daftar isi -->
                    <aside class="lg:sticky lg:top-6 lg:self-start">
                        <div class="rounded-xl border border-white/10 bg-slate-900/40 p-3 backdrop-blur-xl">
                            <p class="px-2 pb-2 pt-1 text-xs font-semibold uppercase tracking-wider text-slate-500">Daftar Isi</p>
                            <nav class="flex flex-row flex-wrap gap-1 lg:flex-col lg:flex-nowrap">
                                <button
                                    v-for="(sec, idx) in sections"
                                    :key="sec.id"
                                    type="button"
                                    class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm transition-colors"
                                    :class="activeId === sec.id ? 'bg-cyan-500/15 text-cyan-200 ring-1 ring-cyan-500/30' : 'text-slate-300 hover:bg-white/5'"
                                    @click="scrollTo(sec.id)"
                                >
                                    <component :is="sec.icon" class="h-4 w-4 flex-shrink-0 text-cyan-400" />
                                    <span class="hidden sm:inline">{{ idx + 1 }}.</span>
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
                            class="scroll-mt-24 overflow-hidden rounded-xl border border-white/10 bg-slate-900/40 shadow-lg shadow-black/30 backdrop-blur-xl"
                        >
                            <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4 sm:px-6">
                                <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sky-500/15 ring-1 ring-cyan-500/30">
                                    <component :is="sec.icon" class="h-5 w-5 text-cyan-400" />
                                </div>
                                <div class="flex flex-1 flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-white">
                                        <span class="text-slate-500">{{ idx + 1 }}.</span> {{ sec.title }}
                                    </h3>
                                    <span
                                        v-for="badge in sec.badges ?? []"
                                        :key="badge"
                                        class="rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-300"
                                    >
                                        Khusus {{ badge }}
                                    </span>
                                </div>
                            </div>

                            <div class="px-5 py-4 sm:px-6 sm:py-5">
                                <p class="text-sm text-slate-300">{{ sec.intro }}</p>

                                <component
                                    :is="sec.ordered ? 'ol' : 'ul'"
                                    class="mt-4 space-y-2.5"
                                    :class="sec.ordered ? 'list-none' : ''"
                                >
                                    <li
                                        v-for="(item, i) in sec.list"
                                        :key="i"
                                        class="flex gap-3 text-sm text-slate-300"
                                    >
                                        <span
                                            v-if="sec.ordered"
                                            class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-cyan-500/15 text-xs font-semibold text-cyan-300 ring-1 ring-cyan-500/30"
                                        >{{ i + 1 }}</span>
                                        <span v-else class="mt-2 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-cyan-400/70"></span>
                                        <span>
                                            <span v-if="item.strong" class="font-semibold text-white">{{ item.strong }} — </span>{{ item.text }}
                                        </span>
                                    </li>
                                </component>

                                <div v-if="sec.tip" class="mt-4 flex items-start gap-2.5 rounded-lg border border-cyan-500/25 bg-cyan-500/5 px-3.5 py-2.5">
                                    <LifeBuoy class="mt-0.5 h-4 w-4 flex-shrink-0 text-cyan-300" />
                                    <p class="text-xs text-cyan-100/90"><span class="font-semibold">Tips:</span> {{ sec.tip }}</p>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
