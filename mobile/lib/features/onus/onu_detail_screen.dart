import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/api/api_exception.dart';
import '../../core/format.dart';
import '../../core/providers.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/pulse_dot.dart';
import '../../core/widgets/rx_power_badge.dart';
import '../../core/widgets/status_chip.dart';
import '../../data/read_providers.dart';
import '../../models/onu.dart';
import '../../theme/app_theme.dart';
import '../auth/auth_controller.dart';

const _tnum = [FontFeature.tabularFigures()];

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
            style: FilledButton.styleFrom(backgroundColor: AppColors.warning, foregroundColor: const Color(0xFF241A00)),
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
      body: AuroraBackground(
        intensity: 0.5,
        child: RefreshIndicator(
        onRefresh: () async => ref.refresh(onuDetailProvider(_arg).future),
        color: AppColors.primary,
        backgroundColor: AppColors.surfaceAlt,
        child: AsyncView<Onu>(
          value: data,
          onRetry: () => ref.refresh(onuDetailProvider(_arg)),
          data: (o) => ListView(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
            children: [
              _Header(onu: o),
              const SizedBox(height: 14),
              SectionTitle('Informasi', icon: LucideIcons.info),
              _Info(onu: o),
              if (canReboot || canRename) ...[
                const SizedBox(height: 18),
                Row(
                  children: [
                    if (canRename)
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: _busy ? null : () => _rename(o),
                          icon: const Icon(LucideIcons.edit, size: 18),
                          label: const Text('Ubah nama'),
                        ),
                      ),
                    if (canRename && canReboot) const SizedBox(width: 12),
                    if (canReboot)
                      Expanded(
                        child: FilledButton.icon(
                          onPressed: _busy ? null : _reboot,
                          icon: _busy
                              ? const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2))
                              : const Icon(LucideIcons.restart, size: 18),
                          label: const Text('Reboot'),
                          style: FilledButton.styleFrom(
                              backgroundColor: AppColors.warning, foregroundColor: const Color(0xFF241A00)),
                        ),
                      ),
                  ],
                ),
              ],
              if (canDelete) ...[
                const SizedBox(height: 12),
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
          ),
        ),
      ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.onu});
  final Onu onu;

  @override
  Widget build(BuildContext context) {
    final color = onu.online ? AppColors.success : AppColors.danger;
    return GlassCard(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Stack(
                clipBehavior: Clip.none,
                children: [
                  Container(
                    padding: const EdgeInsets.all(11),
                    decoration: BoxDecoration(
                      color: color.withValues(alpha: 0.13),
                      borderRadius: BorderRadius.circular(AppRadius.chip),
                    ),
                    child: Icon(LucideIcons.router, color: color, size: 24),
                  ),
                  Positioned(
                    right: -3,
                    top: -3,
                    child: PulseDot(color: color, size: 9, pulse: onu.online),
                  ),
                ],
              ),
              const SizedBox(width: 13),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(onu.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17, letterSpacing: -0.3)),
                    const SizedBox(height: 3),
                    Text('${onu.interface ?? 'ONU ${onu.onuId}'} · ${onu.oltName ?? ''}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              StatusChip.online(onu.online),
              RxPowerBadge(dbm: onu.rxPowerDbm, online: onu.online),
              if (onu.rxMarginal && onu.online)
                const StatusChip(
                    label: 'RX marginal', color: AppColors.warning, icon: LucideIcons.alertTriangle),
            ],
          ),
        ],
      ),
    );
  }
}

class _Info extends StatelessWidget {
  const _Info({required this.onu});
  final Onu onu;

  @override
  Widget build(BuildContext context) {
    final rows = <(String, String?, bool)>[
      ('Serial Number', onu.serialNumber, true),
      ('MAC', onu.mac, true),
      ('Tipe ONU', onu.typeName, false),
      ('Nama', onu.name, false),
      ('Deskripsi', onu.description, false),
      ('Pelanggan', onu.customerName, false),
      ('Admin state', onu.adminState, false),
      ('Phase state', onu.phaseState, false),
      ('RX power', onu.online ? Fmt.rx(onu.rxPowerDbm) : '— (offline)', true),
      ('Penyebab down', onu.lastDownCause, false),
      ('Slot / Port / ID', '${onu.slot} / ${onu.port} / ${onu.onuId}', true),
    ].where((r) => (r.$2 ?? '').trim().isNotEmpty).toList();

    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      child: Column(
        children: [
          for (var i = 0; i < rows.length; i++) ...[
            if (i > 0) const Divider(height: 1),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 11),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 130,
                    child: Text(rows[i].$1, style: const TextStyle(color: AppColors.muted, fontSize: 13)),
                  ),
                  Expanded(
                    child: SelectableText(
                      rows[i].$2!,
                      style: TextStyle(
                          fontSize: 13, fontWeight: FontWeight.w600, height: 1.3,
                          fontFeatures: rows[i].$3 ? _tnum : null),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}
