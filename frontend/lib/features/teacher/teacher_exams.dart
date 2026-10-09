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
import 'teacher_common.dart';

class TeacherExams extends ConsumerStatefulWidget {
  const TeacherExams({super.key, this.adminView = false});
  final bool adminView;
  @override
  ConsumerState<TeacherExams> createState() => _TeacherExamsState();
}

class _TeacherExamsState extends ConsumerState<TeacherExams> with SingleTickerProviderStateMixin {
  late final _tabs = TabController(length: 2, vsync: this);
  final _bank = GlobalKey<PagedListState>();
  final _exams = GlobalKey<PagedListState>();
  Map<String, dynamic> _filter = {};

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Column(children: [
        Material(color: Palette.surface, child: TabBar(controller: _tabs, tabs: const [Tab(text: 'آزمون‌ها'), Tab(text: 'بانک سؤال')])),
        Expanded(child: TabBarView(controller: _tabs, children: [_examsTab(), _bankTab()])),
      ]);

  // ------------------------------------------------------------------ exams
  Widget _examsTab() => PageBody(children: [
        PageHeader('آزمون‌ها', actions: [if (!widget.adminView) FilledButton.icon(key: const Key('new-exam'), onPressed: _newExam, icon: const Icon(Icons.add), label: const Text('آزمون جدید'))]),
        PagedList(key: _exams, path: '/exams', emptyText: 'آزمونی ساخته نشده است.', itemBuilder: (c, e, st) => AppCard(
              onTap: () => c.push('/teacher/exams/${e['id']}'),
              child: Row(children: [
                const Icon(Icons.quiz_outlined, color: Palette.brand), const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${e['title']}', style: Theme.of(c).textTheme.titleMedium), Text('${fmtDate(e['start_at'], withTime: true)} تا ${fmtDate(e['end_at'], withTime: true)}', style: Theme.of(c).textTheme.bodySmall)])),
                StatusChip(statusName(e['status'] as String), tone: e['status'] == 'published' ? Tone.success : Tone.neutral),
              ]),
            )),
      ]);

