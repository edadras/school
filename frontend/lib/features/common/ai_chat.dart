import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/api.dart';
import '../../core/session.dart';
import '../../design/forms.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

class _Turn {
  _Turn(this.role, this.text, {this.sources = const [], this.error = false});
  final String role; // user | assistant
  final String text;
  final List<Map<String, dynamic>> sources;
  final bool error;
}

/// AI assistant. Students: tutor (guides, doesn't hand out homework answers). Teachers: chat + teaching tools.
/// Every response shows the approved school sources it used; failures and "not configured" states are explicit.
class AiScreen extends ConsumerStatefulWidget {
  const AiScreen({super.key, this.teacher = false});
  final bool teacher;
  @override
  ConsumerState<AiScreen> createState() => _AiState();
}

class _AiState extends ConsumerState<AiScreen> {
  final _c = TextEditingController();
  final List<_Turn> _turns = [];
  bool _busy = false, _sources = true;
  Map<String, dynamic>? _status;

  @override
  void initState() {
    super.initState();
    ref.read(apiProvider).get('/ai/status').then((r) => mounted ? setState(() => _status = Map<String, dynamic>.from(r)) : null).catchError((_) {});
  }

  Future<void> _ask() async {
    final t = _c.text.trim();
    if (t.isEmpty || _busy) return;
    setState(() { _turns.add(_Turn('user', t)); _busy = true; _c.clear(); });
    try {
      final r = await ref.read(apiProvider).post('/ai/ask', data: {'message': t, 'use_sources': _sources});
      setState(() => _turns.add(_Turn('assistant', r['text'] as String, sources: ((r['sources'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList())));
    } on ApiException catch (e) {
      setState(() => _turns.add(_Turn('assistant', switch (e.code) {
            'ai_unconfigured' => 'سرویس هوش مصنوعی هنوز توسط مدیر سامانه پیکربندی نشده است.',
            'ai_disabled_by_school' => 'دستیار هوشمند توسط مدرسهٔ شما فعال نشده است.',
            _ => e.readable,
          }, error: true)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _tool(String kind) async {
    final api = ref.read(apiProvider);
    Map<String, dynamic>? out;
    try {
      switch (kind) {
        case 'plan':
          await showForm(context, title: 'طرح درس', submitLabel: 'تولید', fields: const [
            FieldSpec('section_id', 'کلاس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/sections'),
            FieldSpec('subject_id', 'درس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/subjects'),
            FieldSpec('topic', 'موضوع', required: true), FieldSpec('minutes', 'مدت (دقیقه)', type: FieldType.number, initial: 45),
          ], submit: (v) async { out = Map<String, dynamic>.from(await api.post('/ai/teacher/lesson-plan', data: v)); });
          if (out != null) setState(() => _turns.add(_Turn('assistant', out!['text'] as String, sources: ((out!['sources'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList())));
        case 'questions':
          await showForm(context, title: 'پیش‌نویس سؤال', submitLabel: 'تولید', fields: const [
            FieldSpec('subject_id', 'درس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/subjects'),
            FieldSpec('topic', 'مبحث', required: true), FieldSpec('count', 'تعداد', type: FieldType.number, required: true, initial: 5),
            FieldSpec('difficulty', 'دشواری (۱ تا ۵)', type: FieldType.number, initial: 3),
          ], submit: (v) async { out = Map<String, dynamic>.from(await api.post('/ai/teacher/questions', data: v)); });
          if (out != null) setState(() => _turns.add(_Turn('assistant', 'پیش‌نویس سؤال‌ها (پس از بازبینی در بانک سؤال ثبت کنید):\n\n${(out!['questions'] as List).map((q) => '• [${q['type']}] ${q['body']}').join('\n')}')));
        case 'remedial':
          await showForm(context, title: 'تمرین جبرانی بر پایهٔ ضعف‌های واقعی', submitLabel: 'تحلیل', fields: const [
            FieldSpec('section_id', 'کلاس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/sections'),
            FieldSpec('subject_id', 'درس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/subjects'),
          ], submit: (v) async { out = Map<String, dynamic>.from(await api.post('/ai/teacher/remedial', data: v)); });
          if (out != null) setState(() => _turns.add(_Turn('assistant', out!['suggestion'] == null ? (out!['note'] ?? 'داده‌ٔ کافی نیست.') : '${(out!['weak_topics'] as List).map((w) => '${w['topic']} (${w['rate']}٪)').join('، ')}\n\n${out!['suggestion']}\n\n(پیشنهاد؛ نیازمند تأیید معلم)')));
      }
    } on ApiException catch (e) {
      if (mounted) toast(context, e.code == 'ai_unconfigured' ? 'سرویس هوش مصنوعی پیکربندی نشده است.' : e.readable, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final configured = _status?['configured'] == true;
    final enabled = _status?['enabled_for_school'] == true;
    return Column(children: [
      if (_status != null && (!configured || !enabled))
        Container(width: double.infinity, color: Palette.warnSoft, padding: const EdgeInsets.all(10), child: Text(!configured ? 'سرویس هوش مصنوعی هنوز پیکربندی نشده است؛ این بخش تا اتصال سرویس کار نمی‌کند.' : 'دستیار هوشمند در تنظیمات مدرسه فعال نشده است.', textAlign: TextAlign.center)),
      if (widget.teacher) SingleChildScrollView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.all(8), child: Row(children: [
        ActionChip(avatar: const Icon(Icons.menu_book, size: 18), label: const Text('طرح درس'), onPressed: () => _tool('plan')), const SizedBox(width: 8),
        ActionChip(avatar: const Icon(Icons.quiz, size: 18), label: const Text('تولید سؤال'), onPressed: () => _tool('questions')), const SizedBox(width: 8),
        ActionChip(avatar: const Icon(Icons.healing, size: 18), label: const Text('تمرین جبرانی'), onPressed: () => _tool('remedial')),
      ])),
      Expanded(child: _turns.isEmpty
          ? EmptyState(widget.teacher ? 'از دستیار برای طرح درس، سؤال و تمرین کمک بگیرید. خروجی‌ها فقط پیشنهادند.' : 'هر سؤال درسی دارید بپرسید. دستیار راهنمایی می‌کند، نه جواب‌دادن تکلیف.', icon: Icons.auto_awesome_outlined)
          : ListView(padding: const EdgeInsets.all(16), children: [
              for (final t in _turns)
                Align(alignment: t.role == 'user' ? AlignmentDirectional.centerStart : AlignmentDirectional.centerEnd, child: Container(
                  constraints: const BoxConstraints(maxWidth: 640), margin: const EdgeInsets.only(bottom: 10), padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: t.error ? Palette.dangerSoft : (t.role == 'user' ? Palette.brandSoft : Colors.white), borderRadius: BorderRadius.circular(14), border: Border.all(color: Palette.border)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    SelectableText(t.text),
                    if (t.sources.isNotEmpty) ...[const Divider(), Wrap(spacing: 6, children: [const Text('منابع مدرسه:', style: TextStyle(fontSize: 12, color: Palette.muted)), for (final s in t.sources) Chip(visualDensity: VisualDensity.compact, avatar: const Icon(Icons.menu_book_outlined, size: 14), label: Text('${s['title']}', style: const TextStyle(fontSize: 12)))])],
                  ]),
                )),
              if (_busy) const Padding(padding: EdgeInsets.all(8), child: LinearProgressIndicator()),
            ])),
      Container(
        padding: const EdgeInsets.all(10), color: Colors.white,
        child: Column(children: [
          Row(children: [Checkbox(value: _sources, onChanged: (v) => setState(() => _sources = v ?? true)), const Expanded(child: Text('استفاده از منابع تأییدشدهٔ مدرسه (با ارجاع)')), TextButton(onPressed: () async { final r = await ref.read(apiProvider).delete('/ai/my-data'); if (mounted) toast(context, '${r['deleted']} سابقه حذف شد.'); }, child: const Text('حذف سوابق من'))]),
          Row(children: [
            Expanded(child: TextField(key: const Key('ai-input'), controller: _c, onSubmitted: (_) => _ask(), decoration: const InputDecoration(hintText: 'سؤال خود را بنویسید...'))),
            const SizedBox(width: 8),
            IconButton.filled(key: const Key('ai-send'), onPressed: _busy ? null : _ask, icon: const Icon(Icons.send)),
          ]),
        ]),
      ),
    ]);
  }
}
