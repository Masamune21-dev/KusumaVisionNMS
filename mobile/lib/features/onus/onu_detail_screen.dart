import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/api/api_exception.dart';
import '../../core/format.dart';
import '../../core/providers.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/rx_power_badge.dart';
import '../../core/widgets/status_chip.dart';
import '../../data/read_providers.dart';
import '../../models/onu.dart';
import '../../theme/app_theme.dart';
import '../auth/auth_controller.dart';

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
      backgroundColor: error ? AppColors.danger.withValues(alpha: 0.9) : AppColors.success.withValues(alpha: 0.9),
    ));
  }

  Future<void> _reboot() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: AppColors.bgElevated,
        title: const Text('Reboot ONU?'),
        content: const Text('ONU akan restart selama 30–60 detik.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Reboot')),
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
        backgroundColor: AppColors.bgElevated,
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

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(onuDetailProvider(_arg));
    final user = ref.watch(authControllerProvider).user;
    final caps = ref.watch(oltDetailProvider(widget.oltId)).valueOrNull?.capabilities ?? const {};
    final canWrite = (user?.canWrite ?? false);
    final canReboot = canWrite && caps['supports_reboot'] == true;
    final canRename = canWrite && caps['supports_onu_info_write'] == true;

    return Scaffold(
      appBar: AppBar(title: const Text('Detail ONU')),
      body: RefreshIndicator(
        onRefresh: () async => ref.refresh(onuDetailProvider(_arg).future),
        child: AsyncView<Onu>(
          value: data,
          onRetry: () => ref.refresh(onuDetailProvider(_arg)),
          data: (o) => ListView(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
            children: [
              _Header(onu: o),
              const SizedBox(height: 14),
              _Info(onu: o),
              if (canReboot || canRename) ...[
                const SizedBox(height: 16),
                Row(
                  children: [
                    if (canRename)
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: _busy ? null : () => _rename(o),
                          icon: const Icon(Icons.edit_outlined, size: 18),
                          label: const Text('Ubah nama'),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: AppColors.secondary,
                            side: const BorderSide(color: AppColors.border),
                            padding: const EdgeInsets.symmetric(vertical: 14),
                          ),
                        ),
                      ),
                    if (canRename && canReboot) const SizedBox(width: 12),
                    if (canReboot)
                      Expanded(
                        child: FilledButton.icon(
                          onPressed: _busy ? null : _reboot,
                          icon: _busy
                              ? const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2))
                              : const Icon(Icons.restart_alt, size: 18),
                          label: const Text('Reboot'),
                          style: FilledButton.styleFrom(backgroundColor: AppColors.warning, foregroundColor: const Color(0xFF241A00)),
                        ),
                      ),
                  ],
                ),
              ],
            ],
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
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(onu.title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          const SizedBox(height: 4),
          Text('${onu.interface ?? 'ONU ${onu.onuId}'} · ${onu.oltName ?? ''}',
              style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
          const SizedBox(height: 14),
          Row(
            children: [
              StatusChip.online(onu.online),
              const SizedBox(width: 8),
              RxPowerBadge(dbm: onu.rxPowerDbm, online: onu.online),
              const Spacer(),
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
    final rows = <(String, String?)>[
      ('Serial Number', onu.serialNumber),
      ('MAC', onu.mac),
      ('Tipe ONU', onu.typeName),
      ('Nama', onu.name),
      ('Deskripsi', onu.description),
      ('Pelanggan', onu.customerName),
      ('Admin state', onu.adminState),
      ('Phase state', onu.phaseState),
      ('RX power', onu.online ? Fmt.rx(onu.rxPowerDbm) : '— (offline)'),
      ('Penyebab down', onu.lastDownCause),
      ('Slot / Port / ID', '${onu.slot} / ${onu.port} / ${onu.onuId}'),
    ];

    return GlassCard(
      child: Column(
        children: [
          for (final r in rows)
            if ((r.$2 ?? '').trim().isNotEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 7),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(
                      width: 130,
                      child: Text(r.$1, style: const TextStyle(color: AppColors.muted, fontSize: 13)),
                    ),
                    Expanded(
                      child: Text(r.$2!,
                          style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                    ),
                  ],
                ),
              ),
        ],
      ),
    );
  }
}
