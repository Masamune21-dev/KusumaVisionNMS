import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_staggered_animations/flutter_staggered_animations.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/api/api_exception.dart';
import '../../core/format.dart';
import '../../core/json.dart';
import '../../core/providers.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/stagger.dart';
import '../../data/read_providers.dart';
import '../../theme/app_theme.dart';
import '../auth/auth_controller.dart';

/// ONU unconfigured (autofind) sebuah OLT ZTE — dengan discovery live & CTA Register.
class UnconfiguredScreen extends ConsumerStatefulWidget {
  const UnconfiguredScreen({super.key, required this.oltId});
  final int oltId;

  @override
  ConsumerState<UnconfiguredScreen> createState() => _UnconfiguredScreenState();
}

class _UnconfiguredScreenState extends ConsumerState<UnconfiguredScreen> {
  bool _busy = false;

  Future<void> _discover() async {
    setState(() => _busy = true);
    try {
      final res = await ref.read(nmsApiProvider).refreshUnconfigured(widget.oltId);
      ref.invalidate(unconfiguredProvider(widget.oltId));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('Discovery selesai: ${res['count'] ?? 0} ONU ditemukan.'),
          backgroundColor: AppColors.success.withValues(alpha: 0.9),
        ));
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(e.message),
          backgroundColor: AppColors.danger.withValues(alpha: 0.9),
        ));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(unconfiguredProvider(widget.oltId));
    final canWrite = ref.watch(authControllerProvider).user?.canWrite ?? false;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Unconfigured ONU'),
        actions: [
          if (canWrite)
            IconButton(
              tooltip: 'Discovery live',
              onPressed: _busy ? null : _discover,
              icon: _busy
                  ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(LucideIcons.refreshCw, size: 20),
            ),
        ],
      ),
      body: AuroraBackground(
        animate: false,
        intensity: 0.5,
        child: RefreshIndicator(
        onRefresh: () async => ref.refresh(unconfiguredProvider(widget.oltId).future),
        child: AsyncView<({List<Map<String, dynamic>> onus, bool ok, String? refreshedAt})>(
          value: data,
          onRetry: () => ref.refresh(unconfiguredProvider(widget.oltId)),
          data: (res) {
            if (res.onus.isEmpty) {
              return ListView(
                children: const [
                  SizedBox(height: 80),
                  EmptyState(
                    message: 'Tidak ada ONU unconfigured.\nTekan tombol refresh untuk discovery live.',
                    icon: LucideIcons.searchCheck,
                  ),
                ],
              );
            }
            return AnimationLimiter(
              child: ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
                itemCount: res.onus.length + 1,
                separatorBuilder: (_, __) => const SizedBox(height: 10),
                itemBuilder: (_, i) {
                  if (i == 0) {
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 4),
                      child: Text('${res.onus.length} ONU · refresh ${Fmt.relative(res.refreshedAt)}',
                          style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
                    );
                  }
                  return staggeredItem(
                    i,
                    _UnconfiguredCard(oltId: widget.oltId, data: res.onus[i - 1], canRegister: canWrite),
                  );
                },
              ),
            );
          },
        ),
      ),
      ),
    );
  }
}

class _UnconfiguredCard extends StatelessWidget {
  const _UnconfiguredCard({required this.oltId, required this.data, required this.canRegister});
  final int oltId;
  final Map<String, dynamic> data;
  final bool canRegister;

  @override
  Widget build(BuildContext context) {
    final sn = J.asStrN(data['serial_number']) ?? J.asStrN(data['sn']) ?? '-';
    final slot = J.asIntN(data['slot']);
    final port = J.asIntN(data['port']);
    final model = J.asStrN(data['model']) ?? J.asStrN(data['type_name']);

    return GlassCard(
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: AppColors.warning.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Icon(LucideIcons.plugZap, color: AppColors.warning, size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(sn, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                const SizedBox(height: 3),
                Text(
                  [
                    if (slot != null && port != null) 'Port $slot/$port',
                    if (model != null) model,
                  ].join(' · '),
                  style: const TextStyle(color: AppColors.muted, fontSize: 12),
                ),
              ],
            ),
          ),
          if (canRegister)
            FilledButton(
              onPressed: () {
                final q = <String, String>{
                  if (sn != '-') 'sn': sn,
                  if (slot != null) 'slot': '$slot',
                  if (port != null) 'port': '$port',
                };
                final query = q.entries.map((e) => '${e.key}=${Uri.encodeComponent(e.value)}').join('&');
                context.push('/olts/$oltId/register${query.isEmpty ? '' : '?$query'}');
              },
              style: FilledButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                textStyle: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700),
              ),
              child: const Text('Register'),
            ),
        ],
      ),
    );
  }
}
