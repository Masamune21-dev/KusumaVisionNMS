import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../features/auth/auth_controller.dart';
import '../../theme/app_theme.dart';

/// Foto dokumentasi ODP (lihat-saja; unggah & hapus tetap di web).
///
/// Berkasnya dilayani rute ber-token (`GET /odps/{id}/photo`), bukan disk publik —
/// jadi permintaan gambar HARUS membawa header Authorization seperti panggilan API
/// lain. `Image.network` mendukung header, tak perlu paket tambahan.
class OdpPhoto extends ConsumerWidget {
  const OdpPhoto({
    super.key,
    required this.url,
    this.height = 150,
    this.onTap,
  });

  final String? url;
  final double height;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final src = url;
    if (src == null || src.isEmpty) return const SizedBox.shrink();

    final token = ref.watch(authControllerProvider).token;

    return GestureDetector(
      onTap: onTap ?? () => showOdpPhotoViewer(context, src, token),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(AppRadius.chip),
        child: Image.network(
          src,
          headers: token == null ? null : {'Authorization': 'Bearer $token'},
          height: height,
          width: double.infinity,
          fit: BoxFit.cover,
          loadingBuilder: (_, child, progress) => progress == null
              ? child
              : Container(
                  height: height,
                  color: AppColors.surfaceAlt,
                  alignment: Alignment.center,
                  child: const CircularProgressIndicator(strokeWidth: 2),
                ),
          errorBuilder: (_, __, ___) => Container(
            height: height,
            color: AppColors.surfaceAlt,
            alignment: Alignment.center,
            child: const Icon(LucideIcons.imageOff, size: 22, color: AppColors.faint),
          ),
        ),
      ),
    );
  }
}

/// Penampil foto ukuran penuh (cubit untuk zoom, ketuk latar untuk menutup).
void showOdpPhotoViewer(BuildContext context, String url, String? token) {
  showDialog<void>(
    context: context,
    barrierColor: const Color(0xE6000000),
    builder: (dialogContext) => GestureDetector(
      onTap: () => Navigator.pop(dialogContext),
      child: Stack(
        children: [
          Center(
            child: InteractiveViewer(
              maxScale: 5,
              child: Image.network(
                url,
                headers: token == null ? null : {'Authorization': 'Bearer $token'},
                fit: BoxFit.contain,
                errorBuilder: (_, __, ___) => const Icon(LucideIcons.imageOff,
                    size: 40, color: AppColors.faint),
              ),
            ),
          ),
          Positioned(
            top: 40,
            right: 16,
            child: IconButton(
              icon: const Icon(LucideIcons.x, color: Colors.white),
              onPressed: () => Navigator.pop(dialogContext),
            ),
          ),
        ],
      ),
    ),
  );
}
