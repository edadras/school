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
import 'teacher_common.dart';

class TeacherAssignments extends ConsumerStatefulWidget {
  const TeacherAssignments({super.key, this.adminView = false});
  final bool adminView;
  @override
  ConsumerState<TeacherAssignments> createState() => _TeacherAssignmentsState();
}

class _TeacherAssignmentsState extends ConsumerState<TeacherAssignments> {
  final _key = GlobalKey<PagedListState>();
  final List<Map<String, dynamic>> _files = [];

  Future<void> _create() async {
    final t = await ref.read(teachingProvider.future);
    if (!mounted) return;
    _files.clear();
    final ok = await showForm(context, title: 'تکلیف جدید', submitLabel: 'ایجاد', fields: [
      FieldSpec('pair', 'کلاس و درس', type: FieldType.dropdown, required: true, options: teachingOptions(t)),
      const FieldSpec('title', 'عنوان', required: true), const FieldSpec('description', 'توضیح', type: FieldType.multiline),
      const FieldSpec('due_at', 'مهلت ارسال', type: FieldType.datetime), const FieldSpec('max_score', 'بارم', type: FieldType.number, initial: 20),
      const FieldSpec('answer_types', 'نوع پاسخ مجاز', type: FieldType.multiChoice, required: true, options: [Option('text', 'متن'), Option('file', 'عکس/PDF'), Option('audio', 'صوت'), Option('video', 'ویدئو'), Option('drawing', 'رسم'), Option('math', 'ریاضی')], initial: ['text', 'file']),
      const FieldSpec('rubric_text', 'معیارهای ارزیابی (هر خط: عنوان:بارم)', type: FieldType.multiline),
      const FieldSpec('allow_draft', 'ذخیرهٔ موقت پاسخ', type: FieldType.toggle, initial: true), const FieldSpec('allow_late', 'پذیرش پاسخ دیرهنگام', type: FieldType.toggle, initial: true),
      const FieldSpec('allow_resubmit', 'اجازهٔ ارسال مجدد', type: FieldType.toggle, initial: true), const FieldSpec('publish', 'انتشار همین حالا', type: FieldType.toggle, initial: true),
    ], submit: (v) async {
      final p = pairById(t, v['pair'])!;
      final rubric = <Map<String, dynamic>>[];
      for (final line in (v['rubric_text'] as String? ?? '').split('\n')) {
        final parts = line.split(':');
        if (parts.length == 2 && num.tryParse(parts[1].trim()) != null) rubric.add({'title': parts[0].trim(), 'max': num.parse(parts[1].trim())});
      }
      await ref.read(apiProvider).post('/assignments', data: {...v..remove('pair')..remove('rubric_text'), 'section_id': p['section_id'], 'subject_id': p['subject_id'], if (rubric.isNotEmpty) 'rubric': rubric, 'file_ids': [for (final f in _files) f['id']]});
    });
    if (ok) _key.currentState?.reload();
  }

  @override
  Widget build(BuildContext context) => PageBody(children: [
        PageHeader('تکالیف', actions: [if (!widget.adminView) FilledButton.icon(key: const Key('new-assignment'), onPressed: _create, icon: const Icon(Icons.add), label: const Text('تکلیف جدید'))]),
        PagedList(key: _key, path: '/assignments', emptyText: 'تکلیفی ایجاد نشده است.', itemBuilder: (c, a, st) => AppCard(
              onTap: () => c.push('/teacher/assignments/${a['id']}'),
              child: Row(children: [
                const Icon(Icons.assignment_outlined, color: Palette.brand), const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${a['title']}', style: Theme.of(c).textTheme.titleMedium), Text(a['due_at'] == null ? 'بدون مهلت' : 'مهلت: ${fmtDate(a['due_at'], withTime: true)}', style: Theme.of(c).textTheme.bodySmall)])),
                StatusChip(statusName(a['status'] as String), tone: a['status'] == 'published' ? Tone.success : Tone.neutral),
              ]),
            )),
      ]);
}

/// Submissions of one assignment: see everyone (incl. not started), open work, grade / return for revision, view attempt history.
class AssignmentReview extends ConsumerStatefulWidget {
  const AssignmentReview({super.key, required this.id});
  final int id;
  @override
  ConsumerState<AssignmentReview> createState() => _AssignmentReviewState();
}

