import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../live/recordings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/forms.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import '../common/messages_screen.dart';
import '../teacher/teacher_assignments.dart';
import '../teacher/teacher_exams.dart';
import '../teacher/teacher_grades.dart';

final _rulesProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async => Map<String, dynamic>.from(await ref.read(apiProvider).get('/grading/rules')));
final _overviewProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async => Map<String, dynamic>.from(await ref.read(apiProvider).get('/analytics/overview')));
final _attentionProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async => [for (final x in (await ref.read(apiProvider).get('/analytics/attention'))['data'] as List) Map<String, dynamic>.from(x as Map)]);
final _teachersProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async => [for (final x in (await ref.read(apiProvider).get('/analytics/teachers'))['data'] as List) Map<String, dynamic>.from(x as Map)]);
final _liveReportProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async => Map<String, dynamic>.from(await ref.read(apiProvider).get('/sessions/report')));
final _schoolProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async => Map<String, dynamic>.from(await ref.read(apiProvider).get('/school/profile')));

class AdminDashboard extends ConsumerWidget {
  const AdminDashboard({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final o = ref.watch(_overviewProvider);
    final school = ref.watch(_schoolProvider);
    return PageBody(maxWidth: 1200, onRefresh: () async { ref.invalidate(_overviewProvider); ref.invalidate(_attentionProvider); ref.invalidate(_teachersProvider); ref.invalidate(_liveReportProvider); }, children: [
      PageHeader('داشبورد مدیریت', subtitle: ref.watch(sessionProvider).active?.schoolName),
      Async<Map<String, dynamic>>(school, builder: (s) {
        final sub = s['data']['subscription'] as Map?;
        return sub == null ? const SizedBox.shrink() : Padding(padding: const EdgeInsets.only(bottom: 12), child: AppCard(color: Palette.brandSoft, child: Row(children: [const Icon(Icons.workspace_premium_outlined, color: Palette.brand), const SizedBox(width: 10), Expanded(child: Text('طرح ${sub['plan']}: حداکثر ${faDigits(sub['max_students'])} دانش‌آموز، ${faDigits(sub['max_teachers'])} معلم، ${faDigits(sub['max_live_sessions'])} کلاس هم‌زمان، ${faDigits(sub['max_storage_mb'])} مگابایت فضا'))])));
      }),
      Async<Map<String, dynamic>>(o, onRetry: () => ref.invalidate(_overviewProvider), builder: (d) {
        final c = d['counts'] as Map, a = d['attendance'] as Map, s = d['sessions'] as Map, asg = d['assignments'] as Map, ex = d['exams'] as Map;
        return Wrap(spacing: 12, runSpacing: 12, children: [
          for (final t in [
            ('دانش‌آموزان فعال', faDigits(c['students']), Icons.groups, Tone.info), ('معلمان', faDigits(c['teachers']), Icons.school, Tone.info), ('کلاس‌ها', faDigits(c['sections']), Icons.meeting_room_outlined, Tone.info),
            ('حضور (۳۰ روز)', '${fmtNum(a['present_rate'])}٪', Icons.how_to_reg, Tone.success), ('غیبت (۳۰ روز)', '${fmtNum(a['absent_rate'])}٪', Icons.person_off_outlined, Tone.danger),
            ('نرخ برگزاری کلاس‌ها', s['held_rate'] == null ? '—' : '${fmtNum(s['held_rate'])}٪', Icons.videocam_outlined, Tone.success), ('نرخ تحویل تکلیف', asg['submission_rate'] == null ? '—' : '${fmtNum(asg['submission_rate'])}٪', Icons.assignment_turned_in_outlined, Tone.info),
            ('آزمون در انتظار تصحیح', faDigits(ex['pending_manual']), Icons.rate_review_outlined, Tone.warn),
          ]) SizedBox(width: 260, child: StatCard(label: t.$1, value: t.$2, icon: t.$3, tone: t.$4)),
        ]);
      }),
      const SectionTitle('دانش‌آموزان نیازمند توجه'),
      Async<List<Map<String, dynamic>>>(ref.watch(_attentionProvider), builder: (l) => l.isEmpty ? const AppCard(child: Text('مورد نگران‌کننده‌ای نیست.')) : Column(children: [for (final x in l) Padding(padding: const EdgeInsets.only(bottom: 8), child: AppCard(child: Row(children: [const Icon(Icons.priority_high, color: Palette.warn), const SizedBox(width: 10), Expanded(child: Text('${x['student']?['first_name']} ${x['student']?['last_name']}')), Text('غیبت ۱۴ روز: ${faDigits(x['absences_14d'])}  ·  تکلیف دیرکرد: ${faDigits(x['late_submissions_30d'])}', style: Theme.of(context).textTheme.bodySmall)])))])),
      const SectionTitle('فعالیت معلمان (۳۰ روز)'),
      Async<List<Map<String, dynamic>>>(ref.watch(_teachersProvider), builder: (l) => l.isEmpty ? const EmptyState('معلمی ثبت نشده است.') : Column(children: [for (final t in l) Padding(padding: const EdgeInsets.only(bottom: 8), child: AppCard(child: Row(children: [Expanded(child: Text('${t['name']}')), Wrap(spacing: 14, children: [Text('کلاس برگزارشده: ${faDigits(t['sessions_held'])}/${faDigits(t['sessions_total'])}'), Text('برگزارنشده: ${faDigits(t['not_held'])}'), Text('تکلیف: ${faDigits(t['assignments_created'])}'), Text('در انتظار تصحیح: ${faDigits(t['awaiting_grading'])}')])])))])),
    ]);
  }
}

/// Live monitor: classes happening now, today's sessions, technical issues, and observer join.
class AdminLive extends ConsumerWidget {
  const AdminLive({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => PageBody(onRefresh: () async => ref.invalidate(_liveReportProvider), children: [
        const PageHeader('نظارت بر کلاس‌های آنلاین'),
        Async<Map<String, dynamic>>(ref.watch(_liveReportProvider), builder: (r) => Wrap(spacing: 12, runSpacing: 12, children: [
              SizedBox(width: 220, child: StatCard(label: 'در حال برگزاری', value: faDigits(r['live_now']), icon: Icons.videocam, tone: Tone.success)),
              for (final e in (r['by_status'] as Map).entries) SizedBox(width: 220, child: StatCard(label: '${statusName(e.key as String)} (۷ روز)', value: faDigits(e.value), icon: Icons.event_note, tone: e.key == 'not_held' ? Tone.danger : Tone.info)),
              for (final e in (r['issues'] as Map).entries) SizedBox(width: 220, child: StatCard(label: 'مشکل فنی: ${e.key}', value: faDigits(e.value), icon: Icons.report_problem_outlined, tone: Tone.warn)),
            ])),
        const SectionTitle('جلسه‌های امروز'),
        PagedList(path: '/sessions', query: const {'today': 1}, emptyText: 'جلسه‌ای امروز نیست.', itemBuilder: (c, s, st) => AppCard(onTap: s['status'] == 'live' ? () => c.push('/live/${s['id']}') : null, child: Row(children: [
              Icon(s['status'] == 'live' ? Icons.videocam : Icons.videocam_outlined, color: s['status'] == 'live' ? Palette.success : Palette.muted), const SizedBox(width: 12),
              Expanded(child: Text('${s['title']}  ·  ${fmtDate(s['scheduled_start'], withTime: true)}')), StatusChip(statusName(s['status'] as String), tone: s['status'] == 'live' ? Tone.success : (s['status'] == 'not_held' ? Tone.danger : Tone.neutral)),
              if (s['status'] == 'live') const Padding(padding: EdgeInsets.only(right: 8), child: Text('مشاهده')),
              IconButton(tooltip: 'ضبط‌ها', icon: const Icon(Icons.video_library_outlined), onPressed: () => showRecordings(c, ref, s['id'] as int)),
            ]))),
      ]);
}

class AdminAttendance extends ConsumerStatefulWidget {
  const AdminAttendance({super.key});
  @override
  ConsumerState<AdminAttendance> createState() => _AdminAttendanceState();
}

class _AdminAttendanceState extends ConsumerState<AdminAttendance> {
  Map<String, dynamic> _q = {};
  @override
  Widget build(BuildContext context) => PageBody(children: [
        const PageHeader('حضور و غیاب'),
        Wrap(spacing: 8, runSpacing: 8, children: [for (final s in const ['present', 'late', 'absent', 'excused']) FilterChip(label: Text(tr('att.$s')), selected: _q['status'] == s, onSelected: (on) => setState(() => _q = {..._q, 'status': on ? s : null}))]),
        const SizedBox(height: 12),
        PagedList(path: '/attendance', query: _q, emptyText: 'سابقه‌ای نیست.', itemBuilder: (c, a, st) => AppCard(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10), child: Row(children: [
              Expanded(child: Text('دانش‌آموز ${faDigits(a['student_id'])} · کلاس ${faDigits(a['section_id'])}')), Text(fmtDate(a['on_date'])), const SizedBox(width: 10),
              if (a['source'] == 'teacher') const Padding(padding: EdgeInsets.only(left: 6), child: Icon(Icons.verified_outlined, size: 16, color: Palette.muted)),
              StatusChip(tr('att.${a['status']}'), tone: a['status'] == 'present' ? Tone.success : (a['status'] == 'absent' ? Tone.danger : Tone.warn)),
            ]))),
      ]);
}

class AdminLearning extends StatelessWidget {
  const AdminLearning({super.key});
  @override
  Widget build(BuildContext context) => DefaultTabController(length: 2, child: Column(children: [
        const Material(color: Palette.surface, child: TabBar(tabs: [Tab(text: 'تکالیف'), Tab(text: 'آزمون‌ها')])),
        const Expanded(child: TabBarView(children: [TeacherAssignments(adminView: true), TeacherExams(adminView: true)])),
      ]));
}

/// Grade approval + grading formula (weights, rounding, pass mark, approval policy).
class AdminGrades extends ConsumerStatefulWidget {
  const AdminGrades({super.key});
  @override
  ConsumerState<AdminGrades> createState() => _AdminGradesState();
}

class _AdminGradesState extends ConsumerState<AdminGrades> {
  @override
  Widget build(BuildContext context) => DefaultTabController(length: 2, child: Column(children: [
        const Material(color: Palette.surface, child: TabBar(tabs: [Tab(text: 'تأیید نمرات'), Tab(text: 'فرمول معدل و قواعد')])),
        Expanded(child: TabBarView(children: [const GradeBook(approver: true), _rules()])),
      ]));

