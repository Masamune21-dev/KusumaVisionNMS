import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/api/api_exception.dart';
import '../../core/onu_status.dart';
import '../../core/providers.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/kv_art.dart';
import '../../core/widgets/glass_card.dart';
import '../../data/read_providers.dart';
import '../../models/onu.dart';
import '../../theme/app_theme.dart';
import '../auth/auth_controller.dart';
import 'onu_detail_parts.dart';

class OnuDetailScreen extends ConsumerStatefulWidget {
  const OnuDetailScreen({
    super.key,
    required this.oltId,
    required this.slot,
    required this.port,
    required this.onuId,
  });

  final int oltId, slot, port, onuId;

  @override
  ConsumerState<OnuDetailScreen> createState() => _OnuDetailScreenState();
}

class _OnuDetailScreenState extends ConsumerState<OnuDetailScreen> {
  bool _busy = false;

  OnuArg get _arg => (oltId: widget.oltId, slot: widget.slot, port: widget.port, onuId: widget.onuId);

  void _snack(String msg, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: error ? AppColors.danger.withValues(alpha: 0.95) : AppColors.success.withValues(alpha: 0.95),
    ));
  }

  Future<void> _reboot() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Reboot ONU?'),
        content: const Text('ONU akan restart selama 30–60 detik.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppColors.warning, foregroundColor: AppColors.onWarning),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Reboot'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    setState(() => _busy = true);
    try {
      final res = await ref.read(nmsApiProvider).rebootOnu(widget.oltId, widget.slot, widget.port, widget.onuId);
      _snack(res['message']?.toString() ?? 'Perintah reboot terkirim.', error: res['ok'] != true);
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _rename(Onu onu) async {
    final controller = TextEditingController(text: onu.name ?? onu.customerName ?? '');
    final name = await showDialog<String>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Ubah nama ONU'),
        content: TextField(
          controller: controller,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Nama'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(context, controller.text.trim()), child: const Text('Simpan')),
        ],
      ),
    );
    if (name == null || name.isEmpty) return;
    setState(() => _busy = true);
    try {
      await ref.read(nmsApiProvider).renameOnu(widget.oltId, widget.slot, widget.port, widget.onuId, name: name);
      ref.invalidate(onuDetailProvider(_arg));
      _snack('Nama ONU diperbarui.');
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Hapus (deregister) ONU dari OLT — destruktif, konfirmasi danger dulu
  /// (paritas web). Sukses: refresh daftar ONU port lalu keluar dari layar ini.
  Future<void> _delete(Onu onu) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Hapus ONU?'),
        content: Text(
          '${onu.interface ?? 'ONU ${onu.onuId}'} akan dihapus (deregistrasi) '
          'permanen dari OLT. Tindakan ini tidak bisa dibatalkan.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    setState(() => _busy = true);
    try {
      final res = await ref
          .read(nmsApiProvider)
          .deleteOnu(widget.oltId, widget.slot, widget.port, widget.onuId);
      ref.invalidate(portOnusProvider((oltId: widget.oltId, slot: widget.slot, port: widget.port)));
      _snack(res['message']?.toString() ?? 'ONU dihapus dari OLT.', error: res['ok'] != true);
      if (mounted && res['ok'] == true) context.pop();
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(onuDetailProvider(_arg));
    final user = ref.watch(authControllerProvider).user;
    final caps = ref.watch(oltDetailProvider(widget.oltId)).valueOrNull?.capabilities ?? const {};
    final canWrite = (user?.canWrite ?? false);
    final canReboot = canWrite && caps['supports_reboot'] == true;
    final canRename = canWrite && caps['supports_onu_info_write'] == true;
    final canDelete = canWrite && caps['supports_onu_delete'] == true;

    return Scaffold(
      appBar: AppBar(title: const Text('Detail ONU')),
      body: KvBackdrop(
        intensity: 0.5,
        child: RefreshIndicator(
          onRefresh: () async => ref.refresh(onuDetailProvider(_arg).future),
          color: AppColors.primary,
          backgroundColor: AppColors.surfaceAlt,
          child: AsyncView<Onu>(
            value: data,
            onRetry: () => ref.refresh(onuDetailProvider(_arg)),
            data: (o) {
              final status = OnuStatus.of(o);
              // Masuk sekali: fade + naik halus, di-stagger antar seksi.
              Widget seq(int i, Widget child) => child
                  .animate(delay: (i * 60).ms)
                  .fadeIn(duration: AppMotion.base)
                  .slideY(begin: 0.08, curve: AppMotion.enter);

              final actions = <Widget>[
                if (canRename)
                  OnuActionTile(
                    icon: LucideIcons.edit,
                    label: 'Ubah nama',
                    color: AppColors.secondary,
                    onTap: _busy ? null : () => _rename(o),
                  ),
                if (canReboot)
                  OnuActionTile(
                    icon: LucideIcons.restart,
                    label: 'Reboot',
                    color: AppColors.warning,
                    busy: _busy,
                    onTap: _busy ? null : _reboot,
                  ),
              ];

              return ListView(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
                children: [
                  seq(0, OnuHero(onu: o, status: status)),
                  const SizedBox(height: 12),
                  seq(1, OnuPathCard(onu: o, status: status)),
                  const SizedBox(height: 18),
                  seq(2, SectionTitle('Sinyal optik', icon: LucideIcons.signal)),
                  seq(2, OnuSignalCard(onu: o, status: status)),
                  if (actions.isNotEmpty) ...[
                    const SizedBox(height: 18),
                    seq(3, SectionTitle('Aksi', icon: LucideIcons.zap)),
                    seq(
                      3,
                      GlassCard(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 8),
                        child: Row(children: actions),
                      ),
                    ),
                  ],
                  const SizedBox(height: 18),
                  seq(4, SectionTitle('Info teknis', icon: LucideIcons.info)),
                  seq(4, OnuFactGrid(facts: onuFacts(o))),
                  if (canDelete) ...[
                    const SizedBox(height: 20),
                    OutlinedButton.icon(
                      onPressed: _busy ? null : () => _delete(o),
                      icon: const Icon(LucideIcons.trash, size: 18),
                      label: const Text('Hapus ONU dari OLT'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AppColors.danger,
                        side: BorderSide(color: AppColors.danger.withValues(alpha: 0.55)),
                      ),
                    ),
                  ],
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