  Future<void> _newExam() async {
    final t = await ref.read(teachingProvider.future);
    if (!mounted) return;
    // Pick the class/subject first, then the questions of that subject from the bank.
    final pairId = await showDialog<int>(context: context, builder: (c) => SimpleDialog(title: const Text('آزمون برای کدام کلاس و درس؟'), children: [for (final x in t) SimpleDialogOption(onPressed: () => Navigator.pop(c, x['id'] as int), child: Text('${x['label']}'))]));
    if (pairId == null || !mounted) return;
    final pair = pairById(t, pairId)!;
    final qs = await ref.read(apiProvider).get('/questions', query: {'subject_id': pair['subject_id'], 'per_page': 100});
    final bank = (qs['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    if (bank.isEmpty) { if (mounted) toast(context, 'ابتدا برای این درس در «بانک سؤال» سؤال بسازید.', error: true); return; }
    if (!mounted) return;
    final ok = await showForm(context, title: 'آزمون ${pair['label']}', submitLabel: 'ایجاد', fields: [
      const FieldSpec('title', 'عنوان آزمون', required: true), const FieldSpec('start_at', 'شروع', type: FieldType.datetime, required: true), const FieldSpec('end_at', 'پایان (آخرین زمان ورود/ارسال)', type: FieldType.datetime, required: true),
      const FieldSpec('duration_minutes', 'مدت مجاز (دقیقه)', type: FieldType.number, required: true, initial: 30), const FieldSpec('max_attempts', 'تعداد دفعات شرکت', type: FieldType.number, initial: 1),
      const FieldSpec('shuffle_questions', 'ترتیب تصادفی سؤال‌ها', type: FieldType.toggle), const FieldSpec('shuffle_options', 'ترتیب تصادفی گزینه‌ها', type: FieldType.toggle),
      const FieldSpec('show_score', 'نمایش نمره به دانش‌آموز', type: FieldType.dropdown, initial: 'manual', options: [Option('never', 'هرگز'), Option('immediately', 'بلافاصله (پس از تصحیح)'), Option('after_end', 'پس از پایان آزمون'), Option('manual', 'پس از انتشار توسط معلم')]),
      const FieldSpec('show_answers', 'نمایش پاسخ صحیح', type: FieldType.dropdown, initial: 'never', options: [Option('never', 'هرگز'), Option('after_end', 'پس از پایان آزمون'), Option('manual', 'پس از انتشار توسط معلم')]),
      FieldSpec('questions', 'سؤال‌ها', type: FieldType.multiChoice, required: true, options: [for (final q in bank) Option(q['id'] as int, '[${tr('qtype.${q['type']}')}] ${(q['body'] as String).length > 50 ? '${(q['body'] as String).substring(0, 50)}…' : q['body']}')]),
      const FieldSpec('publish', 'انتشار برای دانش‌آموزان', type: FieldType.toggle, initial: true),
    ], submit: (v) async {
      final publish = v.remove('publish') == true;
      final r = await ref.read(apiProvider).post('/exams', data: {...v, 'section_id': pair['section_id'], 'subject_id': pair['subject_id'], 'questions': [for (final id in v['questions'] as List) {'question_id': id}]});
      if (publish) await ref.read(apiProvider).post('/exams/${r['data']['id']}/publish');
    });
    if (ok) _exams.currentState?.reload();
  }

  // ------------------------------------------------------------------ bank
  Widget _bankTab() => PageBody(children: [
        PageHeader('بانک سؤال', actions: [FilledButton.icon(key: const Key('new-question'), onPressed: () => _editQuestion(), icon: const Icon(Icons.add), label: const Text('سؤال جدید'))]),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final t in const ['mcq', 'tf', 'fill', 'short', 'essay']) FilterChip(label: Text(tr('qtype.$t')), selected: _filter['type'] == t, onSelected: (on) => setState(() => _filter = {..._filter, 'type': on ? t : null})),
        ]),
        const SizedBox(height: 10),
        SearchField(onChanged: (v) => setState(() => _filter = {..._filter, 'q': v})),
        const SizedBox(height: 10),
        PagedList(key: _bank, path: '/questions', query: _filter, emptyText: 'سؤالی در بانک نیست.', itemBuilder: (c, q, st) => AppCard(
              child: Row(children: [
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${q['body']}', maxLines: 2, overflow: TextOverflow.ellipsis),
                  const SizedBox(height: 4),
                  Wrap(spacing: 6, children: [StatusChip(tr('qtype.${q['type']}'), tone: Tone.info), StatusChip('دشواری ${faDigits(q['difficulty'])}', tone: Tone.neutral), if (q['topic'] != null) StatusChip('${q['topic']}', tone: Tone.neutral), StatusChip('${fmtNum(q['points'])} نمره', tone: Tone.neutral)]),
                ])),
                IconButton(tooltip: 'ویرایش', icon: const Icon(Icons.edit_outlined), onPressed: () => _editQuestion(q)),
                IconButton(tooltip: 'حذف', icon: const Icon(Icons.delete_outline, color: Palette.danger), onPressed: () async { if (await confirm(c, 'حذف شود؟', danger: true)) { try { await ref.read(apiProvider).delete('/questions/${q['id']}'); st.reload(); } on ApiException catch (e) { if (c.mounted) toast(c, e.readable, error: true); } } }),
              ]),
            )),
      ]);

  Future<void> _editQuestion([Map<String, dynamic>? q]) async {
    final t = await ref.read(teachingProvider.future);
    final subjects = {for (final x in t) x['subject_id'] as int: (x['subject'] as String)};
    if (!mounted) return;
    final ok = await showDialog<bool>(context: context, builder: (c) => _QuestionDialog(subjects: subjects, initial: q));
    if (ok == true) _bank.currentState?.reload();
  }
}

class _QuestionDialog extends ConsumerStatefulWidget {
  const _QuestionDialog({required this.subjects, this.initial});
  final Map<int, String> subjects;
  final Map<String, dynamic>? initial;
  @override
  ConsumerState<_QuestionDialog> createState() => _QuestionDialogState();
}

