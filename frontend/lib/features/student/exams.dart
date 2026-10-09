import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import '../common/files_ui.dart';
import '../common/notifications.dart';

class ExamsList extends ConsumerWidget {
  const ExamsList({super.key, this.studentId});
  final int? studentId;
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return PageBody(children: [
      const PageHeader('آزمون‌ها'),
      PagedList(
        path: '/exams', emptyText: 'آزمونی وجود ندارد.',
        itemBuilder: (c, e, st) {
          final start = DateTime.tryParse('${e['start_at']}')?.toLocal();
          final end = DateTime.tryParse('${e['end_at']}')?.toLocal();
          final now = serverNow(ref);
          final open = start != null && end != null && now.isAfter(start) && now.isBefore(end);
          final over = end != null && now.isAfter(end);
          return AppCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                const Icon(Icons.quiz_outlined, color: Palette.brand),
                const SizedBox(width: 10),
                Expanded(child: Text('${e['title']}', style: Theme.of(c).textTheme.titleMedium)),
                StatusChip(open ? 'باز است' : (over ? 'پایان‌یافته' : 'در انتظار شروع'), tone: open ? Tone.success : Tone.neutral),
              ]),
              const SizedBox(height: 6),
              Text('از ${fmtDate(e['start_at'], withTime: true)} تا ${fmtDate(e['end_at'], withTime: true)}  ·  مدت: ${faDigits(e['duration_minutes'])} دقیقه  ·  دفعات مجاز: ${faDigits(e['max_attempts'])}', style: Theme.of(c).textTheme.bodySmall),
              if (studentId == null) ...[
                const SizedBox(height: 10),
                Wrap(spacing: 8, children: [
                  if (open) FilledButton(key: Key('start-exam-${e['id']}'), onPressed: () => _start(c, ref, e['id'] as int), child: const Text('شروع / ادامهٔ آزمون')),
                  if (over || !open) OutlinedButton(onPressed: () => _result(c, ref, e['id'] as int), child: const Text('نتیجه')),
                ]),
              ],
            ]),
          );
        },
      ),
    ]);
  }

  Future<void> _start(BuildContext context, WidgetRef ref, int examId) async {
    if (!await confirm(context, 'آزمون با زمان‌سنج سرور شروع می‌شود و پس از پایان زمان به‌طور خودکار ثبت می‌شود. شروع کنیم؟', ok: 'شروع')) return;
    try {
      final r = await ref.read(apiProvider).post('/exams/$examId/start');
      if (context.mounted) context.push('/exam/${r['attempt']['id']}');
    } on ApiException catch (e) {
      if (context.mounted) toast(context, e.readable, error: true);
    }
  }

  Future<void> _result(BuildContext context, WidgetRef ref, int examId) async {
    try {
      final r = await ref.read(apiProvider).get('/exams/$examId/my-result');
      if (!context.mounted) return;
      showDialog(context: context, builder: (c) => AlertDialog(
            title: const Text('نتیجهٔ آزمون'),
            content: SizedBox(width: 460, child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (r['score_visible'] == true) Text('نمره: ${fmtNum(r['total_score'])} از ${fmtNum(r['max_score'])}', style: Theme.of(c).textTheme.titleLarge)
              else const Text('نتیجه هنوز توسط معلم/مدرسه منتشر نشده است.'),
              if (r['answers_visible'] == true) ...[const Divider(), for (final a in (r['review'] as List)) Text('سؤال ${a['question_id']}: امتیاز ${fmtNum(a['score'])}${a['feedback'] != null ? ' — ${a['feedback']}' : ''}')] else const Padding(padding: EdgeInsets.only(top: 8), child: Text('پاسخ‌های صحیح پس از زمان مجاز نمایش داده می‌شوند.', style: TextStyle(color: Palette.muted))),
            ]))),
            actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('بستن'))],
          ));
    } on ApiException catch (e) {
      if (context.mounted) toast(context, e.code == null && e.status == 404 ? 'هنوز در این آزمون شرکت نکرده‌اید.' : e.readable);
    }
  }
}

/// Full-screen exam. The server deadline is authoritative: the countdown is derived from it (corrected by the server clock offset);
/// every change is auto-saved; if the connection drops the answers are kept locally and re-sent; refreshing resumes the same attempt.
class ExamTakeScreen extends ConsumerStatefulWidget {
  const ExamTakeScreen({super.key, required this.attemptId});
  final int attemptId;
  @override
  ConsumerState<ExamTakeScreen> createState() => _ExamTakeState();
}

