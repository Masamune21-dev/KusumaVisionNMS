import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:kusumavision_nms/core/icons.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../core/api/api_exception.dart';
import '../../core/env.dart';
import '../../core/fcm/fcm_service.dart';
import '../../core/providers.dart';
import '../../core/widgets/glass_card.dart';
import '../../theme/app_theme.dart';
import '../auth/auth_controller.dart';

class AccountScreen extends ConsumerStatefulWidget {
  const AccountScreen({super.key});

  @override
  ConsumerState<AccountScreen> createState() => _AccountScreenState();
}

class _AccountScreenState extends ConsumerState<AccountScreen> {
  bool _testing = false;

  Future<void> _testPush() async {
    setState(() => _testing = true);
    try {
      // Pastikan token perangkat terdaftar dulu (minta izin + kirim token).
      await ref.read(fcmServiceProvider).onLogin();
      final res = await ref.read(nmsApiProvider).testPush();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(res.message),
        backgroundColor: (res.ok ? AppColors.success : AppColors.warning).withValues(alpha: 0.95),
        duration: const Duration(seconds: 4),
      ));
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(e.message),
          backgroundColor: AppColors.danger.withValues(alpha: 0.95),
        ));
      }
    } finally {
      if (mounted) setState(() => _testing = false);
    }
  }

  Future<void> _logout() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: AppColors.bgElevated,
        title: const Text('Keluar?'),
        content: const Text('Anda akan keluar dari sesi ini.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Keluar')),
        ],
      ),
    );
    if (ok == true) {
      await ref.read(authControllerProvider.notifier).logout();
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authControllerProvider).user;

    return Scaffold(
      appBar: AppBar(title: const Text('Akun')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
        children: [
          // --- Info akun ---
          GlassCard(
            child: Column(
              children: [
                CircleAvatar(
                  radius: 32,
                  backgroundColor: AppColors.primary.withValues(alpha: 0.15),
                  child: Text(
                    (user?.name.isNotEmpty ?? false) ? user!.name[0].toUpperCase() : '?',
                    style: const TextStyle(color: AppColors.primary, fontSize: 28, fontWeight: FontWeight.w800),
                  ),
                ),
                const SizedBox(height: 12),
                Text(user?.name ?? '-', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                const SizedBox(height: 2),
                Text(user?.email ?? '-', style: const TextStyle(color: AppColors.muted, fontSize: 13)),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  children: [
                    _badge(user?.roleLabel ?? '-', AppColors.secondary),
                    if (user?.isAdmin ?? false) _badge('Admin', AppColors.primary),
                    if (user?.isDemo ?? false) _badge('Demo (read-only)', AppColors.warning),
                    if (!(user?.canWrite ?? false)) _badge('Tak bisa aksi tulis', AppColors.faint),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),

          // --- Notifikasi / tes push ---
          GlassCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(LucideIcons.bellRing, size: 18, color: AppColors.primary),
                    const SizedBox(width: 8),
                    const Text('Notifikasi', style: TextStyle(fontWeight: FontWeight.w700)),
                    const Spacer(),
                    _badge(
                      FcmService.available ? 'FCM aktif' : 'FCM tak aktif',
                      FcmService.available ? AppColors.success : AppColors.faint,
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                const Text(
                  'Kirim notifikasi tes untuk memastikan push masuk ke HP ini.',
                  style: TextStyle(color: AppColors.muted, fontSize: 12.5),
                ),
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _testing ? null : _testPush,
                    icon: _testing
                        ? const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(LucideIcons.bellRing, size: 18),
                    label: Text(_testing ? 'Mengirim…' : 'Tes Push Notifikasi'),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),

          // --- Info aplikasi ---
          GlassCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: const [
                    Icon(LucideIcons.info, size: 18, color: AppColors.secondary),
                    SizedBox(width: 8),
                    Text('Info Aplikasi', style: TextStyle(fontWeight: FontWeight.w700)),
                  ],
                ),
                const SizedBox(height: 10),
                FutureBuilder<PackageInfo>(
                  future: PackageInfo.fromPlatform(),
                  builder: (_, snap) {
                    final p = snap.data;
                    return Column(
                      children: [
                        _kv('Aplikasi', p?.appName ?? 'KusumaVision NMS'),
                        _kv('Versi', p == null ? '…' : '${p.version} (build ${p.buildNumber})'),
                        _kv('Package', p?.packageName ?? 'net.kusumavision.nms'),
                        _kv('Server API', Env.apiBaseUrl),
                      ],
                    );
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // --- Logout ---
          OutlinedButton.icon(
            onPressed: _logout,
            icon: const Icon(LucideIcons.logOut, size: 18),
            label: const Text('Keluar'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.danger,
              side: BorderSide(color: AppColors.danger.withValues(alpha: 0.5)),
              padding: const EdgeInsets.symmetric(vertical: 14),
            ),
          ),
          const SizedBox(height: 16),
          const Center(
            child: Text('PT Berkah Media Kusuma Vision',
                style: TextStyle(color: AppColors.faint, fontSize: 11)),
          ),
        ],
      ),
    );
  }

  Widget _badge(String text, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.14),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: color.withValues(alpha: 0.4)),
        ),
        child: Text(text, style: TextStyle(color: color, fontSize: 11.5, fontWeight: FontWeight.w700)),
      );

  Widget _kv(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(width: 96, child: Text(k, style: const TextStyle(color: AppColors.muted, fontSize: 13))),
            Expanded(child: SelectableText(v, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600))),
          ],
        ),
      );
}
