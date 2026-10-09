import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/forms.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import 'teacher_common.dart';

/// Grade book: enter continuous / oral / project grades per student, see statuses, change approved grades only with a reason.
class GradeBook extends ConsumerStatefulWidget {
  const GradeBook({super.key, this.approver = false});
  final bool approver; // deputy/admin: can approve & edit approved grades
  @override
  ConsumerState<GradeBook> createState() => _GradeBookState();
}

class _GradeBookState extends ConsumerState<GradeBook> {
  Map<String, dynamic> _q = {};
  final _list = GlobalKey<PagedListState>();

  Future<void> _enter() async {
    final t = await ref.read(teachingProvider.future);
    if (!mounted) return;
    final pairId = await showDialog<int>(context: context, builder: (c) => SimpleDialog(title: const Text('ثبت نمره برای...'), children: [for (final x in t) SimpleDialogOption(onPressed: () => Navigator.pop(c, x['id'] as int), child: Text('${x['label']}'))]));
    if (pairId == null || !mounted) return;
    final pair = pairById(t, pairId)!;
    final studs = ((await ref.read(apiProvider).get('/academics/students', query: {'section_id': pair['section_id'], 'per_page': 100}))['data'] as List).cast<Map>();
    if (!mounted) return;
    final ok = await showForm(context, title: 'نمرهٔ جدید — ${pair['label']}', fields: [
      FieldSpec('student_id', 'دانش‌آموز', type: FieldType.dropdown, required: true, options: [for (final s in studs) Option(s['id'] as int, '${s['first_name']} ${s['last_name']}')]),
      const FieldSpec('term_id', 'ترم', type: FieldType.dropdown, required: true, optionsFrom: '/academics/terms', labelKey: 'title'),
      FieldSpec('kind', 'نوع', type: FieldType.dropdown, required: true, initial: 'classwork', options: [for (final k in const ['classwork', 'oral', 'project', 'final']) Option(k, tr('kind.$k'))]),
      const FieldSpec('title', 'عنوان', required: true), const FieldSpec('score', 'نمره', type: FieldType.number, required: true), const FieldSpec('max_score', 'از', type: FieldType.number, initial: 20),
    ], submit: (v) async => ref.read(apiProvider).post('/grades', data: {...v, 'section_id': pair['section_id'], 'subject_id': pair['subject_id']}));
    if (ok) _list.currentState?.reload();
  }

  Future<void> _edit(Map<String, dynamic> g) async {
    final approved = g['status'] == 'approved';
    final ok = await showForm(context, title: 'تغییر نمره', initial: {'score': g['score']}, fields: [
      const FieldSpec('score', 'نمرهٔ جدید', type: FieldType.number, required: true),
      if (approved) const FieldSpec('reason', 'دلیل تغییر (الزامی برای نمرهٔ تأییدشده)', type: FieldType.multiline, required: true),
    ], submit: (v) async => ref.read(apiProvider).patch('/grades/${g['id']}', data: v));
    if (ok) _list.currentState?.reload();
  }

  Future<void> _history(Map<String, dynamic> g) async {
    final r = await ref.read(apiProvider).get('/grades/${g['id']}/history');
    if (!mounted) return;
    showDialog(context: context, builder: (c) => AlertDialog(title: const Text('تاریخچهٔ تغییرات نمره'), content: SizedBox(width: 460, child: ListView(shrinkWrap: true, children: [for (final h in r['data'] as List) ListTile(dense: true, title: Text('${fmtNum(h['old_score'])} → ${fmtNum(h['new_score'])}  (${h['old_status'] ?? '—'} → ${h['new_status'] ?? '—'})'), subtitle: Text('${h['reason'] ?? ''}  ·  ${fmtDate(h['created_at'], withTime: true)}  ·  کاربر ${faDigits(h['changed_by'])}'))])), actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('بستن'))]));
  }

  @override
  Widget build(BuildContext context) => PageBody(children: [
        PageHeader('دفتر نمرات', actions: [
          if (!widget.approver) FilledButton.icon(key: const Key('enter-grade'), onPressed: _enter, icon: const Icon(Icons.add), label: const Text('ثبت نمره')),
          if (widget.approver) FilledButton.icon(key: const Key('approve-all'), onPressed: () async {
                final sec = await showForm(context, title: 'تأیید گروهی نمرات', fields: const [FieldSpec('section_id', 'کلاس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/sections', optionLabel: _sectionLabel), FieldSpec('term_id', 'ترم', type: FieldType.dropdown, required: true, optionsFrom: '/academics/terms', labelKey: 'title')],
                    submit: (v) async { final r = await ref.read(apiProvider).post('/grades/approve-bulk', data: v); if (mounted) toast(context, '${faDigits(r['approved'])} نمره تأیید شد.'); }, submitLabel: 'تأیید همه');
                if (sec) _list.currentState?.reload();
              }, icon: const Icon(Icons.done_all), label: const Text('تأیید گروهی')),
        ]),
        Wrap(spacing: 8, children: [for (final s in const [('draft', 'پیش‌نویس'), ('approved', 'تأییدشده')]) FilterChip(label: Text(s.$2), selected: _q['status'] == s.$1, onSelected: (on) => setState(() => _q = {..._q, 'status': on ? s.$1 : null}))]),
        const SizedBox(height: 10),
        PagedList(key: _list, path: '/grades', query: _q, emptyText: 'نمره‌ای ثبت نشده است.', itemBuilder: (c, g, st) => AppCard(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10), child: Row(children: [
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${g['title']}'), Text('دانش‌آموز ${faDigits(g['student_id'])} · ${tr('kind.${g['kind']}')}', style: Theme.of(c).textTheme.bodySmall)])),
              Text('${fmtNum(g['score'])} / ${fmtNum(g['max_score'])}', style: Theme.of(c).textTheme.titleMedium?.copyWith(color: Palette.brand)),
              const SizedBox(width: 8), StatusChip(statusName(g['status'] as String), tone: g['status'] == 'approved' ? Tone.success : Tone.warn),
              if (g['status'] == 'draft' && widget.approver) IconButton(tooltip: 'تأیید', icon: const Icon(Icons.check_circle_outline, color: Palette.success), onPressed: () async { await ref.read(apiProvider).post('/grades/${g['id']}/approve'); st.reload(); }),
              if (g['status'] == 'draft' || widget.approver) IconButton(tooltip: 'ویرایش', icon: const Icon(Icons.edit_outlined), onPressed: () => _edit(g)),
              IconButton(tooltip: 'تاریخچه', icon: const Icon(Icons.history), onPressed: () => _history(g)),
            ]))),
      ]);
}

String? _sectionLabel(Map<String, dynamic> m) => '${(m['grade'] as Map?)?['name'] ?? ''} ${m['name']}'.trim();
