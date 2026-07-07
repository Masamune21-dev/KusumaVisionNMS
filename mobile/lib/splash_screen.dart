import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';

import 'core/widgets/aurora_background.dart';
import 'core/widgets/pulse_logo.dart';
import 'theme/app_theme.dart';

class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      body: AuroraBackground(
        intensity: 1.05,
        child: SafeArea(
          child: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const PulseLogo(size: 128)
                    .animate()
                    .fadeIn(duration: AppMotion.slow)
                    .scale(begin: const Offset(0.85, 0.85), curve: AppMotion.spring),
                const SizedBox(height: 26),
                Text('KusumaVision',
                        style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                              color: AppColors.text,
                              shadows: [const Shadow(color: Color(0x6622D3EE), blurRadius: 22)],
                            ))
                    .animate(delay: 220.ms)
                    .fadeIn(duration: AppMotion.base)
                    .slideY(begin: 0.35, curve: AppMotion.enter),
                const SizedBox(height: 4),
                Text('N   M   S',
                        style: TextStyle(
                          fontFamily: AppFont.body,
                          color: AppColors.muted,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 8,
                        ))
                    .animate(delay: 380.ms)
                    .fadeIn(duration: AppMotion.base),
                const SizedBox(height: 34),
                SizedBox(
                  width: 132,
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                    child: const LinearProgressIndicator(
                      minHeight: 3,
                      backgroundColor: AppColors.surfaceAlt,
                    ),
                  ),
                ).animate(delay: 520.ms).fadeIn(),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
