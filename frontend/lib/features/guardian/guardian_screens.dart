import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/forms.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import '../common/schedule_widgets.dart';
import '../student/assignments.dart';
import '../student/student_screens.dart';

final childrenProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final r = await ref.read(apiProvider).get('/me/children');
  return [for (final c in r['data'] as List) Map<String, dynamic>.from(c as Map)];
});

final selectedChildProvider = StateProvider<int?>((ref) => null);

/// Wraps a per-child page with a child switcher. Only approved children are ever returned by the API.
class ChildScope extends ConsumerWidget {
  const ChildScope({super.key, required this.builder});
  final Widget Function(BuildContext, Map<String, dynamic> child) builder;
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final kids = ref.watch(childrenProvider);
    return Async<List<Map<String, dynamic>>>(kids, onRetry: () => ref.invalidate(childrenProvider), builder: (list) {
      if (list.isEmpty) return const EmptyState('هنوز فرزندی به حساب شما متصل نشده است. برای اتصال با مدرسه تماس بگیرید.', icon: Icons.family_restroom_outlined);
      final sel = ref.watch(selectedChildProvider);
      final child = list.firstWhere((c) => c['id'] == sel, orElse: () => list.first);
      return Column(children: [
        if (list.length > 1) Container(color: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8), child: SingleChildScrollView(scrollDirection: Axis.horizontal, child: Row(children: [for (final c in list) Padding(padding: const EdgeInsets.only(left: 8), child: ChoiceChip(label: Text('${c['first_name']} ${c['last_name']}'), selected: c['id'] == child['id'], onSelected: (_) => ref.read(selectedChildProvider.notifier).state = c['id'] as int))]))),
        Expanded(child: KeyedSubtree(key: ValueKey(child['id']), child: builder(context, child))),
      ]);
    });
  }
}

