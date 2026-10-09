import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/forms.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import '../common/files_ui.dart';
import '../common/schedule_widgets.dart';
import 'teacher_common.dart';

class TeacherHome extends ConsumerWidget {
  const TeacherHome({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(sessionProvider);
    return PageBody(
      onRefresh: () async { ref.invalidate(myScheduleProvider); ref.invalidate(todaySessionsProvider); },
      children: [
        PageHeader('سلام ${s.name}', subtitle: 'برنامهٔ تدریس امروز'),
        const TodayPanel(teacher: true),
        const SectionTitle('هفتهٔ من'),
        Async<ScheduleData>(ref.watch(myScheduleProvider), builder: (d) => WeeklyGrid(data: d, showSection: true)),
      ],
    );
  }
}

class TeacherClasses extends ConsumerWidget {
  const TeacherClasses({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => PageBody(children: [
        const PageHeader('کلاس‌های من'),
        Async<List<Map<String, dynamic>>>(ref.watch(teachingProvider), onRetry: () => ref.invalidate(teachingProvider), builder: (t) => t.isEmpty
            ? const EmptyState('هنوز درسی به شما تخصیص داده نشده است.', icon: Icons.class_outlined)
            : Column(children: [for (final x in t) Padding(padding: const EdgeInsets.only(bottom: 10), child: AppCard(onTap: () => context.push('/teacher/classes/${x['section_id']}/${x['subject_id']}'), child: Row(children: [const Icon(Icons.class_outlined, color: Palette.brand), const SizedBox(width: 12), Expanded(child: Text('${x['label']}', style: Theme.of(context).textTheme.titleMedium)), const Icon(Icons.chevron_left)])))])),
      ]);
}

/// One class+subject: students & roll call, materials, make-up sessions.
class ClassDetail extends ConsumerStatefulWidget {
  const ClassDetail({super.key, required this.sectionId, required this.subjectId});
  final int sectionId, subjectId;
  @override
  ConsumerState<ClassDetail> createState() => _ClassDetailState();
}

class _ClassDetailState extends ConsumerState<ClassDetail> with SingleTickerProviderStateMixin {
  late final _tabs = TabController(length: 4, vsync: this);
  final _matKey = GlobalKey<PagedListState>();

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(teachingProvider).valueOrNull ?? [];
    final pair = t.where((x) => x['section_id'] == widget.sectionId && x['subject_id'] == widget.subjectId).firstOrNull;
    return Scaffold(
      appBar: AppBar(title: Text(pair?['label'] ?? 'کلاس'), leading: BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/teacher/classes')),
          bottom: TabBar(controller: _tabs, tabs: const [Tab(text: 'دانش‌آموزان'), Tab(text: 'حضور و غیاب'), Tab(text: 'محتوا'), Tab(text: 'کلاس جبرانی')])),
      body: TabBarView(controller: _tabs, children: [_students(), _attendance(), _materials(), _makeup()]),
    );
  }

  Widget _students() => PageBody(children: [
        PagedList(path: '/academics/students', query: {'section_id': widget.sectionId}, emptyText: 'دانش‌آموزی نیست.', perPage: 100, itemBuilder: (c, s, st) => AppCard(child: Row(children: [const Icon(Icons.person_outline), const SizedBox(width: 10), Expanded(child: Text('${s['first_name']} ${s['last_name']}')), Text(faDigits(s['student_code']), style: Theme.of(c).textTheme.bodySmall), IconButton(tooltip: 'خلاصهٔ حضور', icon: const Icon(Icons.how_to_reg_outlined), onPressed: () => _summary(s))]))),
      ]);

  Future<void> _summary(Map<String, dynamic> s) async {
    final r = await ref.read(apiProvider).get('/attendance/students/${s['id']}/summary');
    final d = r['data'] as Map;
    if (!mounted) return;
    showDialog(context: context, builder: (c) => AlertDialog(title: Text('${s['first_name']} ${s['last_name']}'), content: Text('حاضر: ${faDigits(d['present'])}   تأخیر: ${faDigits(d['late'])}   غایب: ${faDigits(d['absent'])}   موجه: ${faDigits(d['excused'])}'), actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('بستن'))]));
  }

