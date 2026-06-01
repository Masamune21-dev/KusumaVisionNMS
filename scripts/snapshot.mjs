#!/usr/bin/env node
// KusumaVision NMS — snapshot tampilan halaman (Welcome hero+navbar, Login, Dashboard).
// Pakai Playwright (Chromium headless) → PNG, lalu dikonversi ke .webp via `cwebp`.
//
// Jalankan:
//   npm run snapshot                         # Welcome + Login (publik)
//   SNAP_USER=admin@bmkv.net SNAP_PASS=... npm run snapshot   # + Dashboard (perlu login)
//
// Env opsional:
//   BASE_URL (default https://127.0.0.1)  OUT_DIR (default public/img)
//   WIDTH=1920  HEIGHT=1080  DSF=2  WEBP_QUALITY=82
//   ONLY=welcome,login,dashboard          # batasi target

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const BASE_URL = process.env.BASE_URL || 'https://127.0.0.1';
const OUT_DIR = resolve(process.env.OUT_DIR || 'public/img');
const WIDTH = parseInt(process.env.WIDTH || '1920', 10);
const HEIGHT = parseInt(process.env.HEIGHT || '1080', 10);
const DSF = parseFloat(process.env.DSF || '2');
const WEBP_QUALITY = process.env.WEBP_QUALITY || '82';
const SNAP_USER = process.env.SNAP_USER || '';
const SNAP_PASS = process.env.SNAP_PASS || '';
const ONLY = (process.env.ONLY || '').split(',').map((s) => s.trim()).filter(Boolean);

const want = (name) => ONLY.length === 0 || ONLY.includes(name);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

mkdirSync(OUT_DIR, { recursive: true });
const tmp = mkdtempSync(join(tmpdir(), 'kv-snap-'));

/** Screenshot halaman aktif → simpan sebagai <name>.webp di OUT_DIR. */
async function shoot(page, name, { fullPage = false } = {}) {
  const png = join(tmp, `${name}.png`);
  await page.screenshot({ path: png, fullPage });
  const webp = join(OUT_DIR, `${name}.webp`);
  execFileSync('cwebp', ['-quiet', '-q', WEBP_QUALITY, png, '-o', webp]);
  console.log(`  ✓ ${name}.webp  (${fullPage ? 'full page' : `${WIDTH}x${HEIGHT}`})`);
}

async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {});
  // Beri waktu animasi hero (tsParticles/typed.js/AOS/gsap) & chart ApexCharts selesai.
  await sleep(2500);
}

const results = [];

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const context = await browser.newContext({
  viewport: { width: WIDTH, height: HEIGHT },
  deviceScaleFactor: DSF,
  ignoreHTTPSErrors: true, // akses via 127.0.0.1 → cert untuk domain asli
});
const page = await context.newPage();

try {
  console.log(`Snapshot dari ${BASE_URL} → ${OUT_DIR}`);

  // 1) Welcome — navbar + hero (di atas lipatan, bukan full page).
  if (want('welcome')) {
    console.log('• Welcome (hero + navbar)');
    await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await page.evaluate(() => window.scrollTo(0, 0));
    await shoot(page, 'welcome', { fullPage: false });
    results.push('welcome');
  }

  // 2) Login — kartu di tengah viewport.
  if (want('login')) {
    console.log('• Login');
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await shoot(page, 'login', { fullPage: false });
    results.push('login');
  }

  // 3) Dashboard — perlu login; lewati bila kredensial tak ada.
  if (want('dashboard')) {
    if (!SNAP_USER || !SNAP_PASS) {
      console.log('• Dashboard — DILEWATI (set SNAP_USER & SNAP_PASS untuk capture)');
    } else {
      console.log('• Dashboard (login dulu)');
      await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
      await page.fill('#email', SNAP_USER);
      await page.fill('#password', SNAP_PASS);
      await Promise.all([
        page.waitForURL('**/dashboard', { timeout: 30000 }),
        page.click('button[type=submit]'),
      ]);
      await settle(page);
      await shoot(page, 'dashboard', { fullPage: true });
      results.push('dashboard');
    }
  }

  console.log(`\nSelesai: ${results.length ? results.join(', ') : '(tak ada)'} → ${OUT_DIR}`);
} catch (err) {
  console.error('\n✗ Gagal:', err.message);
  process.exitCode = 1;
} finally {
  await browser.close();
  rmSync(tmp, { recursive: true, force: true });
}
