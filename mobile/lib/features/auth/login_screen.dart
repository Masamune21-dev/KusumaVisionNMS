import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/widgets/aurora_background.dart';
import '../../core/widgets/glass_card.dart';
import '../../core/widgets/pulse_logo.dart';
import '../../theme/app_theme.dart';
import 'auth_controller.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _obscure = true;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    await ref.read(authControllerProvider.notifier).login(_email.text, _password.text);
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final t = Theme.of(context).textTheme;

    return Scaffold(
      body: AuroraBackground(
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Column(
                  children: [
                    const SizedBox(height: 8),
                    const PulseLogo(size: 96)
                        .animate()
                        .fadeIn(duration: AppMotion.slow)
                        .scale(begin: const Offset(0.85, 0.85), curve: AppMotion.spring),
                    const SizedBox(height: 20),
                    Text('KusumaVision NMS',
                        style: t.headlineSmall?.copyWith(
                          shadows: [const Shadow(color: Color(0x5522D3EE), blurRadius: 20)],
                        )).animate(delay: 120.ms).fadeIn().slideY(begin: 0.3, curve: AppMotion.enter),
                    const SizedBox(height: 6),
                    Text('Monitoring & provisioning FTTH GPON',
                            style: t.bodyMedium?.copyWith(color: AppColors.muted))
                        .animate(delay: 200.ms)
                        .fadeIn(),
                    const SizedBox(height: 28),
                    GlassCard(
                      blur: true,
                      padding: const EdgeInsets.all(20),
                      child: Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            TextFormField(
                              controller: _email,
                              keyboardType: TextInputType.emailAddress,
                              autofillHints: const [AutofillHints.email],
                              autocorrect: false,
                              decoration: const InputDecoration(
                                labelText: 'Email',
                                prefixIcon: Icon(LucideIcons.mail, size: 18),
                              ),
                              validator: (v) =>
                                  (v == null || !v.contains('@')) ? 'Email tidak valid' : null,
                            ),
                            const SizedBox(height: 14),
                            TextFormField(
                              controller: _password,
                              obscureText: _obscure,
                              autofillHints: const [AutofillHints.password],
                              decoration: InputDecoration(
                                labelText: 'Kata sandi',
                                prefixIcon: const Icon(LucideIcons.lock, size: 18),
                                suffixIcon: IconButton(
                                  tooltip: _obscure ? 'Tampilkan' : 'Sembunyikan',
                                  icon: Icon(_obscure ? LucideIcons.eye : LucideIcons.eyeOff, size: 18),
                                  onPressed: () => setState(() => _obscure = !_obscure),
                                ),
                              ),
                              onFieldSubmitted: (_) => _submit(),
                              validator: (v) =>
                                  (v == null || v.isEmpty) ? 'Kata sandi wajib diisi' : null,
                            ),
                            if (auth.error != null) ...[
                              const SizedBox(height: 14),
                              _ErrorBanner(auth.error!),
                            ],
                            const SizedBox(height: 22),
                            FilledButton(
                              onPressed: auth.busy ? null : _submit,
                              child: auth.busy
                                  ? const SizedBox(
                                      height: 20,
                                      width: 20,
                                      child: CircularProgressIndicator(
                                          strokeWidth: 2.2, color: AppColors.onPrimary))
                                  : const Text('Masuk'),
                            ),
                          ],
                        ),
                      ),
                    ).animate(delay: 280.ms).fadeIn(duration: AppMotion.base).slideY(
                          begin: 0.14,
                          curve: AppMotion.enter,
                        ),
                    const SizedBox(height: 18),
                    Text('PT Berkah Media Kusuma Vision',
                            style: t.labelSmall?.copyWith(color: AppColors.faint))
                        .animate(delay: 420.ms)
                        .fadeIn(),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner(this.message);
  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.control),
        border: Border.all(color: AppColors.danger.withValues(alpha: 0.4)),
      ),
      child: Row(
        children: [
          const Icon(LucideIcons.alertTriangle, color: AppColors.danger, size: 16),
          const SizedBox(width: 8),
          Expanded(
            child: Text(message,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: AppColors.danger)),
          ),
        ],
      ),
    ).animate().fadeIn(duration: AppMotion.fast).slideY(begin: -0.2, curve: AppMotion.enter);
  }
}
