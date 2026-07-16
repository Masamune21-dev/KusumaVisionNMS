import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:kusumavision_nms/core/icons.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../core/api/api_exception.dart';
import '../../core/env.dart';
import '../../core/fcm/fcm_service.dart';
import '../../core/providers.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../models/user.dart';
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

  void _copy(String label, String value) {
    Clipboard.setData(ClipboardData(text: value));
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text('$label disalin'),
      backgroundColor: AppColors.surfaceAlt,
      duration: const Duration(seconds: 2),
    ));
  }

  Future<void> _logout() async {
    // PENTING: tombol dialog harus pop memakai context milik DIALOG (dialogCtx),
    // bukan context layar. Layar Akun hidup di navigator cabang StatefulShellRoute,
    // sedangkan dialog di root navigator — Navigator.pop(context) dari sini justru
    // mem-pop halaman /account (IndexedStack jadi kosong → layar hitam) dan
    // dialognya menggantung, logout tak pernah jalan.
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogCtx) => AlertDialog(
        title: const Text('Keluar?'),
        content: const Text('Anda akan keluar dari sesi ini.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogCtx, false), child: const Text('Batal')),
          FilledButton(
            style: FilledButton.styleFrom(
                backgroundColor: AppColors.danger, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(dialogCtx, true),
            child: const Text('Keluar'),
          ),
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
    final t = Theme.of(context).textTheme;
    final topInset = MediaQuery.of(context).padding.top + kToolbarHeight + 8;
    final bottomInset = MediaQuery.of(context).viewPadding.bottom + 110;

    Widget seq(int i, Widget child) => child
        .animate(delay: (i * 70).ms)
        .fadeIn(duration: AppMotion.base)
        .slideY(begin: 0.12, curve: AppMotion.enter);

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(backgroundColor: Colors.transparent, title: const Text('Akun')),
      body: AuroraBackground(
        intensity: 0.7,
        child: ListView(
          padding: EdgeInsets.fromLTRB(16, topInset, 16, bottomInset),
          children: [
            seq(0, _ProfileCard(user: user)),
            const SizedBox(height: 14),

            // --- Notifikasi / tes push ---
            seq(
              1,
              GlassCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        _leadIcon(LucideIcons.bellRing, AppColors.primary),
                        const SizedBox(width: 10),
                        Text('Notifikasi', style: t.titleMedium),
                        const Spacer(),
                        _badge(
                          FcmService.available ? 'FCM aktif' : 'FCM tak aktif',
                          FcmService.available ? AppColors.success : AppColors.faint,
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    Text('Kirim notifikasi tes untuk memastikan push masuk ke HP ini.',
                        style: t.bodySmall?.copyWith(color: AppColors.muted)),
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
            ),
            const SizedBox(height: 14),

            // --- Info aplikasi ---
            seq(
              2,
              GlassCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      _leadIcon(LucideIcons.smartphone, AppColors.secondary),
                      const SizedBox(width: 10),
                      Text('Info Aplikasi', style: t.titleMedium),
                    ]),
                    const SizedBox(height: 6),
                    FutureBuilder<PackageInfo>(
                      future: PackageInfo.fromPlatform(),
                      builder: (_, snap) {
                        final p = snap.data;
                        return Column(
                          children: [
                            _kv('Aplikasi', p?.appName ?? 'KusumaVision NMS'),
                            _kv('Versi', p == null ? '…' : '${p.version} (build ${p.buildNumber})', mono: true),
                            _kv('Package', p?.packageName ?? 'net.kusumavision.nms', mono: true, copyable: true),
                            _kv('Server API', Env.apiBaseUrl, mono: true, copyable: true),
                          ],
                        );
                      },
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 22),

            // --- Logout (dipisah, warna danger) ---
            seq(
              3,
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
            ),
            const SizedBox(height: 18),
            Center(
              child: Text('PT Berkah Media Kusuma Vision',
                  style: t.labelSmall?.copyWith(color: AppColors.faint)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _leadIcon(IconData icon, Color color) => Container(
        padding: const EdgeInsets.all(7),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.14),
          borderRadius: BorderRadius.circular(AppRadius.chip),
        ),
        child: Icon(icon, size: 16, color: color),
      );

  Widget _badge(String text, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.14),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: color.withValues(alpha: 0.4)),
        ),
        child: Text(text, style: TextStyle(color: color, fontSize: 11.5, fontWeight: FontWeight.w700)),
      );

  Widget _kv(String k, String v, {bool mono = false, bool copyable = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 7),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            SizedBox(width: 92, child: Text(k, style: const TextStyle(color: AppColors.muted, fontSize: 13))),
            Expanded(
              child: Text(v,
                  style: mono
                      ? AppText.mono(size: 12.5, weight: FontWeight.w600, color: AppColors.text)
                      : const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
            ),
            if (copyable)
              InkWell(
                onTap: () => _copy(k, v),
                borderRadius: BorderRadius.circular(8),
                child: const Padding(
                  padding: EdgeInsets.all(6),
                  child: Icon(LucideIcons.copy, size: 15, color: AppColors.faint),
                ),
              ),
          ],
        ),
      );
}

/// Kartu hero profil — avatar cincin-gradient + nama + chip peran tunggal.
class _ProfileCard extends StatelessWidget {
  const _ProfileCard({required this.user});
  final AppUser? user;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final initial = (user?.name.isNotEmpty ?? false) ? user!.name[0].toUpperCase() : '?';
    final admin = user?.isAdmin ?? false;
    final roleColor = admin ? AppColors.primary : AppColors.secondary;

    return GlassCard(
      blur: true,
      padding: const EdgeInsets.fromLTRB(20, 22, 20, 20),
      child: Column(
        children: [
          Container(
            padding: const EdgeInsets.all(3),
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: AppGradient.accent,
              boxShadow: AppShadow.glow(AppColors.primary, alpha: 0.35, blur: 26),
            ),
            child: CircleAvatar(
              radius: 36,
              backgroundColor: AppColors.bgElevated,
              child: Text(initial,
                  style: const TextStyle(
                      fontFamily: AppFont.display,
                      color: AppColors.primary,
                      fontSize: 30,
                      fontWeight: FontWeight.w800)),
            ),
          ),
          const SizedBox(height: 14),
          Text(user?.name ?? '-', style: t.titleLarge, textAlign: TextAlign.center),
          const SizedBox(height: 3),
          Text(user?.email ?? '-',
              style: t.bodyMedium?.copyWith(color: AppColors.muted), textAlign: TextAlign.center),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.center,
            children: [
              _RoleChip(
                label: user?.roleLabel ?? '-',
                color: roleColor,
                icon: admin ? LucideIcons.shieldCheck : LucideIcons.user,
              ),
              if (user?.isDemo ?? false)
                _capBadge('Demo · read-only', AppColors.warning)
              else if (!(user?.canWrite ?? false))
                _capBadge('Read-only', AppColors.faint),
            ],
          ),
        ],
      ),
    );
  }

  Widget _capBadge(String text, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.13),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: color.withValues(alpha: 0.36)),
        ),
        child: Text(text, style: TextStyle(color: color, fontSize: 11.5, fontWeight: FontWeight.w700)),
      );
}

class _RoleChip extends StatelessWidget {
  const _RoleChip({required this.label, required this.color, required this.icon});
  final String label;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.only(left: 9, right: 13, top: 6, bottom: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: 0.42)),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 14, color: color),
        const SizedBox(width: 6),
        Text(label, style: TextStyle(color: color, fontSize: 12.5, fontWeight: FontWeight.w700)),
      ]),
    );
  }
}