class _ExamTakeState extends ConsumerState<ExamTakeScreen> {
  Map<String, dynamic>? _data;
  Object? _error;
  final Map<int, Map<String, dynamic>> _answers = {}; // question_id → answer
  final Set<int> _dirty = {};
  int _index = 0;
  Timer? _tick, _save;
  DateTime? _deadline;
  Duration _skew = Duration.zero;
  bool _offline = false, _submitting = false, _done = false;
  final Map<int, TextEditingController> _tc = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _tick?.cancel();
    _save?.cancel();
    for (final c in _tc.values) { c.dispose(); }
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await ref.read(apiProvider).get('/exam-attempts/${widget.attemptId}');
      _data = Map<String, dynamic>.from(r);
      _deadline = DateTime.parse('${r['attempt']['deadline_at']}').toLocal();
      _skew = DateTime.parse('${r['server_time']}').difference(DateTime.now());
      for (final q in r['questions'] as List) {
        final sa = q['saved_answer'];
        if (sa != null && !_answers.containsKey(q['id'])) _answers[q['id'] as int] = Map<String, dynamic>.from(sa as Map);
      }
      _error = null;
      _tick ??= Timer.periodic(const Duration(seconds: 1), (_) { if (mounted) setState(() {}); _checkTime(); });
      if (r['attempt']['status'] != 'in_progress') _done = true;
    } catch (e) {
      _error = e;
    }
    if (mounted) setState(() {});
  }

  Duration get _left => _deadline == null ? Duration.zero : _deadline!.difference(DateTime.now().add(_skew));

  void _checkTime() {
    if (_done || _submitting || _deadline == null) return;
    if (_left.inSeconds <= 0) _submit(auto: true);
  }

  void _set(int qid, Map<String, dynamic> a) {
    _answers[qid] = a;
    _dirty.add(qid);
    setState(() {});
    _save?.cancel();
    _save = Timer(const Duration(milliseconds: 1200), _flush);
  }

  /// Sends unsaved answers; on failure keeps them and retries (offline tolerance).
  Future<void> _flush() async {
    if (_dirty.isEmpty || _done) return;
    final ids = _dirty.toList();
    try {
      await ref.read(apiProvider).put('/exam-attempts/${widget.attemptId}/answers', data: {'answers': [for (final id in ids) {'question_id': id, 'answer': _answers[id]}]});
      _dirty.removeAll(ids);
      if (_offline && mounted) setState(() => _offline = false);
    } on ApiException catch (e) {
      if (e.isOffline) {
        if (mounted) setState(() => _offline = true);
        _save?.cancel();
        _save = Timer(const Duration(seconds: 4), _flush);
      } else if (e.status == 422) {
        await _load(); // attempt closed server-side (deadline)
      }
    }
  }

  Future<void> _submit({bool auto = false}) async {
    if (_submitting) return;
    if (!auto && !await confirm(context, 'پاسخ‌ها نهایی ارسال شود؟ پس از ارسال امکان ویرایش نیست.', ok: 'ارسال نهایی')) return;
    setState(() => _submitting = true);
    for (var i = 0; i < 4; i++) {
      try {
        await ref.read(apiProvider).post('/exam-attempts/${widget.attemptId}/submit', data: {'answers': [for (final id in _answers.keys) {'question_id': id, 'answer': _answers[id]}]});
        _done = true;
        break;
      } on ApiException catch (e) {
        if (!e.isOffline) { if (mounted) toast(context, e.readable, error: true); _done = e.status == 422; break; }
        await Future<void>.delayed(const Duration(seconds: 3)); // keep trying while offline until the server clock ends it
      }
    }
    _tick?.cancel();
    if (mounted) setState(() => _submitting = false);
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) return Scaffold(appBar: AppBar(title: const Text('آزمون')), body: ErrorView(_error!, onRetry: _load));
    if (_data == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    if (_done) {
      return Scaffold(appBar: AppBar(title: Text('${_data!['exam']['title']}')), body: Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Icon(Icons.check_circle, color: Palette.success, size: 64), const SizedBox(height: 12), const Text('پاسخ‌های شما ثبت شد.'),
        const SizedBox(height: 12), FilledButton(key: const Key('exam-done-back'), onPressed: () => context.go('/student/exams'), child: const Text('بازگشت به آزمون‌ها')),
      ])));
    }
    final qs = (_data!['questions'] as List).cast<Map>();
    final q = Map<String, dynamic>.from(qs[_index]);
    final low = _left.inSeconds < 120;
    return PopScope(
      canPop: false,
      child: Scaffold(
        appBar: AppBar(title: Text('${_data!['exam']['title']}'), automaticallyImplyLeading: false, actions: [
          if (_offline) const Padding(padding: EdgeInsets.symmetric(horizontal: 8), child: Chip(avatar: Icon(Icons.wifi_off, size: 16), label: Text('آفلاین — پاسخ‌ها نگه داشته می‌شوند'))),
          Container(margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 10), padding: const EdgeInsets.symmetric(horizontal: 14), alignment: Alignment.center, decoration: BoxDecoration(color: low ? Palette.dangerSoft : Palette.brandSoft, borderRadius: BorderRadius.circular(20)),
              child: Text(fmtDuration(_left.isNegative ? Duration.zero : _left), key: const Key('exam-timer'), style: TextStyle(color: low ? Palette.danger : Palette.brand, fontWeight: FontWeight.w700))),
        ]),
        body: Row(children: [
          if (MediaQuery.sizeOf(context).width >= 760) SizedBox(width: 120, child: ListView(padding: const EdgeInsets.all(8), children: [for (var i = 0; i < qs.length; i++) _navButton(i, qs)])),
          Expanded(child: PageBody(maxWidth: 780, children: [
            if (MediaQuery.sizeOf(context).width < 760) SizedBox(height: 46, child: ListView(scrollDirection: Axis.horizontal, children: [for (var i = 0; i < qs.length; i++) Padding(padding: const EdgeInsets.only(left: 6), child: _navButton(i, qs, compact: true))])),
            AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [Text('سؤال ${faDigits(_index + 1)} از ${faDigits(qs.length)}', style: Theme.of(context).textTheme.bodySmall), const Spacer(), StatusChip('${fmtNum(q['points'])} نمره', tone: Tone.neutral)]),
              const SizedBox(height: 8),
              Text('${q['body']}', style: Theme.of(context).textTheme.titleMedium),
              if (q['media_file_id'] != null) Padding(padding: const EdgeInsets.only(top: 10), child: FileChip(q['media_file_id'] as int)),
              const SizedBox(height: 14),
              _answerWidget(q),
            ])),
            const SizedBox(height: 12),
            Row(children: [
              OutlinedButton(onPressed: _index > 0 ? () => setState(() => _index--) : null, child: const Text('قبلی')),
              const SizedBox(width: 8),
              OutlinedButton(key: const Key('exam-next'), onPressed: _index < qs.length - 1 ? () => setState(() => _index++) : null, child: const Text('بعدی')),
              const Spacer(),
              FilledButton.icon(key: const Key('exam-submit'), onPressed: _submitting ? null : () => _submit(), icon: const Icon(Icons.done_all), label: const Text('ارسال نهایی')),
            ]),
          ])),
        ]),
      ),
    );
  }

  Widget _navButton(int i, List<Map> qs, {bool compact = false}) {
    final answered = _answers.containsKey(qs[i]['id']) && (_answers[qs[i]['id']]?.isNotEmpty ?? false);
    return InkWell(
      onTap: () => setState(() => _index = i),
      borderRadius: BorderRadius.circular(10),
      child: Container(width: compact ? 42 : null, margin: compact ? null : const EdgeInsets.only(bottom: 6), padding: const EdgeInsets.all(8), alignment: Alignment.center,
          decoration: BoxDecoration(color: i == _index ? Palette.brand : (answered ? Palette.successSoft : Colors.white), borderRadius: BorderRadius.circular(10), border: Border.all(color: Palette.border)),
          child: Text(faDigits(i + 1), style: TextStyle(color: i == _index ? Colors.white : Palette.text))),
    );
  }

  Widget _answerWidget(Map<String, dynamic> q) {
    final id = q['id'] as int;
    switch (q['type']) {
      case 'mcq':
        final sel = ((_answers[id]?['option_ids'] as List?) ?? []).cast<int>();
        final multi = q['multiple'] == true;
        return Column(children: [
          for (final o in (q['options'] as List))
            Container(
              margin: const EdgeInsets.only(bottom: 8),
              decoration: BoxDecoration(border: Border.all(color: sel.contains(o['id']) ? Palette.brand : Palette.border), borderRadius: BorderRadius.circular(12), color: sel.contains(o['id']) ? Palette.brandSoft : null),
              child: multi
                  ? CheckboxListTile(value: sel.contains(o['id']), title: Text('${o['text']}'), onChanged: (v) { final n = [...sel]; v == true ? n.add(o['id'] as int) : n.remove(o['id']); _set(id, {'option_ids': n}); })
                  : ListTile(key: Key('opt-${o['id']}'), leading: Icon(sel.contains(o['id']) ? Icons.radio_button_checked : Icons.radio_button_off, color: Palette.brand), title: Text('${o['text']}'), onTap: () => _set(id, {'option_ids': [o['id']]})),
            ),
          if (multi) const Text('می‌توانید چند گزینه را انتخاب کنید.', style: TextStyle(color: Palette.muted)),
        ]);
      case 'tf':
        final v = _answers[id]?['value'];
        return Row(children: [
          for (final o in const [(true, 'درست'), (false, 'غلط')])
            Expanded(child: Padding(padding: const EdgeInsets.all(4), child: ChoiceChip(showCheckmark: false, label: SizedBox(width: double.infinity, child: Center(child: Text(o.$2))), selected: v == o.$1, onSelected: (_) => _set(id, {'value': o.$1})))),
        ]);
      default:
        final c = _tc.putIfAbsent(id, () => TextEditingController(text: (_answers[id]?['text'] ?? '') as String));
        return TextField(key: Key('answer-$id'), controller: c, minLines: q['type'] == 'essay' ? 6 : 1, maxLines: q['type'] == 'essay' ? 14 : 2, onChanged: (t) => _set(id, {'text': t}), decoration: InputDecoration(hintText: q['type'] == 'essay' ? 'پاسخ تشریحی خود را بنویسید...' : 'پاسخ'));
    }
  }
}