class _QuestionDialogState extends ConsumerState<_QuestionDialog> {
  late int? _subject = widget.initial?['subject_id'] as int? ?? widget.subjects.keys.firstOrNull;
  late String _type = widget.initial?['type'] ?? 'mcq';
  late int _diff = widget.initial?['difficulty'] ?? 2;
  late final _body = TextEditingController(text: widget.initial?['body'] ?? '');
  late final _topic = TextEditingController(text: widget.initial?['topic'] ?? '');
  late final _points = TextEditingController(text: '${widget.initial?['points'] ?? 1}');
  late final _answers = TextEditingController(text: ((widget.initial?['accepted_answers'] as List?) ?? []).whereType<String>().join('\n'));
  late final _rubric = TextEditingController(text: widget.initial?['rubric'] ?? '');
  late final List<(TextEditingController, bool)> _opts = [
    for (final o in ((widget.initial?['options'] as List?) ?? [])) (TextEditingController(text: o['text'] as String), o['is_correct'] == true),
    if ((widget.initial?['options'] as List?) == null) ...[(TextEditingController(), true), (TextEditingController(), false), (TextEditingController(), false), (TextEditingController(), false)],
  ];
  late bool _tf = (widget.initial?['accepted_answers'] as List?)?.firstOrNull == true;
  String? _err;
  bool _busy = false;

  Future<void> _save() async {
    final data = <String, dynamic>{
      'subject_id': _subject, 'type': _type, 'body': _body.text.trim(), 'topic': _topic.text.trim().isEmpty ? null : _topic.text.trim(), 'difficulty': _diff, 'points': num.tryParse(_points.text) ?? 1,
      if (_type == 'mcq') 'options': [for (final o in _opts) if (o.$1.text.trim().isNotEmpty) {'text': o.$1.text.trim(), 'is_correct': o.$2}],
      if (_type == 'tf') 'accepted_answers': [_tf],
      if (_type == 'fill' || _type == 'short') 'accepted_answers': _answers.text.split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty).toList(),
      if (_type == 'essay') 'rubric': _rubric.text.trim(),
    };
    setState(() { _busy = true; _err = null; });
    try {
      final api = ref.read(apiProvider);
      widget.initial == null ? await api.post('/questions', data: data) : await api.patch('/questions/${widget.initial!['id']}', data: data);
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() { _err = e.readable; _busy = false; });
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(widget.initial == null ? 'سؤال جدید' : 'ویرایش سؤال'),
        content: SizedBox(width: 560, child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (_err != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(_err!, style: const TextStyle(color: Palette.danger))),
          Row(children: [
            Expanded(child: DropdownButtonFormField<int>(initialValue: _subject, decoration: const InputDecoration(labelText: 'درس'), items: [for (final e in widget.subjects.entries) DropdownMenuItem(value: e.key, child: Text(e.value))], onChanged: (v) => setState(() => _subject = v))),
            const SizedBox(width: 10),
            Expanded(child: DropdownButtonFormField<String>(initialValue: _type, decoration: const InputDecoration(labelText: 'نوع سؤال'), items: [for (final t in const ['mcq', 'tf', 'fill', 'short', 'essay']) DropdownMenuItem(value: t, child: Text(tr('qtype.$t')))], onChanged: widget.initial == null ? (v) => setState(() => _type = v!) : null)),
          ]),
          const SizedBox(height: 12),
          TextField(key: const Key('q-body'), controller: _body, minLines: 2, maxLines: 6, decoration: const InputDecoration(labelText: 'متن سؤال *')),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: TextField(controller: _topic, decoration: const InputDecoration(labelText: 'مبحث'))), const SizedBox(width: 10),
            Expanded(child: DropdownButtonFormField<int>(initialValue: _diff, decoration: const InputDecoration(labelText: 'دشواری'), items: [for (var i = 1; i <= 5; i++) DropdownMenuItem(value: i, child: Text(faDigits(i)))], onChanged: (v) => setState(() => _diff = v!))), const SizedBox(width: 10),
            SizedBox(width: 90, child: TextField(controller: _points, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'نمره'))),
          ]),
          const SizedBox(height: 12),
          if (_type == 'mcq') ...[
            const Text('گزینه‌ها (گزینه‌های درست را علامت بزنید)'),
            for (var i = 0; i < _opts.length; i++) Row(children: [
              Checkbox(value: _opts[i].$2, onChanged: (v) => setState(() => _opts[i] = (_opts[i].$1, v ?? false))),
              Expanded(child: TextField(key: Key('opt-field-$i'), controller: _opts[i].$1, decoration: InputDecoration(hintText: 'گزینهٔ ${faDigits(i + 1)}'))),
            ]),
            TextButton.icon(onPressed: () => setState(() => _opts.add((TextEditingController(), false))), icon: const Icon(Icons.add), label: const Text('گزینهٔ دیگر')),
          ],
          if (_type == 'tf') SegmentedButton<bool>(segments: const [ButtonSegment(value: true, label: Text('درست')), ButtonSegment(value: false, label: Text('غلط'))], selected: {_tf}, onSelectionChanged: (s) => setState(() => _tf = s.first)),
          if (_type == 'fill' || _type == 'short') TextField(controller: _answers, minLines: 2, maxLines: 5, decoration: const InputDecoration(labelText: 'پاسخ‌های پذیرفته‌شده (هر خط یک پاسخ)')),
          if (_type == 'essay') TextField(controller: _rubric, minLines: 3, maxLines: 8, decoration: const InputDecoration(labelText: 'کلید پاسخ و معیار نمره‌دهی')),
        ]))),
        actions: [TextButton(onPressed: _busy ? null : () => Navigator.pop(context, false), child: const Text('انصراف')), FilledButton(key: const Key('q-save'), onPressed: _busy ? null : _save, child: const Text('ذخیره'))],
      );
}