  Widget _rules() => Consumer(builder: (context, ref, _) {
        return Async<Map<String, dynamic>>(ref.watch(_rulesProvider), builder: (r) {
          final rules = Map<String, dynamic>.from(r['data'] as Map);
          final w = Map<String, dynamic>.from(rules['kind_weights'] as Map);
          return PageBody(children: [
            const PageHeader('قواعد محاسبهٔ معدل'),
            AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('نمرهٔ درس = میانگین وزنی نمرات تأییدشده با ضریب نوع ارزیابی؛ معدل = میانگین نمرهٔ درس‌ها با ضریب هر درس.', style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 10),
              LabeledRow('مقیاس نمره', Text(fmtNum(rules['scale_max']))), LabeledRow('حد قبولی', Text(fmtNum(rules['pass_mark']))), LabeledRow('رقم اعشار', Text(fmtNum(rules['decimals']))), LabeledRow('گرد کردن', Text('${rules['rounding']}')),
              LabeledRow('ضرایب ارزیابی', Wrap(spacing: 10, children: [for (final e in w.entries) Text('${tr('kind.${e.key}')}: ${fmtNum(e.value)}')])),
              LabeledRow('تأیید نمرات الزامی', Text(r['approval_required'] == true ? 'بله' : 'خیر')),
              const SizedBox(height: 10),
              FilledButton.icon(key: const Key('edit-rules'), onPressed: () async {
                final ok = await showForm(context, title: 'ویرایش قواعد', initial: {'scale_max': rules['scale_max'], 'pass_mark': rules['pass_mark'], 'decimals': rules['decimals'], 'rounding': rules['rounding'], 'w_exam': w['exam'], 'w_assignment': w['assignment'], 'w_classwork': w['classwork'], 'w_oral': w['oral'], 'w_project': w['project'], 'w_final': w['final'], 'max_makeup_subjects': rules['max_makeup_subjects'], 'min_subject_mark': rules['min_subject_mark'], 'approval_required': r['approval_required']}, fields: const [
                  FieldSpec('scale_max', 'مقیاس (مثلاً ۲۰)', type: FieldType.number), FieldSpec('pass_mark', 'حد قبولی', type: FieldType.number), FieldSpec('decimals', 'رقم اعشار', type: FieldType.number),
                  FieldSpec('rounding', 'گرد کردن', type: FieldType.dropdown, options: [Option('half_up', 'گرد معمولی'), Option('floor', 'به پایین'), Option('ceil', 'به بالا')]),
                  FieldSpec('w_exam', 'ضریب آزمون', type: FieldType.number), FieldSpec('w_assignment', 'ضریب تکلیف', type: FieldType.number), FieldSpec('w_classwork', 'ضریب فعالیت کلاسی', type: FieldType.number), FieldSpec('w_oral', 'ضریب شفاهی', type: FieldType.number), FieldSpec('w_project', 'ضریب پروژه', type: FieldType.number), FieldSpec('w_final', 'ضریب پایانی', type: FieldType.number),
                  FieldSpec('min_subject_mark', 'حداقل نمرهٔ هر درس (برای جبرانی/مردودی)', type: FieldType.number), FieldSpec('max_makeup_subjects', 'حداکثر درس جبرانی', type: FieldType.number), FieldSpec('approval_required', 'نمرات پیش از نمایش نیازمند تأیید باشند', type: FieldType.toggle),
                ], submit: (v) async => ref.read(apiProvider).put('/grading/rules', data: {
                      'rules': {'scale_max': v['scale_max'], 'pass_mark': v['pass_mark'], 'decimals': v['decimals'], 'rounding': v['rounding'], 'min_subject_mark': v['min_subject_mark'], 'max_makeup_subjects': v['max_makeup_subjects'],
                        'kind_weights': {'exam': v['w_exam'], 'assignment': v['w_assignment'], 'classwork': v['w_classwork'], 'oral': v['w_oral'], 'project': v['w_project'], 'final': v['w_final']}..removeWhere((k, x) => x == null)}..removeWhere((k, x) => x == null),
                      'approval_required': v['approval_required']}));
                if (ok) ref.invalidate(_rulesProvider);
              }, icon: const Icon(Icons.tune), label: const Text('ویرایش قواعد')),
            ])),
          ]);
        });
      });
}

