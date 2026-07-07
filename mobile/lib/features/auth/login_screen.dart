import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/widgets/glass_card.dart';
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

    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [Color(0xFF070D18), Color(0xFF0D1B30), Color(0xFF070D18)],
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Column(
                  children: [
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(18),
                      decoration: BoxDecoration(
                        color: AppColors.primary.withValues(alpha: 0.12),
                        shape: BoxShape.circle,
                        border: Border.all(color: AppColors.primary.withValues(alpha: 0.4)),
                        boxShadow: AppShadow.glow(AppColors.primary, alpha: 0.35, blur: 28),
                      ),
                      child: const Icon(LucideIcons.radioTower, color: AppColors.primary, size: 34),
                    ),
                    const SizedBox(height: 20),
                    const Text('KusumaVision NMS',
                        style: TextStyle(
                            fontSize: 23, fontWeight: FontWeight.w900, color: AppColors.text, letterSpacing: -0.5)),
                    const SizedBox(height: 4),
                    const Text('Monitoring & provisioning FTTH GPON',
                        style: TextStyle(color: AppColors.muted, fontSize: 13)),
                    const SizedBox(height: 28),
                    GlassCard(
                      padding: const EdgeInsets.all(20),
                      child: Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            TextFormField(
                              controller: _email,
                              keyboardType: TextInputType.emailAddress,
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
                              decoration: InputDecoration(
                                labelText: 'Kata sandi',
                                prefixIcon: const Icon(LucideIcons.lock, size: 18),
                                suffixIcon: IconButton(
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
                              Row(
                                children: [
                                  const Icon(LucideIcons.alertTriangle, color: AppColors.danger, size: 16),
                                  const SizedBox(width: 8),
                                  Expanded(
                                    child: Text(auth.error!,
                                        style: const TextStyle(color: AppColors.danger, fontSize: 13)),
                                  ),
                                ],
                              ),
                            ],
                            const SizedBox(height: 22),
                            FilledButton(
                              onPressed: auth.busy ? null : _submit,
                              child: auth.busy
                                  ? const SizedBox(
                                      height: 20, width: 20,
                                      child: CircularProgressIndicator(
                                          strokeWidth: 2.2, color: Color(0xFF042027)))
                                  : const Text('Masuk'),
                            ),
                          ],
                        ),
                      ),
                    ),
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
