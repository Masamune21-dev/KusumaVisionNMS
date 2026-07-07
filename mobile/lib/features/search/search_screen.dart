import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_staggered_animations/flutter_staggered_animations.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/widgets/async_view.dart';
import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/stagger.dart';
import '../../data/read_providers.dart';
import '../../models/search_result.dart';
import '../../theme/app_theme.dart';

class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key});

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  final _controller = TextEditingController();
  final _focus = FocusNode();
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _focus.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _onChanged(String v) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      ref.read(searchQueryProvider.notifier).state = v;
    });
  }

  void _open(SearchResult r) {
    if (r.isOnu && r.hasPort) {
      final focus = r.onuId != null ? '?focus=${r.onuId}' : '';
      context.push('/olts/${r.oltId}/ports/${r.slot}/${r.port}$focus');
    } else {
      context.push('/olts/${r.oltId}');
    }
  }

  @override
  Widget build(BuildContext context) {
    final query = ref.watch(searchQueryProvider);
    final results = ref.watch(searchProvider);
    final topInset = MediaQuery.of(context).padding.top + kToolbarHeight;
    final bottomInset = MediaQuery.of(context).viewPadding.bottom + 88;
    final focused = _focus.hasFocus;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(backgroundColor: Colors.transparent, title: const Text('Pencarian global')),
      body: AuroraBackground(
        intensity: 0.65,
        child: Column(
          children: [
            SizedBox(height: topInset),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
              child: AnimatedContainer(
                duration: AppMotion.base,
                curve: AppMotion.enter,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(AppRadius.control),
                  boxShadow: focused ? AppShadow.glow(AppColors.primary, alpha: 0.28, blur: 22) : null,
                ),
                child: TextField(
                  controller: _controller,
                  focusNode: _focus,
                  autofocus: true,
                  decoration: InputDecoration(
                    hintText: 'Cari OLT, SN ONU, nama pelanggan…',
                    prefixIcon: Icon(LucideIcons.search,
                        size: 18, color: focused ? AppColors.primary : AppColors.faint),
                    suffixIcon: _controller.text.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(LucideIcons.x, size: 18),
                            onPressed: () {
                              _controller.clear();
                              ref.read(searchQueryProvider.notifier).state = '';
                              setState(() {});
                            },
                          ),
                  ),
                  onChanged: (v) {
                    _onChanged(v);
                    setState(() {});
                  },
                ),
              ),
            ),
            Expanded(
              child: query.trim().length < 2
                  ? const EmptyState(
                      message: 'Ketik minimal 2 karakter untuk mencari.', icon: LucideIcons.search)
                  : AsyncView<List<SearchResult>>(
                      value: results,
                      onRetry: () => ref.refresh(searchProvider),
                      data: (list) {
                        if (list.isEmpty) {
                          return const EmptyState(
                              message: 'Tidak ada hasil.', icon: LucideIcons.searchX);
                        }
                        return AnimationLimiter(
                          child: ListView.separated(
                            padding: EdgeInsets.fromLTRB(16, 4, 16, bottomInset),
                            itemCount: list.length,
                            separatorBuilder: (_, __) => const SizedBox(height: 8),
                            itemBuilder: (_, i) =>
                                staggeredItem(i, _ResultRow(result: list[i], onTap: () => _open(list[i]))),
                          ),
                        );
                      },
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ResultRow extends StatelessWidget {
  const _ResultRow({required this.result, required this.onTap});
  final SearchResult result;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isOnu = result.isOnu;
    final t = Theme.of(context).textTheme;
    return GlassCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(9),
            decoration: BoxDecoration(
              color: (isOnu ? AppColors.primary : AppColors.secondary).withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(11),
            ),
            child: Icon(isOnu ? LucideIcons.router : LucideIcons.server,
                size: 18, color: isOnu ? AppColors.primary : AppColors.secondary),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(result.label,
                    maxLines: 1, overflow: TextOverflow.ellipsis, style: t.titleSmall),
                if (result.sublabel != null)
                  Text(result.sublabel!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: t.bodySmall?.copyWith(color: AppColors.muted)),
              ],
            ),
          ),
          const Icon(LucideIcons.chevronRight, color: AppColors.faint, size: 18),
        ],
      ),
    );
  }
}
