import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import 'notifications.dart';

/// Parsed `/me/schedule`-style payload.
class ScheduleData {
  ScheduleData(this.raw);
  final Map<String, dynamic> raw;
  bool get hasTimetable => raw['timetable'] != null;
  List<Map<String, dynamic>> get periods => ((raw['periods'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  List<Map<String, dynamic>> get entries => ((raw['entries'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  List<int> get workingDays => ((raw['timetable']?['working_days'] as List?) ?? []).map((e) => e as int).toList();
  Map get _lookup => (raw['lookup'] as Map?) ?? {};
  String subject(int id) => '${(_lookup['subjects'] as Map?)?['$id'] ?? '—'}';
  String section(int id) => '${(_lookup['sections'] as Map?)?['$id'] ?? '—'}';
  String teacher(int id) => '${(_lookup['teachers'] as Map?)?['$id'] ?? '—'}';
}

final myScheduleProvider = FutureProvider.autoDispose<ScheduleData>((ref) async {
  final r = await ref.read(apiProvider).get('/me/schedule');
  return ScheduleData(Map<String, dynamic>.from(r as Map));
});

final childScheduleProvider = FutureProvider.autoDispose.family<ScheduleData, int>((ref, studentId) async {
  final r = await ref.read(apiProvider).get('/students/$studentId/schedule');
  return ScheduleData(Map<String, dynamic>.from(r as Map));
});

final todaySessionsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final r = await ref.read(apiProvider).get('/sessions', query: {'today': 1, 'per_page': 100});
  return ((r['data'] as List)).map((e) => Map<String, dynamic>.from(e as Map)).toList();
});

/// Today's lessons with bell countdown, live status and the join/start button.
/// Time comes from the server clock offset (a wrong device clock never shifts the bell).
class TodayPanel extends ConsumerStatefulWidget {
  const TodayPanel({super.key, required this.teacher});
  final bool teacher;
  @override
  ConsumerState<TodayPanel> createState() => _TodayState();
}

class _TodayState extends ConsumerState<TodayPanel> {
  Timer? _tick;
  Timer? _refresh;
  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) => mounted ? setState(() {}) : null);
    _refresh = Timer.periodic(const Duration(seconds: 45), (_) {
      ref.invalidate(todaySessionsProvider);
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    _refresh?.cancel();
    super.dispose();
  }

  Future<void> _startOrJoin(Map<String, dynamic> entry, Map<String, dynamic>? session) async {
    var sid = session?['id'] as int?;
    if (widget.teacher) {
      if (session == null || session['status'] == 'scheduled') {
        final r = await ref.read(apiProvider).post(session == null ? '/sessions/start-lesson' : '/sessions/$sid/start', data: session == null ? {'timetable_entry_id': entry['id']} : null);
        sid = r['data']['id'] as int;
        ref.invalidate(todaySessionsProvider);
      }
    }
    if (sid != null && mounted) context.push('/live/$sid');
  }

  @override
  Widget build(BuildContext context) {
    final sched = ref.watch(myScheduleProvider);
    final sessions = ref.watch(todaySessionsProvider);
    return Async<ScheduleData>(sched, onRetry: () => ref.invalidate(myScheduleProvider), builder: (s) {
      if (!s.hasTimetable) return const EmptyState('هنوز برنامهٔ هفتگی فعالی تعریف نشده است.', icon: Icons.event_busy_outlined);
      final now = serverNow(ref);
      final today = apiWeekday(now);
      if (!s.workingDays.contains(today)) return const EmptyState('امروز روز کاری مدرسه نیست.', icon: Icons.weekend_outlined);
      final nowMin = now.hour * 60 + now.minute + now.second / 60;
      final periods = s.periods;
      final entries = s.entries.where((e) => e['weekday'] == today).toList();
      final sess = {for (final x in sessions.valueOrNull ?? <Map<String, dynamic>>[]) if (x['timetable_entry_id'] != null) x['timetable_entry_id']: x};
      final extras = (sessions.valueOrNull ?? []).where((x) => x['timetable_entry_id'] == null && x['status'] != 'ended').toList();

      // Next bell: the next period boundary after "now".
      Duration? untilNext;
      String nextLabel = '';
      for (final p in periods) {
        final st = minutesOf(p['starts_at']).toDouble(), en = minutesOf(p['ends_at']).toDouble();
        if (nowMin < st) { untilNext = Duration(seconds: ((st - nowMin) * 60).round()); nextLabel = 'شروع ${p['title']}'; break; }
        if (nowMin < en) { untilNext = Duration(seconds: ((en - nowMin) * 60).round()); nextLabel = 'پایان ${p['title']}'; break; }
      }

      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        AppCard(color: Palette.brandSoft, child: Row(children: [
          const Icon(Icons.notifications_active_outlined, color: Palette.brand, size: 30),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(untilNext == null ? 'برنامهٔ امروز به پایان رسید.' : nextLabel, style: Theme.of(context).textTheme.titleMedium),
            Text(fmtLongDate(now), style: Theme.of(context).textTheme.bodySmall),
          ])),
          if (untilNext != null) Text(fmtDuration(untilNext), key: const Key('bell-countdown'), style: Theme.of(context).textTheme.headlineSmall?.copyWith(color: Palette.brand, fontFeatures: const [FontFeature.tabularFigures()])),
        ])),
        const SizedBox(height: 12),
        for (final p in periods)
          Builder(builder: (_) {
            final st = minutesOf(p['starts_at']), en = minutesOf(p['ends_at']);
            final active = nowMin >= st && nowMin < en;
            final past = nowMin >= en;
            final hhmm = '${faDigits((p['starts_at'] as String).substring(0, 5))} تا ${faDigits((p['ends_at'] as String).substring(0, 5))}';
            if (p['kind'] != 'lesson') {
              return Padding(padding: const EdgeInsets.only(bottom: 8), child: AppCard(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10), color: active ? Palette.warnSoft : null,
                  child: Row(children: [Icon(p['kind'] == 'break' ? Icons.free_breakfast_outlined : Icons.directions_walk, color: Palette.muted, size: 20), const SizedBox(width: 10), Expanded(child: Text(p['title'])), Text(hhmm, style: Theme.of(context).textTheme.bodySmall)])));
            }
            final e = entries.where((x) => x['period_id'] == p['id']).firstOrNull;
            final ss = e == null ? null : sess[e['id']];
            final live = ss?['status'] == 'live';
            return Padding(padding: const EdgeInsets.only(bottom: 8), child: AppCard(
              color: active ? Colors.white : null,
              child: Row(children: [
                Container(width: 4, height: 48, decoration: BoxDecoration(color: live ? Palette.success : (active ? Palette.brand : (past ? Palette.border : Palette.brandMid)), borderRadius: BorderRadius.circular(4))),
                const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(e == null ? '${p['title']} — بدون کلاس' : s.subject(e['subject_id']), style: Theme.of(context).textTheme.titleMedium?.copyWith(color: past ? Palette.muted : null)),
                  Text(e == null ? hhmm : '$hhmm  ·  ${widget.teacher ? s.section(e['section_id']) : s.teacher(e['teacher_id'])}', style: Theme.of(context).textTheme.bodySmall),
                ])),
                if (ss != null) StatusChip(statusName(ss['status'] as String), tone: live ? Tone.success : (ss['status'] == 'not_held' ? Tone.danger : Tone.neutral)),
                const SizedBox(width: 8),
                if (e != null && (live || (widget.teacher && !past && ss?['status'] != 'ended') || (!widget.teacher && ss != null && ss['status'] == 'live')))
                  FilledButton(key: Key('join-${e['id']}'), onPressed: () => _startOrJoin(e, ss), child: Text(widget.teacher ? (live ? 'ورود به کلاس' : 'شروع کلاس') : 'ورود به کلاس')),
              ]),
            ));
          }),
        if (extras.isNotEmpty) ...[
          const SectionTitle('کلاس جبرانی / جایگزین امروز'),
          for (final x in extras) AppCard(onTap: () => context.push('/live/${x['id']}'), child: Row(children: [const Icon(Icons.event_repeat, color: Palette.brand), const SizedBox(width: 10), Expanded(child: Text('${x['title']}')), Text(fmtDate(x['scheduled_start'], withTime: true)), const SizedBox(width: 8), StatusChip(statusName(x['status'] as String), tone: x['status'] == 'live' ? Tone.success : Tone.info)])),
        ],
      ]);
    });
  }
}