class GuardianHome extends ConsumerWidget {
  const GuardianHome({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => PageBody(onRefresh: () async => ref.invalidate(childrenProvider), children: [
        PageHeader('سلام ${ref.watch(sessionProvider).name}', subtitle: 'فرزندان شما'),
        Async<List<Map<String, dynamic>>>(ref.watch(childrenProvider), builder: (list) => list.isEmpty
            ? const EmptyState('هنوز فرزندی به حساب شما متصل نشده است. پس از تأیید مدرسه در این‌جا نمایش داده می‌شود.', icon: Icons.family_restroom_outlined)
            : Wrap(spacing: 12, runSpacing: 12, children: [for (final c in list) SizedBox(width: 340, child: AppCard(onTap: () { ref.read(selectedChildProvider.notifier).state = c['id'] as int; context.go('/guardian/grades'); }, child: Row(children: [
                const CircleAvatar(backgroundColor: Palette.brandSoft, child: Icon(Icons.child_care, color: Palette.brand)), const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${c['first_name']} ${c['last_name']}', style: Theme.of(context).textTheme.titleMedium), Text('${c['section'] ?? 'بدون کلاس'} · کد ${faDigits(c['student_code'])}', style: Theme.of(context).textTheme.bodySmall)])),
              ])))])),
        const SectionTitle('اطلاعیه‌های مدرسه'),
        PagedList(path: '/announcements', emptyText: 'اطلاعیه‌ای نیست.', itemBuilder: (c, a, st) => AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${a['title']}', style: Theme.of(c).textTheme.titleMedium), const SizedBox(height: 4), Text('${a['body']}'), const SizedBox(height: 4), Text(fmtDate(a['published_at']), style: Theme.of(c).textTheme.bodySmall)]))),
      ]);
}

class GuardianSchedule extends ConsumerWidget {
  const GuardianSchedule({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ChildScope(builder: (c, child) => PageBody(children: [
        PageHeader('برنامهٔ ${child['first_name']}'),
        Async<ScheduleData>(ref.watch(childScheduleProvider(child['id'] as int)), builder: (d) => WeeklyGrid(data: d)),
      ]));
}

class GuardianAttendance extends ConsumerWidget {
  const GuardianAttendance({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ChildScope(builder: (c, child) => PageBody(children: [
        PageHeader('حضور و غیاب ${child['first_name']}'),
        FutureBuilder(future: ref.read(apiProvider).get('/attendance/students/${child['id']}/summary'), builder: (c, s) {
          if (!s.hasData) return const LinearProgressIndicator();
          final d = (s.data as Map)['data'] as Map;
          return Row(children: [
            Expanded(child: StatCard(label: 'حاضر', value: faDigits(d['present']), icon: Icons.check_circle_outline, tone: Tone.success)), const SizedBox(width: 8),
            Expanded(child: StatCard(label: 'تأخیر', value: faDigits(d['late']), icon: Icons.schedule, tone: Tone.warn)), const SizedBox(width: 8),
            Expanded(child: StatCard(label: 'غیبت', value: faDigits(d['absent']), icon: Icons.cancel_outlined, tone: Tone.danger)),
          ]);
        }),
        const SectionTitle('سوابق'),
        PagedList(path: '/attendance', query: {'student_id': child['id']}, emptyText: 'سابقه‌ای نیست.', itemBuilder: (c, a, st) => AppCard(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10), child: Row(children: [Expanded(child: Text(fmtDate(a['on_date']))), if ((a['minutes_late'] ?? 0) > 0) Text('${faDigits(a['minutes_late'])} دقیقه  '), StatusChip(tr('att.${a['status']}'), tone: a['status'] == 'present' ? Tone.success : (a['status'] == 'absent' ? Tone.danger : Tone.warn))]))),
      ]));
}

class GuardianGrades extends StatelessWidget {
  const GuardianGrades({super.key});
  @override
  Widget build(BuildContext context) => ChildScope(builder: (c, child) => GradesScreen(studentId: child['id'] as int));
}

class GuardianAssignments extends StatelessWidget {
  const GuardianAssignments({super.key});
  @override
  Widget build(BuildContext context) => ChildScope(builder: (c, child) => AssignmentsList(studentId: child['id'] as int));
}

class GuardianMeetings extends ConsumerStatefulWidget {
  const GuardianMeetings({super.key});
  @override
  ConsumerState<GuardianMeetings> createState() => _MeetingsState();
}

class _MeetingsState extends ConsumerState<GuardianMeetings> {
  final _key = GlobalKey<PagedListState>();
  @override
  Widget build(BuildContext context) => PageBody(children: [
        PageHeader('درخواست جلسه و پیگیری', actions: [FilledButton.icon(key: const Key('new-meeting'), onPressed: () async {
          final kids = await ref.read(childrenProvider.future);
          final ok = await showForm(context, title: 'درخواست جلسه', submitLabel: 'ارسال', fields: [
            FieldSpec('student_id', 'فرزند', type: FieldType.dropdown, required: true, options: [for (final c in kids) Option(c['id'] as int, '${c['first_name']} ${c['last_name']}')], initial: kids.length == 1 ? kids.first['id'] : null),
            const FieldSpec('teacher_id', 'با چه کسی؟ (خالی = مدیریت مدرسه)', type: FieldType.dropdown, optionsFrom: '/me/child-teachers', labelKey: 'label'),
            const FieldSpec('topic', 'موضوع', required: true), const FieldSpec('details', 'توضیح', type: FieldType.multiline), const FieldSpec('preferred_at', 'زمان پیشنهادی', type: FieldType.datetime),
          ], submit: (v) async => ref.read(apiProvider).post('/meetings', data: v));
          if (ok) _key.currentState?.reload();
        }, icon: const Icon(Icons.add), label: const Text('درخواست جدید'))]),
        PagedList(key: _key, path: '/meetings', emptyText: 'درخواستی ثبت نکرده‌اید.', itemBuilder: (c, m, st) => AppCard(child: Row(children: [
              const Icon(Icons.event_available_outlined, color: Palette.brand), const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${m['topic']}', style: Theme.of(c).textTheme.titleMedium), if (m['scheduled_at'] != null) Text('زمان جلسه: ${fmtDate(m['scheduled_at'], withTime: true)}'), if (m['response_note'] != null) Text('${m['response_note']}', style: Theme.of(c).textTheme.bodySmall)])),
              StatusChip(switch (m['status']) { 'accepted' => 'تأیید شد', 'declined' => 'رد شد', 'done' => 'انجام شد', _ => 'در انتظار' }, tone: m['status'] == 'accepted' ? Tone.success : (m['status'] == 'declined' ? Tone.danger : Tone.neutral)),
            ]))),
      ]);
}
