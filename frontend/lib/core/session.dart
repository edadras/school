import 'dart:convert';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api.dart';

/// Which UI shell a signed-in user lands in.
enum Panel { platform, support, admin, teacher, student, guardian }

class Membership {
  Membership({required this.schoolId, required this.schoolName, required this.schoolCode, required this.role, required this.schoolStatus});
  final int schoolId;
  final String schoolName;
  final String schoolCode;
  final String role;
  final String schoolStatus;

  factory Membership.fromJson(Map j) => Membership(
        schoolId: j['school']['id'], schoolName: j['school']['name'], schoolCode: j['school']['code'] ?? '', role: j['role'], schoolStatus: j['school']['status'] ?? 'active');

  Panel get panel => switch (role) { 'school_admin' || 'deputy' => Panel.admin, 'teacher' => Panel.teacher, 'student' => Panel.student, _ => Panel.guardian };
}

class Session {
  const Session({this.token, this.user, this.activeSchoolId, this.ready = false});
  final String? token;
  final Map<String, dynamic>? user;
  final int? activeSchoolId;
  final bool ready; // storage loaded

  bool get signedIn => token != null && user != null;
  String? get platformRole => user?['platform_role'];
  String get name => (user?['name'] ?? '') as String;
  int get userId => user?['id'] as int;
  List<Membership> get memberships => ((user?['memberships'] as List?) ?? []).map((m) => Membership.fromJson(m as Map)).toList();
  Membership? get active => memberships.where((m) => m.schoolId == activeSchoolId).firstOrNull;
  bool get needsSchoolChoice => platformRole == null && memberships.length > 1 && active == null;

  Panel? get panel {
    if (!signedIn) return null;
    if (platformRole == 'super_admin') return Panel.platform;
    if (platformRole == 'support') return Panel.support;
    return active?.panel;
  }

  Session copy({String? token, Map<String, dynamic>? user, int? activeSchoolId, bool clearSchool = false, bool? ready}) => Session(
      token: token ?? this.token, user: user ?? this.user, activeSchoolId: clearSchool ? null : (activeSchoolId ?? this.activeSchoolId), ready: ready ?? this.ready);
}

/// Persists the session so a browser refresh / app restart keeps the user signed in.
/// (Web: localStorage via shared_preferences; the token is a revocable, expiring Sanctum token.)
class SessionNotifier extends StateNotifier<Session> {
  SessionNotifier() : super(const Session()) {
    _load();
  }

  static const _k = 'session.v1';

  Future<void> _load() async {
    final p = await SharedPreferences.getInstance();
    final raw = p.getString(_k);
    if (raw != null) {
      try {
        final m = jsonDecode(raw) as Map<String, dynamic>;
        state = Session(token: m['token'], user: Map<String, dynamic>.from(m['user']), activeSchoolId: m['school'], ready: true);
        return;
      } catch (_) {}
    }
    state = state.copy(ready: true);
  }

  Future<void> _save() async {
    final p = await SharedPreferences.getInstance();
    if (state.token == null) {
      await p.remove(_k);
    } else {
      await p.setString(_k, jsonEncode({'token': state.token, 'user': state.user, 'school': state.activeSchoolId}));
    }
  }

  Future<void> signIn(String token, Map<String, dynamic> user) async {
    final ms = ((user['memberships'] as List?) ?? []).map((m) => Membership.fromJson(m as Map)).toList();
    state = Session(token: token, user: user, activeSchoolId: user['platform_role'] == null && ms.length == 1 ? ms.first.schoolId : null, ready: true);
    await _save();
  }

  Future<void> refreshUser(Map<String, dynamic> user) async {
    state = state.copy(user: user);
    await _save();
  }

  Future<void> chooseSchool(int id) async {
    state = state.copy(activeSchoolId: id);
    await _save();
  }

  Future<void> clearSchool() async {
    state = state.copy(clearSchool: true);
    await _save();
  }

  Future<void> signOut() async {
    state = const Session(ready: true);
    await _save();
  }
}

final sessionProvider = StateNotifierProvider<SessionNotifier, Session>((ref) => SessionNotifier());

final apiProvider = Provider<Api>((ref) {
  return Api(
    token: () => ref.read(sessionProvider).token,
    schoolId: () => ref.read(sessionProvider).activeSchoolId,
    onUnauthorized: () => ref.read(sessionProvider.notifier).signOut(),
  );
});
