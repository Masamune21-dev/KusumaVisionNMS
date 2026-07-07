import '../core/json.dart';

class AppUser {
  const AppUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.roleLabel,
    required this.isAdmin,
    required this.isDemo,
  });

  final int id;
  final String name;
  final String email;
  final String role;
  final String roleLabel;
  final bool isAdmin;
  final bool isDemo;

  /// Boleh melakukan aksi tulis (registrasi/reboot/rename). Sesuai gating server:
  /// admin, operator & partner boleh, demo tidak. Partner otomatis dibatasi ke OLT
  /// yang di-assign (server mengembalikan hanya OLT tsb + 404 di luarnya).
  bool get canWrite =>
      !isDemo && (role == 'admin' || role == 'operator' || role == 'partner');

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: J.asInt(j['id']),
        name: J.asStr(j['name']),
        email: J.asStr(j['email']),
        role: J.asStr(j['role']),
        roleLabel: J.asStr(j['role_label'], j['role']?.toString() ?? ''),
        isAdmin: J.asBool(j['is_admin']),
        isDemo: J.asBool(j['is_demo']),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'role': role,
        'role_label': roleLabel,
        'is_admin': isAdmin,
        'is_demo': isDemo,
      };
}
