import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/format.dart';
import '../../core/realtime.dart';
import '../../core/session.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import 'bell.dart';

/// Notification feed with incremental sync: after any disconnect the client asks only for what it has not seen.
class FeedState {
  const FeedState({this.items = const [], this.lastId = 0, this.error, this.loaded = false, this.serverOffset = Duration.zero});
  final List<Map<String, dynamic>> items;
  final int lastId;
  final Object? error;
  final bool loaded;
  final Duration serverOffset; // serverTime - deviceTime
  int get unread => items.where((e) => e['read_at'] == null).length;
}

class FeedController extends StateNotifier<FeedState> {
  FeedController(this.ref) : super(const FeedState()) {
    _start();
  }
  final Ref ref;
  Timer? _poll;
  void Function()? _unsub;
  final _bellSeen = <int>{};

  Future<void> _start() async {
    await sync(initial: true);
    final s = ref.read(sessionProvider);
    if (s.active != null) {
      _unsub = ref.read(realtimeProvider).subscribe(userChannel(s.activeSchoolId!, s.userId), (e, d) {
        if (e == 'notification') sync();
      });
    }
    // Poll as a safety net (realtime may be unavailable or the tab may have been suspended).
    _poll = Timer.periodic(const Duration(seconds: 30), (_) => sync());
  }

  Future<void> sync({bool initial = false}) async {
    try {
      final r = await ref.read(apiProvider).get('/me/notifications', query: {'since_id': initial ? null : state.lastId});
      final fresh = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      final server = DateTime.tryParse('${r['server_time']}');
      final off = server == null ? state.serverOffset : server.difference(DateTime.now());
      if (fresh.isEmpty && state.loaded) {
        state = FeedState(items: state.items, lastId: state.lastId, loaded: true, serverOffset: off);
        return;
      }
      final merged = [...fresh.reversed, ...state.items];
      final lastId = fresh.isEmpty ? state.lastId : fresh.map((e) => e['id'] as int).reduce((a, b) => a > b ? a : b);
      state = FeedState(items: merged, lastId: lastId, loaded: true, serverOffset: off);
      // Ring the bell for NEW bell notifications only (not the backlog loaded at startup).
      if (!initial) {
        final hasBell = fresh.any((e) => (e['type'] as String).startsWith('bell.lesson_start') || e['type'] == 'bell.break_start' || e['type'] == 'session.live');
        final newOnes = fresh.where((e) => _bellSeen.add(e['id'] as int)).isNotEmpty;
        if (hasBell && newOnes) ref.read(bellProvider.notifier).ring();
      } else {
        for (final e in fresh) { _bellSeen.add(e['id'] as int); }
      }
    } catch (e) {
      state = FeedState(items: state.items, lastId: state.lastId, error: e, loaded: true, serverOffset: state.serverOffset);
    }
  }

  Future<void> markRead(int id) async {
    state = FeedState(items: [for (final e in state.items) e['id'] == id ? {...e, 'read_at': DateTime.now().toIso8601String()} : e], lastId: state.lastId, loaded: true, serverOffset: state.serverOffset);
    try { await ref.read(apiProvider).post('/me/notifications/$id/read'); } catch (_) {}
  }

  @override
  void dispose() {
    _poll?.cancel();
    _unsub?.call();
    super.dispose();
  }
}

final feedProvider = StateNotifierProvider.autoDispose<FeedController, FeedState>((ref) {
  ref.watch(sessionProvider.select((s) => '${s.token}${s.activeSchoolId}'));
  ref.keepAlive();
  return FeedController(ref);
});

final unreadCountProvider = Provider.autoDispose<AsyncValue<int>>((ref) {
  final f = ref.watch(feedProvider);
  return AsyncValue.data(f.unread);
});

/// Estimated server "now" (corrects wrong device clocks; the bell and countdowns follow the server).
DateTime serverNow(WidgetRef ref) => DateTime.now().add(ref.read(feedProvider).serverOffset);

IconData _icon(String t) {
  if (t.startsWith('bell.')) return Icons.notifications_active_outlined;
  if (t.startsWith('assignment') || t.startsWith('submission')) return Icons.assignment_outlined;
  if (t.startsWith('exam')) return Icons.quiz_outlined;
  if (t.startsWith('attendance')) return Icons.how_to_reg_outlined;
  if (t.startsWith('schedule') || t.startsWith('session')) return Icons.event_outlined;
  if (t.startsWith('message')) return Icons.forum_outlined;
  if (t.startsWith('reportcard')) return Icons.workspace_premium_outlined;
  return Icons.campaign_outlined;
}

String? _target(Panel? p, Map<String, dynamic> n) {
  final t = n['type'] as String;
  final d = (n['data'] as Map?) ?? {};
  final base = switch (p) { Panel.student => '/student', Panel.teacher => '/teacher', Panel.guardian => '/guardian', Panel.admin => '/admin', _ => null };
  if (base == null) return null;
  if (t == 'session.live' && d['session_id'] != null && p == Panel.student) return '/live/${d['session_id']}';
  if (t.startsWith('bell.lesson') && d['session_id'] != null && (p == Panel.student || p == Panel.teacher)) return '/live/${d['session_id']}';
  if (t.startsWith('assignment') || t.startsWith('submission')) return p == Panel.admin ? '/admin/learning' : '$base/assignments';
  if (t.startsWith('exam')) return p == Panel.admin ? '/admin/learning' : '$base/exams';
  if (t.startsWith('message')) return '$base/messages';
  if (t.startsWith('reportcard')) return p == Panel.admin ? '/admin/reports' : '$base/grades';
  if (t.startsWith('attendance')) return p == Panel.guardian ? '/guardian/attendance' : null;
  if (t.startsWith('schedule')) return p == Panel.guardian ? '/guardian/schedule' : base;
  return null;
}

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(feedProvider);
    final panel = ref.watch(sessionProvider).panel;
    final bell = ref.watch(bellProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('اعلان‌ها'), leading: BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/'))),
      body: PageBody(maxWidth: 760, onRefresh: () => ref.read(feedProvider.notifier).sync(), children: [
        if (bell.blocked) AppCard(color: Palette.warnSoft, child: Row(children: [const Icon(Icons.volume_off, color: Palette.warn), const SizedBox(width: 10), const Expanded(child: Text('مرورگر پخش صدای زنگ را قبل از کلیک شما مسدود کرده است.')), TextButton(onPressed: () => ref.read(bellProvider.notifier).ring(force: true), child: const Text('فعال‌سازی صدا'))])),
        if (!f.loaded) const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()))
        else if (f.items.isEmpty) const EmptyState('اعلانی ندارید.', icon: Icons.notifications_none)
        else ...[
          for (final n in f.items)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: AppCard(
                color: n['read_at'] == null ? Palette.brandSoft : null,
                onTap: () {
                  ref.read(feedProvider.notifier).markRead(n['id'] as int);
                  final t = _target(panel, n);
                  if (t != null) context.go(t);
                },
                child: Row(children: [
                  Icon(_icon(n['type'] as String), color: Palette.brand),
                  const SizedBox(width: 12),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${n['title']}', style: TextStyle(fontWeight: n['read_at'] == null ? FontWeight.w700 : FontWeight.w400)),
                    if (n['body'] != null) Text('${n['body']}', style: Theme.of(context).textTheme.bodySmall),
                    Text(fmtDate(n['created_at'], withTime: true), style: Theme.of(context).textTheme.bodySmall),
                  ])),
                ]),
              ),
            ),
        ],
      ]),
    );
  }
}
