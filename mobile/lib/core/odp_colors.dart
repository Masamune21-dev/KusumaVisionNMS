import 'package:flutter/material.dart';

/// Warna pin ODP. Paletnya TIDAK didefinisikan di sini — dikirim server lewat
/// `meta.color_palette` pada `GET /odps` (sumber: App\Support\OdpColors) supaya
/// web, API, dan aplikasi memakai daftar yang sama. Yang tinggal di klien hanya
/// nilai bawaan + parsing/kontras.

/// Harus sama dengan `OdpColors::DEFAULT` di server.
const String kDefaultOdpColorHex = '#f59e0b';
const Color kDefaultOdpColor = Color(0xFFF59E0B);

/// Warna efektif sebuah ODP; hex kosong/rusak → warna bawaan.
Color odpColorOf(String? hex) {
  final value = (hex ?? '').trim();
  if (!RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(value)) return kDefaultOdpColor;

  return Color(0xFF000000 | int.parse(value.substring(1), radix: 16));
}

/// Warna teks/ikon yang terbaca di atas [background] — palet punya warna sangat
/// terang maupun gelap, jadi tak bisa dipatok satu warna.
Color odpTextOn(Color background) =>
    background.computeLuminance() > 0.45 ? const Color(0xFF0F172A) : Colors.white;
