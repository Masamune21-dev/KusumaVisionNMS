// Diff dua teks per-baris (berbasis LCS) untuk membandingkan dua versi running-config OLT.
// Mengembalikan daftar baris beranotasi: { type: 'same' | 'add' | 'del', text }.
// 'del' = ada di versi A saja, 'add' = ada di versi B saja.
//
// Optimasi: potong prefix & suffix yang sama lebih dulu supaya LCS hanya berjalan di bagian
// tengah yang berubah — config OLT umumnya besar tapi perubahannya kecil. Ada guard ukuran agar
// tak meledak di memori untuk config sangat besar dengan banyak perubahan.

function lcsDiff(a, b) {
    const n = a.length;
    const m = b.length;

    if (n === 0) return b.map((text) => ({ type: 'add', text }));
    if (m === 0) return a.map((text) => ({ type: 'del', text }));

    const lcs = Array.from({ length: n + 1 }, () => new Int32Array(m + 1));
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            lcs[i][j] = a[i] === b[j] ? lcs[i + 1][j + 1] + 1 : Math.max(lcs[i + 1][j], lcs[i][j + 1]);
        }
    }

    const out = [];
    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (a[i] === b[j]) {
            out.push({ type: 'same', text: a[i] });
            i++;
            j++;
        } else if (lcs[i + 1][j] >= lcs[i][j + 1]) {
            out.push({ type: 'del', text: a[i] });
            i++;
        } else {
            out.push({ type: 'add', text: b[j] });
            j++;
        }
    }
    while (i < n) out.push({ type: 'del', text: a[i++] });
    while (j < m) out.push({ type: 'add', text: b[j++] });

    return out;
}

export function lineDiff(aText, bText) {
    let a = String(aText ?? '').split('\n');
    let b = String(bText ?? '').split('\n');

    const head = [];
    const tail = [];

    let start = 0;
    while (start < a.length && start < b.length && a[start] === b[start]) {
        head.push({ type: 'same', text: a[start] });
        start++;
    }
    a = a.slice(start);
    b = b.slice(start);

    while (a.length && b.length && a[a.length - 1] === b[b.length - 1]) {
        tail.unshift({ type: 'same', text: a[a.length - 1] });
        a = a.slice(0, -1);
        b = b.slice(0, -1);
    }

    // Guard: config raksasa dengan perubahan besar → tampilkan sebagai del+add murni (tanpa LCS berat).
    const middle = a.length * b.length > 4000000
        ? [...a.map((text) => ({ type: 'del', text })), ...b.map((text) => ({ type: 'add', text }))]
        : lcsDiff(a, b);

    return [...head, ...middle, ...tail];
}

/** Ringkas jumlah baris tambah/hapus dari hasil {@see lineDiff}. */
export function diffStats(diff) {
    let added = 0;
    let removed = 0;
    for (const row of diff) {
        if (row.type === 'add') added++;
        else if (row.type === 'del') removed++;
    }
    return { added, removed, changed: added + removed };
}