/// Exam control room: publish, release results, analysis, grading queue (essays), AI suggestion per answer (advisory).
class ExamManage extends ConsumerStatefulWidget {
  const ExamManage({super.key, required this.id});
  final int id;
  @override
  ConsumerState<ExamManage> createState() => _ExamManageState();
}

class _ExamManageState extends ConsumerState<ExamManage> {
  Map<String, dynamic>? _exam, _analysis;
  List<Map<String, dynamic>> _attempts = [];
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final api = ref.read(apiProvider);
      final list = await api.get('/exams', query: {'per_page': 100});
      _exam = ((list['data'] as List).firstWhere((e) => e['id'] == widget.id) as Map).cast<String, dynamic>();
      _attempts = ((await api.get('/exams/${widget.id}/attempts'))['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      _analysis = Map<String, dynamic>.from((await api.get('/exams/${widget.id}/analysis'))['data']);
      setState(() => _error = null);
    } catch (e) {
      setState(() => _error = e);
    }
  }

  Future<void> _openAttempt(Map<String, dynamic> a) async {
    final d = await ref.read(apiProvider).get('/exam-attempts/${a['id']}/detail');
    if (!mounted) return;
    final answers = (d['answers'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    await showDialog(context: context, builder: (c) => StatefulBuilder(builder: (c, set) => AlertDialog(
          title: Text('پاسخ‌های ${a['student']?['first_name'] ?? ''} ${a['student']?['last_name'] ?? ''}'),
          content: SizedBox(width: 640, height: 480, child: ListView(children: [
            for (final x in answers) Card(margin: const EdgeInsets.only(bottom: 10), child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${x['question']['body']}', style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 4),
              Text('پاسخ: ${_fmtAnswer(x)}'),
              if (x['question']['type'] == 'essay') ...[
                if (x['question']['rubric'] != null) Text('معیار: ${x['question']['rubric']}', style: Theme.of(c).textTheme.bodySmall),
                if (x['ai_suggestion'] != null) Container(margin: const EdgeInsets.only(top: 6), padding: const EdgeInsets.all(8), decoration: BoxDecoration(color: Palette.warnSoft, borderRadius: BorderRadius.circular(8)), child: Text('پیشنهاد هوش مصنوعی (غیرقطعی): ${fmtNum(x['ai_suggestion']['suggested_score'])} — ${x['ai_suggestion']['rationale'] ?? ''}')),
                Row(children: [
                  SizedBox(width: 120, child: TextField(controller: TextEditingController(text: x['manual_score'] == null ? '' : '${x['manual_score']}'), keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'نمره', isDense: true), onSubmitted: (v) => _grade(c, x, v))),
                  const SizedBox(width: 8),
                  OutlinedButton.icon(onPressed: () async { try { final r = await ref.read(apiProvider).post('/ai/teacher/answers/${x['id']}/grade-suggestion'); set(() => x['ai_suggestion'] = r['suggestion']); } on ApiException catch (e) { if (c.mounted) toast(c, e.code == 'ai_unconfigured' ? 'سرویس هوش مصنوعی پیکربندی نشده است.' : e.readable, error: true); } }, icon: const Icon(Icons.auto_awesome, size: 16), label: const Text('پیشنهاد AI')),
                ]),
              ] else Text('نمره: ${fmtNum(x['auto_score'])}', style: Theme.of(c).textTheme.bodySmall),
            ]))),
          ])),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('بستن'))],
        )));
    _load();
  }

  String _fmtAnswer(Map x) {
    final a = x['answer'];
    if (a == null) return '— بدون پاسخ —';
    if (a['text'] != null) return '${a['text']}';
    if (a['value'] != null) return a['value'] == true ? 'درست' : 'غلط';
    final ids = (a['option_ids'] as List?) ?? [];
    final opts = (x['question']['options'] as List?) ?? [];
    return ids.map((i) => opts.where((o) => o['id'] == i).map((o) => o['text']).join()).join('، ');
  }

  Future<void> _grade(BuildContext c, Map x, String v) async {
    try {
      await ref.read(apiProvider).put('/exam-answers/${x['id']}/grade', data: {'score': num.parse(v)});
      if (c.mounted) toast(c, 'نمره ثبت شد.');
    } on ApiException catch (e) {
      if (c.mounted) toast(c, e.readable, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final back = BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/teacher/exams'));
    if (_error != null) return Scaffold(appBar: AppBar(leading: back), body: ErrorView(_error!, onRetry: _load));
    if (_exam == null) return Scaffold(appBar: AppBar(leading: back), body: const Center(child: CircularProgressIndicator()));
    final e = _exam!;
    final an = _analysis!;
    return Scaffold(
      appBar: AppBar(title: Text('${e['title']}'), leading: back, actions: [
        if (e['status'] == 'draft') TextButton(onPressed: () async { await ref.read(apiProvider).post('/exams/${e['id']}/publish'); _load(); }, child: const Text('انتشار')),
        TextButton(key: const Key('release-results'), onPressed: () async { await ref.read(apiProvider).post('/exams/${e['id']}/release'); if (mounted) toast(context, 'نتایج برای دانش‌آموزان و والدین منتشر شد.'); _load(); }, child: const Text('انتشار نتایج')),
      ]),
      body: PageBody(children: [
        Row(children: [
          Expanded(child: StatCard(label: 'شرکت‌کننده', value: faDigits(an['attempts']), icon: Icons.groups)),
          const SizedBox(width: 10),
          Expanded(child: StatCard(label: 'میانگین', value: fmtNum(an['average']), icon: Icons.analytics_outlined, tone: Tone.success)),
          const SizedBox(width: 10),
          Expanded(child: StatCard(label: 'در انتظار تصحیح', value: faDigits(_attempts.where((a) => a['status'] == 'submitted').length), icon: Icons.rate_review_outlined, tone: Tone.warn)),
        ]),
        const SectionTitle('توزیع نمرات'),
        AppCard(child: SizedBox(height: 110, child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
          for (final b in (an['distribution'] as List))
            Expanded(child: Column(mainAxisAlignment: MainAxisAlignment.end, children: [Text(faDigits(b['count'])), Container(height: 6.0 + (b['count'] as int) * 14, margin: const EdgeInsets.symmetric(horizontal: 6), decoration: BoxDecoration(color: Palette.brand, borderRadius: BorderRadius.circular(6))), Text('${faDigits(b['from'])}–${faDigits(b['to'])}٪', style: Theme.of(context).textTheme.bodySmall)])),
        ]))),
        const SectionTitle('تحلیل سؤال‌ها'),
        for (final q in (an['questions'] as List)) AppCard(padding: const EdgeInsets.all(12), child: Row(children: [Expanded(child: Text('سؤال ${faDigits(q['question_id'])}')), Text('درصد موفقیت: ${q['success_rate'] == null ? '—' : fmtNum(q['success_rate'])}٪'), const SizedBox(width: 8), if (q['hard'] == true) const StatusChip('دشوار', tone: Tone.danger)])),
        const SectionTitle('پاسخ‌نامه‌ها'),
        if (_attempts.isEmpty) const EmptyState('هنوز کسی شرکت نکرده است.')
        else for (final a in _attempts) Padding(padding: const EdgeInsets.only(bottom: 8), child: AppCard(onTap: () => _openAttempt(a), child: Row(children: [Expanded(child: Text('${a['student']?['first_name']} ${a['student']?['last_name']} — تلاش ${faDigits(a['attempt_no'])}')), if (a['total_score'] != null) Text(fmtNum(a['total_score']), style: const TextStyle(fontWeight: FontWeight.w700)), const SizedBox(width: 8), StatusChip(statusName(a['status'] as String), tone: a['status'] == 'graded' ? Tone.success : Tone.warn)]))),
      ]),
    );
  }
}
