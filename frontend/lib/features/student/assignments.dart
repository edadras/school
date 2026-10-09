import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import '../common/drawing_pad.dart';
import '../common/files_ui.dart';
import '../common/notifications.dart';

Tone _tone(String s) => switch (s) { 'finalized' => Tone.success, 'late' || 'overdue' => Tone.danger, 'needs_revision' => Tone.warn, 'submitted' || 'under_review' => Tone.info, _ => Tone.neutral };

class AssignmentsList extends ConsumerStatefulWidget {
  const AssignmentsList({super.key, this.studentId});
  final int? studentId; // guardian: a specific child
  @override
  ConsumerState<AssignmentsList> createState() => _AssignmentsListState();
}

class _AssignmentsListState extends ConsumerState<AssignmentsList> {
  String _filter = 'all';
  @override
  Widget build(BuildContext context) {
    return PageBody(children: [
      const PageHeader('تکالیف'),
      Wrap(spacing: 8, children: [for (final f in const [('all', 'همه'), ('open', 'باز'), ('done', 'ارسال‌شده'), ('graded', 'نمره‌دار')]) ChoiceChip(label: Text(f.$2), selected: _filter == f.$1, onSelected: (_) => setState(() => _filter = f.$1))]),
      const SizedBox(height: 12),
      PagedList(
        path: '/assignments', query: {'student_id': widget.studentId, 'status': null}, emptyText: 'تکلیفی وجود ندارد.',
        itemBuilder: (c, a, st) {
          final state = (a['state'] ?? 'published') as String;
          final show = switch (_filter) { 'open' => ['published', 'viewed', 'in_progress', 'needs_revision', 'overdue'].contains(state), 'done' => ['submitted', 'late', 'under_review'].contains(state), 'graded' => state == 'finalized', _ => true };
          if (!show) return const SizedBox.shrink();
          final sub = a['my_submission'] as Map?;
          return AppCard(
            onTap: widget.studentId != null ? null : () => c.push('/assignment/${a['id']}'),
            child: Row(children: [
              const Icon(Icons.assignment_outlined, color: Palette.brand),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${a['title']}', style: Theme.of(c).textTheme.titleMedium),
                Text(a['due_at'] == null ? 'بدون مهلت' : 'مهلت: ${fmtDate(a['due_at'], withTime: true)}', style: Theme.of(c).textTheme.bodySmall),
                if (sub?['score'] != null) Text('نمره: ${fmtNum(sub!['score'])} از ${fmtNum(a['max_score'])}', style: const TextStyle(color: Palette.success)),
              ])),
              StatusChip(statusName(state), tone: _tone(state)),
            ]),
          );
        },
      ),
    ]);
  }
}

/// Student's assignment page: instructions + answer editor (text / files / audio / video / drawing / math) with draft autosave.
class AssignmentDetail extends ConsumerStatefulWidget {
  const AssignmentDetail({super.key, required this.id});
  final int id;
  @override
  ConsumerState<AssignmentDetail> createState() => _AssignmentDetailState();
}

class _AssignmentDetailState extends ConsumerState<AssignmentDetail> {
  Map<String, dynamic>? _a, _sub;
  List<int> _attachments = [];
  Object? _error;
  final _text = TextEditingController();
  final _math = TextEditingController();
  List<Map<String, dynamic>> _drawing = [];
  final List<Map<String, dynamic>> _files = [];
  Timer? _saveTimer;
  String _saveState = '';
  bool _dirty = false;