/// Report cards: templates, generate for a class/term, issue, revoke, view.
class AdminReports extends ConsumerStatefulWidget {
  const AdminReports({super.key});
  @override
  ConsumerState<AdminReports> createState() => _AdminReportsState();
}

class _AdminReportsState extends ConsumerState<AdminReports> {
  final _key = GlobalKey<PagedListState>();
  Map<String, dynamic> _q = {};

  @override
  Widget build(BuildContext context) => PageBody(children: [
        PageHeader('کارنامه‌ها', actions: [
          OutlinedButton.icon(onPressed: _templates, icon: const Icon(Icons.design_services_outlined), label: const Text('قالب کارنامه')),
          FilledButton.icon(key: const Key('generate-reports'), onPressed: _generate, icon: const Icon(Icons.auto_fix_high), label: const Text('تولید برای یک کلاس')),
          FilledButton.tonalIcon(key: const Key('issue-reports'), onPressed: _issue, icon: const Icon(Icons.send_outlined), label: const Text('صدور گروهی')),
        ]),
        Wrap(spacing: 8, children: [for (final s in const [('draft', 'پیش‌نویس'), ('issued', 'صادرشده'), ('revoked', 'ابطال‌شده')]) FilterChip(label: Text(s.$2), selected: _q['status'] == s.$1, onSelected: (on) => setState(() => _q = {..._q, 'status': on ? s.$1 : null}))]),
        const SizedBox(height: 12),
        PagedList(key: _key, path: '/report-cards', query: _q, emptyText: 'کارنامه‌ای ساخته نشده است.', itemBuilder: (c, r, st) => AppCard(child: Row(children: [
              const Icon(Icons.workspace_premium_outlined, color: Palette.brand), const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('دانش‌آموز ${faDigits(r['student_id'])} — معدل ${fmtNum(r['average'])}', style: Theme.of(c).textTheme.titleMedium), Text(r['result'] == null ? 'بدون نتیجه' : tr('result.${r['result']}'), style: Theme.of(c).textTheme.bodySmall)])),
              StatusChip(statusName(r['status'] as String), tone: r['status'] == 'issued' ? Tone.success : (r['status'] == 'revoked' ? Tone.danger : Tone.neutral)),
              IconButton(tooltip: 'مشاهده PDF', icon: const Icon(Icons.picture_as_pdf_outlined), onPressed: () => c.push('/report-card/${r['id']}')),
              if (r['status'] == 'draft') IconButton(tooltip: 'صدور', icon: const Icon(Icons.verified_outlined, color: Palette.success), onPressed: () async { try { await ref.read(apiProvider).post('/report-cards/${r['id']}/issue'); st.reload(); } on ApiException catch (e) { if (c.mounted) toast(c, e.readable, error: true); } }),
              if (r['status'] == 'issued') IconButton(tooltip: 'ابطال', icon: const Icon(Icons.block, color: Palette.danger), onPressed: () async { final why = await promptText(c, 'دلیل ابطال'); if (why != null) { await ref.read(apiProvider).post('/report-cards/${r['id']}/revoke', data: {'reason': why}); st.reload(); } }),
            ]))),
      ]);

  Future<void> _generate() async {
    final ok = await showForm(context, title: 'تولید کارنامه', submitLabel: 'تولید', fields: const [
      FieldSpec('section_id', 'کلاس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/sections', optionLabel: _sec), FieldSpec('term_id', 'ترم', type: FieldType.dropdown, required: true, optionsFrom: '/academics/terms', labelKey: 'title'),
    ], submit: (v) async { final r = await ref.read(apiProvider).post('/report-cards/generate', data: v); if (mounted) toast(context, '${faDigits(r['generated'])} کارنامهٔ پیش‌نویس ساخته شد (فقط از نمرات تأییدشده).'); });
    if (ok) _key.currentState?.reload();
  }

  Future<void> _issue() async {
    final ok = await showForm(context, title: 'صدور کارنامه‌های کلاس', submitLabel: 'صدور', fields: const [
      FieldSpec('section_id', 'کلاس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/sections', optionLabel: _sec), FieldSpec('term_id', 'ترم', type: FieldType.dropdown, required: true, optionsFrom: '/academics/terms', labelKey: 'title'),
    ], submit: (v) async { final r = await ref.read(apiProvider).post('/report-cards/issue-section', data: v); if (mounted) toast(context, 'صادرشده: ${faDigits((r['issued'] as List).length)}  ·  ناموفق: ${faDigits((r['failed'] as List).length)}${(r['failed'] as List).isNotEmpty ? ' (${(r['failed'] as List).first['error']})' : ''}'); });
    if (ok) _key.currentState?.reload();
  }

  Future<void> _templates() async {
    final r = await ref.read(apiProvider).get('/report-card-templates');
    if (!mounted) return;
    await showDialog(context: context, builder: (c) => AlertDialog(
          title: const Text('قالب‌های کارنامه'),
          content: SizedBox(width: 480, child: Column(mainAxisSize: MainAxisSize.min, children: [
            for (final t in r['data'] as List) ListTile(title: Text('${t['name']}'), subtitle: Text('امضا: ${(t['signatures'] as List).join('، ')}'), trailing: t['is_default'] == true ? const StatusChip('پیش‌فرض', tone: Tone.success) : null),
            if ((r['data'] as List).isEmpty) const Text('قالبی تعریف نشده؛ کارنامه با قالب ساده صادر می‌شود.'),
          ])),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('بستن')), FilledButton(onPressed: () async {
            Navigator.pop(c);
            await showForm(context, title: 'قالب جدید', fields: const [FieldSpec('name', 'نام قالب', required: true), FieldSpec('header', 'عنوان بالای کارنامه'), FieldSpec('footer', 'پانویس', type: FieldType.multiline), FieldSpec('signatures_text', 'محل امضا (هر خط یک مورد)', type: FieldType.multiline, required: true, initial: 'مدیر مدرسه\nمعلم'), FieldSpec('is_default', 'قالب پیش‌فرض', type: FieldType.toggle, initial: true)],
                submit: (v) async => ref.read(apiProvider).post('/report-card-templates', data: {...v..remove('signatures_text'), 'signatures': (v['signatures_text'] as String).split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty).toList()}));
          }, child: const Text('قالب جدید'))],
        ));
  }
}