class _AssignmentReviewState extends ConsumerState<AssignmentReview> {
  Map<String, dynamic>? _data;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await ref.read(apiProvider).get('/assignments/${widget.id}/submissions');
      setState(() { _data = Map<String, dynamic>.from(r); _error = null; });
    } catch (e) {
      setState(() => _error = e);
    }
  }

  Future<void> _grade(Map<String, dynamic> st, Map<String, dynamic> sub) async {
    final a = _data!['assignment'] as Map;
    final hist = await ref.read(apiProvider).get('/submissions/${sub['id']}/history');
    if (!mounted) return;
    await showDialog(context: context, builder: (c) {
      final score = TextEditingController(text: sub['score'] == null ? '' : fmtNum(sub['score']).replaceAll(RegExp(r'[۰-۹]'), ''));
      final fb = TextEditingController(text: sub['feedback'] ?? '');
      return AlertDialog(
        title: Text('${st['first_name']} ${st['last_name']}'),
        content: SizedBox(width: 560, child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [StatusChip(statusName(sub['status'] as String), tone: Tone.info), const SizedBox(width: 8), if (sub['is_late'] == true) const StatusChip('دیرکرد', tone: Tone.danger), const Spacer(), Text('تلاش ${faDigits(sub['attempt'])}')]),
          const SizedBox(height: 10),
          if ((sub['text_answer'] ?? '').toString().isNotEmpty) Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Palette.bg, borderRadius: BorderRadius.circular(10)), child: SelectableText('${sub['text_answer']}')),
          if ((sub['math'] as Map?)?['text'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: SelectableText('ریاضی: ${(sub['math'] as Map)['text']}', textDirection: TextDirection.ltr)),
          if ((sub['drawing'] as List?)?.isNotEmpty ?? false) const Padding(padding: EdgeInsets.only(top: 8), child: Text('✎ پاسخ دارای رسم است (در سوابق ذخیره شده).')),
          if ((sub['files'] as List?)?.isNotEmpty ?? false) Padding(padding: const EdgeInsets.only(top: 8), child: Wrap(spacing: 8, runSpacing: 8, children: [for (final f in sub['files'] as List) FileChip(f['file_id'] as int)])),
          const Divider(),
          TextField(controller: score, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: 'نمره (از ${fmtNum(a['max_score'])})')),
          const SizedBox(height: 10),
          TextField(controller: fb, minLines: 2, maxLines: 5, decoration: const InputDecoration(labelText: 'بازخورد')),
          if ((hist['data'] as List).length > 1) ...[const SizedBox(height: 10), Text('سوابق ارسال: ${(hist['data'] as List).map((h) => 'تلاش ${faDigits(h['attempt'])} (${fmtDate(h['snapshot']['submitted_at'], withTime: true)})').join('، ')}', style: Theme.of(context).textTheme.bodySmall)],
        ]))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: const Text('بستن')),
          OutlinedButton(onPressed: () async { await _post(c, sub, 'return', score.text, fb.text); }, child: const Text('بازگرداندن برای اصلاح')),
          FilledButton(key: const Key('finalize-grade'), onPressed: () async { await _post(c, sub, 'finalize', score.text, fb.text); }, child: const Text('ثبت نهایی نمره')),
        ],
      );
    });
    _load();
  }

  Future<void> _post(BuildContext c, Map<String, dynamic> sub, String outcome, String score, String fb) async {
    try {
      await ref.read(apiProvider).post('/submissions/${sub['id']}/grade', data: {'outcome': outcome, if (score.trim().isNotEmpty) 'score': num.tryParse(score.trim()), 'feedback': fb.isEmpty ? null : fb});
      if (c.mounted) Navigator.pop(c);
      if (mounted) toast(context, outcome == 'return' ? 'برای اصلاح بازگردانده شد.' : 'نمره ثبت شد (پس از تأیید مدرسه به دانش‌آموز نمایش داده می‌شود).');
    } on ApiException catch (e) {
      if (c.mounted) toast(c, e.readable, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final back = BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/teacher/assignments'));
    if (_error != null) return Scaffold(appBar: AppBar(leading: back), body: ErrorView(_error!, onRetry: _load));
    if (_data == null) return Scaffold(appBar: AppBar(leading: back), body: const Center(child: CircularProgressIndicator()));
    final a = _data!['assignment'] as Map;
    final rows = (_data!['students'] as List).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: Text('${a['title']}'), leading: back, actions: [
        if (a['status'] == 'draft') TextButton(onPressed: () async { await ref.read(apiProvider).post('/assignments/${a['id']}/publish'); _load(); }, child: const Text('انتشار')),
        if (a['status'] == 'published') TextButton(onPressed: () async { await ref.read(apiProvider).post('/assignments/${a['id']}/close'); _load(); }, child: const Text('بستن تکلیف')),
      ]),
      body: PageBody(children: [
        Row(children: [
          Expanded(child: StatCard(label: 'ارسال‌شده', value: faDigits(rows.where((r) => r['submission'] != null && !['viewed', 'in_progress'].contains(r['submission']['status'])).length), icon: Icons.upload_file)),
          const SizedBox(width: 10),
          Expanded(child: StatCard(label: 'نهایی‌شده', value: faDigits(rows.where((r) => r['submission']?['status'] == 'finalized').length), icon: Icons.verified_outlined, tone: Tone.success)),
          const SizedBox(width: 10),
          Expanded(child: StatCard(label: 'بدون پاسخ', value: faDigits(rows.where((r) => r['submission'] == null || ['viewed', 'in_progress'].contains(r['submission']['status'])).length), icon: Icons.hourglass_empty, tone: Tone.warn)),
        ]),
        const SizedBox(height: 14),
        for (final r in rows)
          Padding(padding: const EdgeInsets.only(bottom: 8), child: AppCard(
            onTap: r['submission'] != null && !['viewed', 'in_progress'].contains(r['submission']['status']) ? () => _grade(Map<String, dynamic>.from(r['student']), Map<String, dynamic>.from(r['submission'])) : null,
            child: Row(children: [
              Expanded(child: Text('${r['student']['first_name']} ${r['student']['last_name']}')),
              if (r['submission']?['score'] != null) Padding(padding: const EdgeInsets.only(left: 10), child: Text(fmtNum(r['submission']['score']), style: const TextStyle(fontWeight: FontWeight.w700, color: Palette.success))),
              StatusChip(r['submission'] == null ? 'مشاهده نشده' : statusName(r['submission']['status'] as String), tone: r['submission'] == null ? Tone.neutral : (r['submission']['status'] == 'late' ? Tone.danger : Tone.info)),
            ]),
          )),
      ]),
    );
  }
}