  String get _key => 'draft.assign.${widget.id}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _saveTimer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await ref.read(apiProvider).get('/assignments/${widget.id}');
      _a = Map<String, dynamic>.from(r['assignment']);
      _attachments = ((r['attachments'] as List?) ?? []).map((e) => e as int).toList();
      _sub = r['my_submission'] == null ? null : Map<String, dynamic>.from(r['my_submission']);
      // Server draft wins; otherwise restore the locally cached one (survives refresh / offline).
      _text.text = _sub?['text_answer'] ?? '';
      _math.text = (_sub?['math'] as Map?)?['text'] ?? '';
      _drawing = ((_sub?['drawing'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      final p = await SharedPreferences.getInstance();
      final local = p.getString(_key);
      if (local != null && (_sub == null || _sub!['status'] == 'viewed' || _sub!['status'] == 'in_progress' || _sub!['status'] == 'needs_revision')) {
        final m = jsonDecode(local) as Map<String, dynamic>;
        if ((m['text'] as String).isNotEmpty && _text.text.isEmpty) _text.text = m['text'];
      }
      setState(() => _error = null);
    } catch (e) {
      setState(() => _error = e);
    }
  }

  bool get _locked => _sub?['status'] == 'finalized' || _a?['status'] == 'closed' || (!(_a?['allow_resubmit'] ?? true) && ['submitted', 'late', 'under_review'].contains(_sub?['status']));
  List<String> get _types => ((_a?['answer_types'] as List?) ?? []).cast<String>();

  void _changed() {
    _dirty = true;
    SharedPreferences.getInstance().then((p) => p.setString(_key, jsonEncode({'text': _text.text})));
    if (_a?['allow_draft'] != true) return;
    _saveTimer?.cancel();
    setState(() => _saveState = 'در حال ذخیره...');
    _saveTimer = Timer(const Duration(milliseconds: 2500), _saveDraft);
  }

  Map<String, dynamic> get _payload => {
        if (_types.contains('text')) 'text_answer': _text.text.isEmpty ? null : _text.text,
        if (_types.contains('drawing') && _drawing.isNotEmpty) 'drawing': _drawing,
        if (_types.contains('math') && _math.text.isNotEmpty) 'math': {'text': _math.text},
      };

  Future<void> _saveDraft() async {
    try {
      final r = await ref.read(apiProvider).put('/assignments/${widget.id}/draft', data: _payload);
      _sub = Map<String, dynamic>.from(r['data']);
      _dirty = false;
      if (mounted) setState(() => _saveState = 'پیش‌نویس ذخیره شد');
    } on ApiException catch (e) {
      if (mounted) setState(() => _saveState = e.isOffline ? 'آفلاین — پس از اتصال دوباره ذخیره می‌شود' : e.readable);
      if (e.isOffline) _saveTimer = Timer(const Duration(seconds: 8), _saveDraft);
    }
  }

  Future<void> _submit() async {
    _saveTimer?.cancel();
    final r = await ref.read(apiProvider).post('/assignments/${widget.id}/submit', data: {..._payload, 'file_ids': [for (final f in _files) f['id']]});
    _sub = Map<String, dynamic>.from(r['data']);
    final p = await SharedPreferences.getInstance();
    await p.remove(_key);
    _files.clear();
    await _load();
    if (mounted) toast(context, 'پاسخ شما ارسال شد.');
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) return Scaffold(appBar: AppBar(title: const Text('تکلیف')), body: ErrorView(_error!, onRetry: _load));
    if (_a == null) return Scaffold(appBar: AppBar(title: const Text('تکلیف')), body: const Center(child: CircularProgressIndicator()));
    final a = _a!;
    final status = (_sub?['status'] ?? 'viewed') as String;
    final due = a['due_at'] == null ? null : DateTime.tryParse('${a['due_at']}')?.toLocal();
    final left = due?.difference(serverNow(ref));
    return Scaffold(
      appBar: AppBar(title: Text('${a['title']}'), leading: BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/student/assignments'))),
      body: PageBody(maxWidth: 820, children: [
        AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [Expanded(child: Text('${a['title']}', style: Theme.of(context).textTheme.titleLarge)), StatusChip(statusName(status), tone: _tone(status))]),
          const SizedBox(height: 8),
          if ((a['description'] ?? '').toString().isNotEmpty) SelectableText('${a['description']}'),
          const SizedBox(height: 8),
          Wrap(spacing: 16, children: [
            Text('بارم: ${fmtNum(a['max_score'])}', style: Theme.of(context).textTheme.bodySmall),
            if (due != null) Text('مهلت: ${fmtDate(a['due_at'], withTime: true)}${left != null && !left.isNegative ? ' (${fmtDuration(left)} مانده)' : ' (گذشته)'}', style: TextStyle(color: (left?.isNegative ?? false) ? Palette.danger : Palette.muted, fontSize: 12.5)),
          ]),
          if (_attachments.isNotEmpty) ...[const Divider(), const Text('فایل‌های معلم'), const SizedBox(height: 6), Wrap(spacing: 8, runSpacing: 8, children: [for (final f in _attachments) FileChip(f)])],
          if ((a['rubric'] as List?)?.isNotEmpty ?? false) ...[const Divider(), const Text('معیار ارزیابی'), for (final r in a['rubric'] as List) Text('• ${r['title']} (${fmtNum(r['max'])})', style: Theme.of(context).textTheme.bodySmall)],
        ])),
        if (_sub?['feedback'] != null || _sub?['score'] != null) ...[
          const SizedBox(height: 12),
          AppCard(color: status == 'needs_revision' ? Palette.warnSoft : Palette.successSoft, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(status == 'needs_revision' ? 'معلم برای اصلاح بازگردانده است' : 'بازخورد معلم', style: const TextStyle(fontWeight: FontWeight.w700)),
            if (_sub?['score'] != null) Text('نمره: ${fmtNum(_sub!['score'])} از ${fmtNum(a['max_score'])}'),
            if (_sub?['feedback'] != null) Text('${_sub!['feedback']}'),
          ])),
        ],
        const SizedBox(height: 12),
        if (_locked)
          const AppCard(child: Text('این تکلیف نهایی شده یا بسته است و ویرایش نمی‌شود.'))
        else
          AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('پاسخ شما', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            if (_types.contains('text')) TextField(key: const Key('answer-text'), controller: _text, minLines: 5, maxLines: 14, onChanged: (_) => _changed(), decoration: const InputDecoration(hintText: 'پاسخ را اینجا بنویسید...')),
            if (_types.contains('math')) ...[const SizedBox(height: 12), const Text('پاسخ ریاضی'), const SizedBox(height: 6), MathPad(controller: _math, onChanged: _changed)],
            if (_types.contains('drawing')) ...[const SizedBox(height: 12), const Text('رسم / نقاشی'), const SizedBox(height: 6), DrawingPad(initial: _drawing, onChanged: (d) { _drawing = d; _changed(); })],
            if (_types.any((t) => ['file', 'audio', 'video'].contains(t))) ...[
              const SizedBox(height: 12),
              Wrap(spacing: 8, runSpacing: 8, children: [
                OutlinedButton.icon(key: const Key('attach-file'), onPressed: () async { final f = await pickAndUpload(context, ref); setState(() => _files.addAll(f)); }, icon: const Icon(Icons.upload_file), label: Text(_types.contains('audio') || _types.contains('video') ? 'بارگذاری عکس / PDF / صوت / ویدئو' : 'بارگذاری عکس یا PDF')),
              ]),
              if (_files.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: UploadList(files: _files, onRemove: (f) => setState(() => _files.remove(f)))),
              if ((_sub?['files'] as List?)?.isNotEmpty ?? false) Padding(padding: const EdgeInsets.only(top: 8), child: Wrap(spacing: 8, runSpacing: 8, children: [for (final f in _sub!['files'] as List) FileChip(f['file_id'] as int)])),
            ],
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: Text(_saveState, style: Theme.of(context).textTheme.bodySmall)),
              if (a['allow_draft'] == true) TextButton(onPressed: _dirty ? _saveDraft : null, child: const Text('ذخیره پیش‌نویس')),
              ActionButton(label: ['submitted', 'late', 'under_review', 'needs_revision'].contains(status) ? 'ارسال مجدد' : 'ارسال پاسخ', icon: Icons.send, onPressed: _submit),
            ]),
          ])),
      ]),
    );
  }
}
