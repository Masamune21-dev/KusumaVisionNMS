import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/api/api_exception.dart';
import '../../core/odp_colors.dart';
import '../../core/providers.dart';
import '../../data/read_providers.dart';
import '../../theme/app_theme.dart';

/// Lembar pemilih warna pin ODP (paritas modal warna di web).
///
/// Bawaannya mewarnai SEMUA ODP di satu PON port — warna dipakai untuk
/// mengelompokkan ODP per port di peta; saklarnya bisa dimatikan agar hanya ODP
/// ini yang berbeda. Palet datang dari server (`odpColorPaletteProvider`), jadi
/// daftar warnanya sama persis dengan web.
///
/// Mengembalikan `true` bila warna berhasil diubah (pemanggil me-refresh datanya).
Future<bool?> showOdpColorSheet(
  BuildContext context, {
  required int odpId,
  required String odpName,
  required String? currentColor,
  String? portLabel,
  int portCount = 1,
}) {
  return showModalBottomSheet<bool>(
    context: context,
    backgroundColor: Colors.transparent,
    isScrollControlled: true,
    builder: (_) => OdpColorSheet(
      odpId: odpId,
      odpName: odpName,
      currentColor: currentColor,
      portLabel: portLabel,
      portCount: portCount,
    ),
  );
}

class OdpColorSheet extends ConsumerStatefulWidget {
  const OdpColorSheet({
    super.key,
    required this.odpId,
    required this.odpName,
    required this.currentColor,
    this.portLabel,
    this.portCount = 1,
  });

  final int odpId;
  final String odpName;
  final String? currentColor;
  final String? portLabel;
  final int portCount;

  @override
  ConsumerState<OdpColorSheet> createState() => _OdpColorSheetState();
}

class _OdpColorSheetState extends ConsumerState<OdpColorSheet> {
  late String _selected = (widget.currentColor ?? kDefaultOdpColorHex).toLowerCase();
  late bool _applyToPort = widget.portLabel != null;
  bool _busy = false;

  Future<void> _submit({String? color, bool random = false}) async {
    setState(() => _busy = true);
    try {
      final res = await ref.read(nmsApiProvider).setOdpColor(
            widget.odpId,
            color: color,
            random: random,
            applyToPort: widget.portLabel != null && _applyToPort,
          );

      // Semua sumber yang menampilkan warna ODP.
      ref.invalidate(odpsProvider);
      ref.invalidate(odpDetailProvider(widget.odpId));
      ref.invalidate(mapDataProvider);

      if (!mounted) return;
      Navigator.pop(context, true);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Warna pin ODP diperbarui (${res.updated} ODP).'),
        backgroundColor: AppColors.success.withValues(alpha: 0.95),
      ));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _busy = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(e.message),
        backgroundColor: AppColors.danger.withValues(alpha: 0.95),
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final palette = ref.watch(odpColorPaletteProvider);
    final preview = odpColorOf(_selected);

    return Container(
      margin: const EdgeInsets.fromLTRB(12, 0, 12, 16),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 18),
      decoration: BoxDecoration(
        color: AppColors.bgElevated,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: AppColors.borderStrong),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 38,
              height: 4,
              margin: const EdgeInsets.only(bottom: 14),
              decoration: BoxDecoration(
                color: AppColors.border,
                borderRadius: BorderRadius.circular(999),
              ),
            ),
          ),
          Row(
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: preview,
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white70, width: 1.6),
                ),
                child: Icon(LucideIcons.odp, size: 17, color: odpTextOn(preview)),
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Warna pin ODP',
                        style: Theme.of(context).textTheme.titleMedium),
                    Text(
                      [
                        widget.odpName,
                        if (widget.portLabel != null) 'Port ${widget.portLabel}',
                      ].join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: AppColors.muted, fontSize: 12),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),

          // Palet dari server — tanpa daftar warna lokal.
          palette.when(
            loading: () => const Padding(
              padding: EdgeInsets.symmetric(vertical: 18),
              child: Center(child: CircularProgressIndicator(strokeWidth: 2.2)),
            ),
            error: (e, _) => Text(
              e is ApiException ? e.message : 'Gagal memuat palet warna.',
              style: const TextStyle(color: AppColors.danger, fontSize: 12.5),
            ),
            data: (colors) => Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                for (final hex in colors)
                  _Swatch(
                    hex: hex,
                    selected: hex.toLowerCase() == _selected.toLowerCase(),
                    onTap: _busy ? null : () => setState(() => _selected = hex.toLowerCase()),
                  ),
              ],
            ),
          ),

          if (widget.portLabel != null) ...[
            const SizedBox(height: 6),
            SwitchListTile(
              value: _applyToPort,
              onChanged: _busy ? null : (v) => setState(() => _applyToPort = v),
              contentPadding: EdgeInsets.zero,
              dense: true,
              activeThumbColor: AppColors.primary,
              title: Text(
                'Terapkan ke semua ODP di port ${widget.portLabel} (${widget.portCount} ODP)',
                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600),
              ),
              subtitle: const Text(
                'Matikan bila hanya ODP ini yang ingin berbeda.',
                style: TextStyle(color: AppColors.muted, fontSize: 11.5),
              ),
            ),
          ] else ...[
            const SizedBox(height: 12),
            const Text(
              'ODP ini belum punya port PON, jadi warnanya hanya berlaku untuk dirinya sendiri.',
              style: TextStyle(color: AppColors.muted, fontSize: 11.5),
            ),
          ],

          const SizedBox(height: 14),
          Row(
            children: [
              OutlinedButton.icon(
                onPressed: _busy ? null : () => _submit(random: true),
                icon: const Icon(LucideIcons.shuffle, size: 17),
                label: const Text('Acak'),
              ),
              const SizedBox(width: 8),
              OutlinedButton(
                onPressed: _busy ? null : () => _submit(color: null),
                child: const Text('Default'),
              ),
              const Spacer(),
              FilledButton(
                onPressed: _busy ? null : () => _submit(color: _selected),
                child: _busy
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : const Text('Simpan'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Swatch extends StatelessWidget {
  const _Swatch({required this.hex, required this.selected, required this.onTap});

  final String hex;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final color = odpColorOf(hex);

    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(
          color: color,
          borderRadius: BorderRadius.circular(AppRadius.chip),
          border: Border.all(
            color: selected ? Colors.white : Colors.white24,
            width: selected ? 2.4 : 1,
          ),
        ),
        child: selected ? Icon(Icons.check, size: 19, color: odpTextOn(color)) : null,
      ),
    );
  }
}