/// Weekly grid: rows = periods, columns = working days.
class WeeklyGrid extends StatelessWidget {
  const WeeklyGrid({super.key, required this.data, this.showSection = false, this.onTapEntry});
  final ScheduleData data;
  final bool showSection;
  final void Function(Map<String, dynamic> entry)? onTapEntry;
  @override
  Widget build(BuildContext context) {
    if (!data.hasTimetable) return const EmptyState('برنامهٔ هفتگی فعالی وجود ندارد.', icon: Icons.event_busy_outlined);
    final days = data.workingDays..sort();
    final periods = data.periods;
    // Phones: a grid of 7 columns does not fit, so show one card per day with its lessons in order.
    if (MediaQuery.sizeOf(context).width < 600) return _agenda(context, days, periods);
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Table(
        defaultColumnWidth: const FixedColumnWidth(128),
        columnWidths: const {0: FixedColumnWidth(96)},
        border: TableBorder.all(color: Palette.border, borderRadius: BorderRadius.circular(10)),
        children: [
          TableRow(decoration: const BoxDecoration(color: Palette.brandSoft), children: [const SizedBox(height: 40), for (final d in days) Center(child: Text(weekdayNames[d], style: const TextStyle(fontWeight: FontWeight.w700)))]),
          for (final p in periods)
            TableRow(decoration: BoxDecoration(color: p['kind'] == 'lesson' ? null : const Color(0xFFF7F9FC)), children: [
              Padding(padding: const EdgeInsets.all(8), child: Column(children: [Text('${p['title']}', style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)), Text(faDigits((p['starts_at'] as String).substring(0, 5)), style: Theme.of(context).textTheme.bodySmall)])),
              for (final d in days)
                Builder(builder: (_) {
                  if (p['kind'] != 'lesson') return const SizedBox(height: 44, child: Center(child: Text('—', style: TextStyle(color: Palette.border))));
                  final es = data.entries.where((e) => e['weekday'] == d && e['period_id'] == p['id']).toList();
                  if (es.isEmpty) return const SizedBox(height: 60);
                  return Column(children: [
                    for (final e in es)
                      InkWell(
                        onTap: onTapEntry == null ? null : () => onTapEntry!(e),
                        child: Container(width: double.infinity, margin: const EdgeInsets.all(3), padding: const EdgeInsets.all(6), decoration: BoxDecoration(color: Palette.brandSoft, borderRadius: BorderRadius.circular(8)),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(data.subject(e['subject_id']), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                              Text(showSection ? data.section(e['section_id']) : data.teacher(e['teacher_id']), style: Theme.of(context).textTheme.bodySmall, overflow: TextOverflow.ellipsis),
                            ])),
                      ),
                  ]);
                }),
            ]),
        ],
      ),
    );
  }

  Widget _agenda(BuildContext context, List<int> days, List<Map<String, dynamic>> periods) {
    final lessons = periods.where((p) => p['kind'] == 'lesson').toList();
    return Column(children: [
      for (final d in days)
        Builder(builder: (_) {
          final rows = <Widget>[];
          for (final p in lessons) {
            for (final e in data.entries.where((e) => e['weekday'] == d && e['period_id'] == p['id'])) {
              rows.add(InkWell(
                onTap: onTapEntry == null ? null : () => onTapEntry!(e),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Row(children: [
                    SizedBox(width: 64, child: Text(faDigits((p['starts_at'] as String).substring(0, 5)), style: Theme.of(context).textTheme.bodySmall)),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(data.subject(e['subject_id']), style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text('${p['title']} · ${showSection ? data.section(e['section_id']) : data.teacher(e['teacher_id'])}', style: Theme.of(context).textTheme.bodySmall),
                    ])),
                  ]),
                ),
              ));
            }
          }
          return Padding(padding: const EdgeInsets.only(bottom: 10), child: AppCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(weekdayNames[d], style: Theme.of(context).textTheme.titleMedium),
              const Divider(height: 16),
              if (rows.isEmpty) Text('کلاسی ندارید.', style: Theme.of(context).textTheme.bodySmall) else ...rows,
            ]),
          ));
        }),
    ]);
  }
}