  /// Roll call for a date: pick one of today's sessions or in-person lesson entry.
  Widget _attendance() => PageBody(children: [
        const SectionTitle('حضور و غیاب'),
        AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('برای کلاس‌های آنلاین، حضور هنگام ورود دانش‌آموز خودکار ثبت می‌شود و شما می‌توانید اصلاح کنید.'),
          const SizedBox(height: 10),
          FilledButton.icon(onPressed: _rollCall, icon: const Icon(Icons.fact_check_outlined), label: const Text('ثبت / اصلاح حضور و غیاب')),
        ])),
        const SectionTitle('سوابق اخیر'),
        PagedList(path: '/attendance', query: {'section_id': widget.sectionId}, emptyText: 'سابقه‌ای نیست.', itemBuilder: (c, a, st) => AppCard(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10), child: Row(children: [Expanded(child: Text('دانش‌آموز ${faDigits(a['student_id'])}')), Text(fmtDate(a['on_date'])), const SizedBox(width: 10), StatusChip(tr('att.${a['status']}'), tone: a['status'] == 'present' ? Tone.success : (a['status'] == 'absent' ? Tone.danger : Tone.warn))]))),
      ]);

  Future<void> _rollCall() async {
    final api = ref.read(apiProvider);
    final sess = await api.get('/sessions', query: {'per_page': 50});
    final mine = ((sess['data'] as List).where((x) => x['section_id'] == widget.sectionId && x['subject_id'] == widget.subjectId)).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final studs = (await api.get('/academics/students', query: {'per_page': 100, 'section_id': widget.sectionId}))['data'] as List;
    if (!mounted) return;
    if (mine.isEmpty) { toast(context, 'جلسه‌ای برای ثبت حضور نیست؛ کلاس آنلاین یا جبرانی ایجاد کنید.', error: true); return; }
    final target = await showDialog<Map<String, dynamic>>(context: context, builder: (c) => SimpleDialog(title: const Text('کدام جلسه؟'), children: [for (final s in mine) SimpleDialogOption(onPressed: () => Navigator.pop(c, s), child: Text('${s['title']} — ${fmtDate(s['scheduled_start'], withTime: true)}'))]));
    if (target == null || !mounted) return;
    final status = <int, String>{for (final s in studs) s['id'] as int: 'present'};
    await showDialog(context: context, builder: (c) => StatefulBuilder(builder: (c, set) => AlertDialog(
          title: const Text('حضور و غیاب'),
          content: SizedBox(width: 460, height: 420, child: ListView(children: [for (final s in studs) ListTile(dense: true, title: Text('${s['first_name']} ${s['last_name']}'), trailing: DropdownButton<String>(value: status[s['id']], items: [for (final k in const ['present', 'late', 'absent', 'excused']) DropdownMenuItem(value: k, child: Text(tr('att.$k')))], onChanged: (v) => set(() => status[s['id'] as int] = v!)))])),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('انصراف')), FilledButton(onPressed: () async {
            try { await api.put('/sessions/${target['id']}/attendance', data: {'items': [for (final e in status.entries) {'student_id': e.key, 'status': e.value}]}); if (c.mounted) Navigator.pop(c); if (mounted) toast(context, 'حضور و غیاب ثبت شد.'); } on ApiException catch (e) { if (c.mounted) toast(c, e.readable, error: true); }
          }, child: const Text('ثبت'))],
        )));
  }

  Widget _materials() => PageBody(children: [
        PageHeader('محتوای آموزشی', actions: [FilledButton.icon(key: const Key('add-material'), onPressed: _addMaterial, icon: const Icon(Icons.add), label: const Text('افزودن'))]),
        PagedList(key: _matKey, path: '/materials', query: {'section_id': widget.sectionId, 'subject_id': widget.subjectId}, emptyText: 'محتوایی منتشر نشده است.', itemBuilder: (c, m, st) => AppCard(child: Row(children: [
              Icon(switch (m['kind']) { 'link' => Icons.link, 'text' => Icons.article_outlined, _ => Icons.attach_file }, color: Palette.brand), const SizedBox(width: 10),
              Expanded(child: Text('${m['title']}')), if (m['published_at'] == null) const StatusChip('پیش‌نویس', tone: Tone.neutral),
              IconButton(icon: const Icon(Icons.delete_outline, color: Palette.danger), onPressed: () async { if (await confirm(c, 'حذف شود؟', danger: true)) { await ref.read(apiProvider).delete('/materials/${m['id']}'); _matKey.currentState?.reload(); } }),
            ]))),
      ]);

  Future<void> _addMaterial() async {
    Map<String, dynamic>? file;
    final ok = await showForm(context, title: 'محتوای جدید', fields: const [
      FieldSpec('title', 'عنوان', required: true),
      FieldSpec('kind', 'نوع', type: FieldType.dropdown, required: true, options: [Option('text', 'متن درس‌نامه'), Option('link', 'پیوند'), Option('file', 'فایل')], initial: 'text'),
      FieldSpec('body', 'متن یا نشانی پیوند', type: FieldType.multiline),
      FieldSpec('ai_indexable', 'اجازهٔ استفادهٔ دستیار هوشمند از این محتوا', type: FieldType.toggle, initial: true),
    ], submit: (v) async {
      if (v['kind'] == 'file') {
        final up = await pickAndUpload(context, ref, multiple: false);
        if (up.isEmpty) throw ApiException('فایلی انتخاب نشد.');
        file = up.first;
      }
      await ref.read(apiProvider).post('/materials', data: {...v, 'section_id': widget.sectionId, 'subject_id': widget.subjectId, 'file_id': file?['id']});
    });
    if (ok) _matKey.currentState?.reload();
  }

  Widget _makeup() => PageBody(children: [
        const PageHeader('کلاس جبرانی / جایگزین'),
        AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('یک جلسهٔ آنلاین خارج از برنامهٔ هفتگی بسازید؛ دانش‌آموزان و والدین اعلان می‌گیرند.'),
          const SizedBox(height: 10),
          FilledButton.icon(key: const Key('add-makeup'), onPressed: () => showForm(context, title: 'کلاس جدید', fields: const [
                FieldSpec('kind', 'نوع', type: FieldType.dropdown, required: true, options: [Option('makeup', 'جبرانی'), Option('substitute', 'جایگزین')], initial: 'makeup'),
                FieldSpec('title', 'عنوان', required: true), FieldSpec('scheduled_start', 'شروع', type: FieldType.datetime, required: true), FieldSpec('scheduled_end', 'پایان', type: FieldType.datetime, required: true),
              ], submit: (v) async { await ref.read(apiProvider).post('/sessions', data: {...v, 'section_id': widget.sectionId, 'subject_id': widget.subjectId}); ref.invalidate(todaySessionsProvider); }), icon: const Icon(Icons.add), label: const Text('ساخت کلاس')),
        ])),
        const SectionTitle('جلسه‌های این درس'),
        PagedList(path: '/sessions', emptyText: 'جلسه‌ای نیست.', itemBuilder: (c, s, st) => s['section_id'] != widget.sectionId || s['subject_id'] != widget.subjectId ? const SizedBox.shrink() : AppCard(onTap: () => c.push('/live/${s['id']}'), child: Row(children: [Expanded(child: Text('${s['title']}')), Text(fmtDate(s['scheduled_start'], withTime: true)), const SizedBox(width: 8), StatusChip(statusName(s['status'] as String), tone: s['status'] == 'live' ? Tone.success : Tone.neutral)]))),
      ]);
}