String? _sec(Map<String, dynamic> m) => '${(m['grade'] as Map?)?['name'] ?? ''} ${m['name']}'.trim();

/// Messenger, announcements, content moderation, parent meeting requests.
class AdminMessages extends ConsumerStatefulWidget {
  const AdminMessages({super.key});
  @override
  ConsumerState<AdminMessages> createState() => _AdminMessagesState();
}

class _AdminMessagesState extends ConsumerState<AdminMessages> {
  final _ann = GlobalKey<PagedListState>();
  final _rep = GlobalKey<PagedListState>();

  @override
  Widget build(BuildContext context) => DefaultTabController(length: 4, child: Column(children: [
        const Material(color: Palette.surface, child: TabBar(isScrollable: true, tabAlignment: TabAlignment.start, tabs: [Tab(text: 'پیام‌ها'), Tab(text: 'اطلاعیه‌ها'), Tab(text: 'گزارش محتوای نامناسب'), Tab(text: 'درخواست جلسه')])),
        Expanded(child: TabBarView(children: [const MessagesScreen(), _announcements(), _moderation(), _meetings()])),
      ]));

  Widget _announcements() => PageBody(children: [
        PageHeader('اطلاعیه‌ها و پیام‌های رسمی', actions: [FilledButton.icon(key: const Key('new-announcement'), onPressed: () async {
          final ok = await showForm(context, title: 'اطلاعیهٔ جدید', submitLabel: 'انتشار', fields: const [
            FieldSpec('title', 'عنوان', required: true), FieldSpec('body', 'متن', type: FieldType.multiline, required: true),
            FieldSpec('audience_type', 'مخاطب', type: FieldType.dropdown, required: true, initial: 'school', options: [Option('school', 'کل مدرسه'), Option('grade', 'یک پایه'), Option('section', 'یک کلاس'), Option('student', 'یک خانواده')]),
            FieldSpec('audience_id', 'شناسهٔ پایه/کلاس/دانش‌آموز (برای مخاطب غیر از کل مدرسه)', type: FieldType.number),
          ], submit: (v) async { final r = await ref.read(apiProvider).post('/announcements', data: v); if (mounted) toast(context, 'برای ${faDigits(r['recipients'])} نفر ارسال شد.'); });
          if (ok) _ann.currentState?.reload();
        }, icon: const Icon(Icons.campaign_outlined), label: const Text('اطلاعیهٔ جدید'))]),
        PagedList(key: _ann, path: '/announcements', emptyText: 'اطلاعیه‌ای منتشر نشده است.', itemBuilder: (c, a, st) => AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Row(children: [Expanded(child: Text('${a['title']}', style: Theme.of(c).textTheme.titleMedium)), StatusChip({'school': 'کل مدرسه', 'grade': 'پایه', 'section': 'کلاس', 'student': 'خانواده'}[a['audience_type']] ?? '', tone: Tone.info)]), const SizedBox(height: 4), Text('${a['body']}'), Text(fmtDate(a['published_at'], withTime: true), style: Theme.of(c).textTheme.bodySmall)]))),
      ]);

  Widget _moderation() => PageBody(children: [
        const PageHeader('گزارش‌های رسیدگی‌نشده'),
        PagedList(key: _rep, path: '/moderation/reports', query: const {'status': 'open'}, emptyText: 'گزارش بازی وجود ندارد.', itemBuilder: (c, r, st) => AppCard(child: Row(children: [
              const Icon(Icons.flag_outlined, color: Palette.warn), const SizedBox(width: 12), Expanded(child: Text('${r['reason']}')),
              TextButton(onPressed: () async {
                final d = await ref.read(apiProvider).get('/moderation/reports/${r['id']}');
                if (!c.mounted) return;
                await showDialog(context: c, builder: (x) => AlertDialog(
                      title: const Text('پیام گزارش‌شده و زمینه (ثبت در سابقهٔ حسابرسی)'),
                      content: SizedBox(width: 520, child: ListView(shrinkWrap: true, children: [for (final m in d['context'] as List) Container(margin: const EdgeInsets.only(bottom: 6), padding: const EdgeInsets.all(8), decoration: BoxDecoration(color: m['id'] == d['message']['id'] ? Palette.dangerSoft : Palette.bg, borderRadius: BorderRadius.circular(8)), child: Text('${m['deleted'] == true ? '(حذف‌شده)' : (m['body'] ?? 'پیوست')}'))])),
                      actions: [
                        TextButton(onPressed: () => Navigator.pop(x), child: const Text('بستن')),
                        OutlinedButton(onPressed: () async { await ref.read(apiProvider).post('/moderation/reports/${r['id']}/resolve', data: {'action': 'dismiss'}); if (x.mounted) Navigator.pop(x); st.reload(); }, child: const Text('رد گزارش')),
                        FilledButton(style: FilledButton.styleFrom(backgroundColor: Palette.danger), onPressed: () async { await ref.read(apiProvider).post('/moderation/reports/${r['id']}/resolve', data: {'action': 'delete_message'}); if (x.mounted) Navigator.pop(x); st.reload(); }, child: const Text('حذف پیام')),
                      ],
                    ));
              }, child: const Text('بررسی')),
            ]))),
      ]);

  Widget _meetings() => PageBody(children: [
        const PageHeader('درخواست‌های جلسهٔ والدین'),
        PagedList(path: '/meetings', emptyText: 'درخواستی نیست.', itemBuilder: (c, m, st) => AppCard(child: Row(children: [
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${m['topic']}', style: Theme.of(c).textTheme.titleMedium), Text('دانش‌آموز ${faDigits(m['student_id'])} · ${fmtDate(m['preferred_at'], withTime: true)}', style: Theme.of(c).textTheme.bodySmall)])),
              if (m['status'] == 'pending') FilledButton(onPressed: () async { await showForm(context, title: 'پاسخ به درخواست', fields: const [FieldSpec('status', 'تصمیم', type: FieldType.dropdown, required: true, initial: 'accepted', options: [Option('accepted', 'تأیید و تعیین زمان'), Option('declined', 'رد')]), FieldSpec('scheduled_at', 'زمان جلسه', type: FieldType.datetime), FieldSpec('response_note', 'توضیح')], submit: (v) async => ref.read(apiProvider).put('/meetings/${m['id']}/respond', data: v)); st.reload(); }, child: const Text('پاسخ')) else StatusChip(m['status'] == 'accepted' ? 'تأیید شد' : 'رد شد', tone: Tone.neutral),
            ]))),
      ]);
}
