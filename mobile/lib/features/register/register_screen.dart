import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:kusumavision_nms/core/icons.dart';

import '../../core/api/api_exception.dart';
import '../../core/providers.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/glass_card.dart';
import '../../data/read_providers.dart';
import '../../theme/app_theme.dart';

/// Registrasi ONU ZTE — mode dasar (single-service template).
/// Alur: options → form → preview script → eksekusi → hasil.
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({
    super.key,
    required this.oltId,
    this.sn,
    this.slot,
    this.port,
  });

  final int oltId;
  final String? sn;
  final int? slot;
  final int? port;

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _c = <String, TextEditingController>{};
  String _onuType = '';
  String _tcont = '';
  String? _vlanProfile;
  String _wanMode = 'pppoe';
  bool _busy = false;
  bool _initialized = false;

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _ctrl(String key, [String initial = '']) =>
      _c.putIfAbsent(key, () => TextEditingController(text: initial));

  void _initFrom(Map<String, dynamic> defaults) {
    if (_initialized) return;
    _initialized = true;
    _ctrl('serial_number', widget.sn ?? (defaults['serial_number']?.toString() ?? ''));
    _ctrl('slot', (widget.slot ?? defaults['slot'] ?? '').toString());
    _ctrl('port', (widget.port ?? defaults['port'] ?? '').toString());
    _ctrl('onu_id', (defaults['onu_id'] ?? 1).toString());
    _ctrl('customer_name');
    _ctrl('vlan', (defaults['vlan'] ?? 100).toString());
    _ctrl('service_name', defaults['service_name']?.toString() ?? 'ServiceName');
    _ctrl('pppoe_username');
    _ctrl('pppoe_password');
    _ctrl('static_ip');
    _ctrl('static_netmask', defaults['static_netmask']?.toString() ?? '24');
    _ctrl('ip_profile', defaults['ip_profile']?.toString() ?? '');
    _onuType = defaults['onu_type']?.toString() ?? '';
    _tcont = defaults['tcont_profile']?.toString() ?? '';
    _vlanProfile = defaults['vlan_profile']?.toString();
    _wanMode = defaults['wan_mode']?.toString() ?? 'pppoe';
  }

  Map<String, dynamic> _buildForm() => {
        'serial_number': _ctrl('serial_number').text.trim(),
        'slot': int.tryParse(_ctrl('slot').text) ?? 0,
        'port': int.tryParse(_ctrl('port').text) ?? 0,
        'onu_id': int.tryParse(_ctrl('onu_id').text) ?? 0,
        'customer_name': _ctrl('customer_name').text.trim(),
        'onu_type': _onuType,
        'tcont_profile': _tcont,
        'vlan': int.tryParse(_ctrl('vlan').text) ?? 100,
        if (_vlanProfile != null && _vlanProfile!.isNotEmpty) 'vlan_profile': _vlanProfile,
        'service_name': _ctrl('service_name').text.trim(),
        'wan_mode': _wanMode,
        if (_wanMode == 'pppoe') 'pppoe_username': _ctrl('pppoe_username').text.trim(),
        if (_wanMode == 'pppoe') 'pppoe_password': _ctrl('pppoe_password').text.trim(),
        if (_wanMode == 'static') 'static_ip': _ctrl('static_ip').text.trim(),
        if (_wanMode == 'static') 'static_netmask': int.tryParse(_ctrl('static_netmask').text) ?? 24,
        if (_wanMode == 'static') 'ip_profile': _ctrl('ip_profile').text.trim(),
      };

  List<String> _names(Map<String, dynamic> profiles, String type) =>
      ((profiles[type] ?? []) as List).map((e) => e['name'].toString()).toList();

  Future<void> _preview() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      final script = await ref.read(nmsApiProvider).registerPreview(widget.oltId, _buildForm());
      if (!mounted) return;
      showDialog(
        context: context,
        builder: (_) => AlertDialog(
          backgroundColor: AppColors.bgElevated,
          title: const Text('Preview script CLI'),
          content: SingleChildScrollView(
            child: SelectableText(script,
                style: const TextStyle(fontFamily: 'monospace', fontSize: 12, color: AppColors.text)),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('Tutup'))],
        ),
      );
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _execute() async {
    if (!_formKey.currentState!.validate()) return;
    final confirm = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: AppColors.bgElevated,
        title: const Text('Daftarkan ONU ke OLT?'),
        content: const Text('Script akan dieksekusi langsung ke OLT via Telnet.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Daftarkan')),
        ],
      ),
    );
    if (confirm != true) return;

    setState(() => _busy = true);
    try {
      final res = await ref.read(nmsApiProvider).register(widget.oltId, _buildForm(), execute: true);
      if (!mounted) return;
      final ok = res['status'] == 'executed';
      _snack(ok ? 'ONU berhasil diregister ke OLT.' : 'Registrasi ditolak OLT: ${res['error'] ?? ''}', error: !ok);
      if (ok) context.pop();
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _snack(String msg, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: error ? AppColors.danger.withValues(alpha: 0.9) : AppColors.success.withValues(alpha: 0.9),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final arg = (oltId: widget.oltId, slot: widget.slot, port: widget.port, sn: widget.sn);
    final options = ref.watch(registerOptionsProvider(arg));

    return Scaffold(
      appBar: AppBar(title: const Text('Registrasi ONU')),
      body: AsyncView<Map<String, dynamic>>(
        value: options,
        onRetry: () => ref.refresh(registerOptionsProvider(arg)),
        data: (data) {
          final caps = (data['capabilities'] ?? {}) as Map<String, dynamic>;
          if (caps['supports_provisioning'] != true) {
            return const EmptyState(
              message: 'Registrasi hanya didukung OLT ZTE.',
              icon: LucideIcons.alertTriangle,
            );
          }
          _initFrom((data['defaults'] ?? {}) as Map<String, dynamic>);
          final profiles = (data['profiles'] ?? {}) as Map<String, dynamic>;

          return Form(
            key: _formKey,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
              children: [
                GlassCard(
                  child: Column(
                    children: [
                      _text('serial_number', 'Serial Number', required: true),
                      Row(children: [
                        Expanded(child: _text('slot', 'Slot', number: true, required: true)),
                        const SizedBox(width: 10),
                        Expanded(child: _text('port', 'Port', number: true, required: true)),
                        const SizedBox(width: 10),
                        Expanded(child: _text('onu_id', 'ONU ID', number: true, required: true)),
                      ]),
                      _text('customer_name', 'Nama pelanggan', required: true),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                GlassCard(
                  child: Column(
                    children: [
                      _dropdown('Profil ONU Type', _onuType, _names(profiles, 'onu_type'),
                          (v) => setState(() => _onuType = v)),
                      _dropdown('Profil T-CONT', _tcont, _names(profiles, 'tcont'),
                          (v) => setState(() => _tcont = v)),
                      _text('vlan', 'VLAN', number: true, required: true),
                      _dropdownNullable('Profil VLAN (opsional)', _vlanProfile, _names(profiles, 'vlan'),
                          (v) => setState(() => _vlanProfile = v)),
                      _text('service_name', 'Service name', required: true),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                GlassCard(
                  child: Column(
                    children: [
                      _dropdown('Mode WAN', _wanMode, const ['pppoe', 'dhcp', 'static'],
                          (v) => setState(() => _wanMode = v)),
                      if (_wanMode == 'pppoe') ...[
                        _text('pppoe_username', 'PPPoE username'),
                        _text('pppoe_password', 'PPPoE password'),
                      ],
                      if (_wanMode == 'static') ...[
                        _text('static_ip', 'IP statis'),
                        _text('static_netmask', 'Netmask (prefix)', number: true),
                        _dropdown('Profil IP', _ctrl('ip_profile').text, _names(profiles, 'ip'),
                            (v) => _ctrl('ip_profile').text = v),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 18),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: _busy ? null : _preview,
                        icon: const Icon(LucideIcons.searchCode, size: 18),
                        label: const Text('Preview'),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: AppColors.secondary,
                          side: const BorderSide(color: AppColors.border),
                          padding: const EdgeInsets.symmetric(vertical: 14),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton.icon(
                        onPressed: _busy ? null : _execute,
                        icon: _busy
                            ? const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2))
                            : const Icon(LucideIcons.plugZap, size: 18),
                        label: const Text('Daftarkan'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _text(String key, String label, {bool number = false, bool required = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: TextFormField(
          controller: _ctrl(key),
          keyboardType: number ? TextInputType.number : TextInputType.text,
          inputFormatters: number ? [FilteringTextInputFormatter.digitsOnly] : null,
          decoration: InputDecoration(labelText: label, isDense: true),
          validator: required ? (v) => (v == null || v.trim().isEmpty) ? '$label wajib diisi' : null : null,
        ),
      );

  Widget _dropdown(String label, String value, List<String> items, void Function(String) onChanged) {
    final opts = {...items, if (value.isNotEmpty) value}.toList();
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: DropdownButtonFormField<String>(
        initialValue: value.isEmpty ? null : value,
        isExpanded: true,
        decoration: InputDecoration(labelText: label, isDense: true),
        items: opts.map((e) => DropdownMenuItem(value: e, child: Text(e))).toList(),
        onChanged: (v) => v == null ? null : onChanged(v),
        validator: (v) => (v == null || v.isEmpty) ? '$label wajib dipilih' : null,
      ),
    );
  }

  Widget _dropdownNullable(String label, String? value, List<String> items, void Function(String?) onChanged) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: DropdownButtonFormField<String>(
        initialValue: (value != null && items.contains(value)) ? value : null,
        isExpanded: true,
        decoration: InputDecoration(labelText: label, isDense: true),
        items: [
          const DropdownMenuItem(value: '', child: Text('—')),
          ...items.map((e) => DropdownMenuItem(value: e, child: Text(e))),
        ],
        onChanged: (v) => onChanged(v == null || v.isEmpty ? null : v),
      ),
    );
  }
}
