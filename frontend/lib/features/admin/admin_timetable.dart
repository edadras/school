import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/forms.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

final _timetablesProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final r = await ref.read(apiProvider).get('/timetables');
  return [for (final t in (r['data']['data'] as List)) Map<String, dynamic>.from(t as Map)];
});

final _sectionsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final r = await ref.read(apiProvider).get('/academics/sections', query: {'per_page': 100});
  return [for (final s in r['data'] as List) Map<String, dynamic>.from(s as Map)];
});

/// Weekly timetable editor: bell periods, per-class grid with live conflict detection, activation (versioned), substitutions.
class AdminTimetable extends ConsumerStatefulWidget {
  const AdminTimetable({super.key});
  @override
  ConsumerState<AdminTimetable> createState() => _AdminTimetableState();
}

class _AdminTimetableState extends ConsumerState<AdminTimetable> {
  int? _ttId, _sectionId;
  Map<String, dynamic>? _tt;
  Map<String, dynamic> _lookup = {};
  List<Map<String, dynamic>> _teaching = [];
  Object? _error;

  Future<void> _load() async {
    if (_ttId == null) return;
    try {
      final api = ref.read(apiProvider);
      final r = await api.get('/timetables/$_ttId');
      final t = await api.get('/me/teaching');
      setState(() { _tt = Map<String, dynamic>.from(r['data']); _lookup = Map<String, dynamic>.from(r['lookup']); _teaching = [for (final x in t['data'] as List) Map<String, dynamic>.from(x as Map)]; _error = null; });
    } catch (e) {
      setState(() => _error = e);
    }
  }

  Future<void> _create() async {
    final years = ((await ref.read(apiProvider).get('/academics/academic-years'))['data'] as List).cast<Map>();
    if (!mounted) return;
    final res = await showDialog<bool>(context: context, builder: (_) => _NewTimetableDialog(years: [for (final y in years) Option(y['id'] as int, '${y['title']}')]));
    if (res == true) { ref.invalidate(_timetablesProvider); }
  }

  Future<void> _cell(Map<String, dynamic> period, int day, Map<String, dynamic>? existing) async {
    if (_sectionId == null) { toast(context, 'ابتدا یک کلاس را انتخاب کنید.', error: true); return; }
    final api = ref.read(apiProvider);
    if (existing != null) {
      final act = await showModalBottomSheet<String>(context: context, builder: (c) => SafeArea(child: Wrap(children: [
            ListTile(leading: const Icon(Icons.swap_horiz), title: const Text('معلم جایگزین / لغو کلاس در یک تاریخ'), onTap: () => Navigator.pop(c, 'sub')),
            ListTile(leading: const Icon(Icons.delete_outline, color: Palette.danger), title: const Text('حذف از برنامه'), onTap: () => Navigator.pop(c, 'del')),
          ])));
      if (act == 'del') { await api.delete('/timetables/$_ttId/entries/${existing['id']}'); _load(); }
      if (act == 'sub') _substitute(existing);
      return;
    }
    final options = _teaching.where((x) => x['section_id'] == _sectionId).toList();
    if (options.isEmpty) { toast(context, 'برای این کلاس هنوز معلمی تخصیص داده نشده است (ساختار آموزشی ← تخصیص معلم).', error: true); return; }
    final pick = await showDialog<Map<String, dynamic>>(context: context, builder: (c) => SimpleDialog(title: Text('${weekdayNames[day]} — ${period['title']}'), children: [for (final o in options) SimpleDialogOption(onPressed: () => Navigator.pop(c, o), child: Text('${o['subject']}  ·  ${o['teacher_name']}'))]));
    if (pick == null) return;
    final body = {'period_id': period['id'], 'weekday': day, 'section_id': _sectionId, 'subject_id': pick['subject_id'], 'teacher_id': pick['teacher_id']};
    try {
      // Conflicts are previewed by exactly the rules that will be enforced on save.
      final chk = await api.post('/timetables/$_ttId/check', data: body);
      if (chk['ok'] != true) { if (mounted) await _showConflicts([for (final c in chk['conflicts'] as List) '${c['message']}']); return; }
      await api.post('/timetables/$_ttId/entries', data: body);
      _load();
    } on ApiException catch (e) {
      if (mounted) await _showConflicts(e.errors['conflicts'] ?? [e.readable]);
    }
  }

  Future<void> _showConflicts(List<String> msgs) => showDialog(context: context, builder: (c) => AlertDialog(
        icon: const Icon(Icons.warning_amber_rounded, color: Palette.warn, size: 36), title: const Text('تداخل در برنامه'),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [for (final m in msgs) Padding(padding: const EdgeInsets.only(bottom: 6), child: Text('• $m'))]),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('متوجه شدم'))],
      ));

  Future<void> _substitute(Map<String, dynamic> entry) async {
    await showForm(context, title: 'جایگزینی / لغو یک جلسه', submitLabel: 'ثبت و اطلاع‌رسانی', fields: const [
      FieldSpec('on_date', 'تاریخ (باید با روز هفتهٔ این زنگ بخواند)', type: FieldType.date, required: true),
      FieldSpec('status', 'اقدام', type: FieldType.dropdown, required: true, initial: 'substitute', options: [Option('substitute', 'معلم جایگزین'), Option('cancelled', 'لغو کلاس')]),
      FieldSpec('substitute_teacher_id', 'معلم جایگزین', type: FieldType.dropdown, optionsFrom: '/teachers', labelKey: 'id', optionLabel: _tn), FieldSpec('reason', 'دلیل'),
    ], submit: (v) async { await ref.read(apiProvider).post('/substitutions', data: {...v, 'timetable_entry_id': entry['id']}); if (mounted) toast(context, 'ثبت شد و به کاربران مربوط اطلاع داده شد.'); });
  }

  @override
  Widget build(BuildContext context) {
    final list = ref.watch(_timetablesProvider);
    final sections = ref.watch(_sectionsProvider);
    return PageBody(maxWidth: 1400, children: [
      PageHeader('برنامهٔ هفتگی و زنگ‌ها', actions: [FilledButton.icon(key: const Key('new-timetable'), onPressed: _create, icon: const Icon(Icons.add), label: const Text('برنامهٔ جدید'))]),
      Async<List<Map<String, dynamic>>>(list, onRetry: () => ref.invalidate(_timetablesProvider), builder: (tts) {
        if (tts.isEmpty) return const EmptyState('هنوز برنامه‌ای ساخته نشده است. با «برنامهٔ جدید» زنگ‌ها را تعریف کنید.', icon: Icons.calendar_view_week_outlined);
        _ttId ??= (tts.firstWhere((t) => t['status'] == 'active', orElse: () => tts.first))['id'] as int;
        if (_tt == null && _error == null) WidgetsBinding.instance.addPostFrameCallback((_) => _load());
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(spacing: 12, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SizedBox(width: 280, child: DropdownButtonFormField<int>(key: ValueKey('tt$_ttId'), initialValue: _ttId, decoration: const InputDecoration(labelText: 'نسخهٔ برنامه'), items: [for (final t in tts) DropdownMenuItem(value: t['id'] as int, child: Text('${t['title']} — نسخهٔ ${faDigits(t['version'])} (${statusName(t['status'] as String)})'))], onChanged: (v) => setState(() { _ttId = v; _tt = null; _load(); }))),
            Async<List<Map<String, dynamic>>>(sections, builder: (ss) => SizedBox(width: 240, child: DropdownButtonFormField<int>(key: const Key('section-select'), initialValue: _sectionId, decoration: const InputDecoration(labelText: 'کلاس'), items: [for (final s in ss) DropdownMenuItem(value: s['id'] as int, child: Text('${(s['grade'] as Map?)?['name'] ?? ''} ${s['name']}'))], onChanged: (v) => setState(() => _sectionId = v)))),
            if (_tt != null && _tt!['status'] != 'archived') ActionButton(label: _tt!['status'] == 'active' ? 'فعال است' : 'فعال‌سازی و اطلاع‌رسانی', icon: Icons.rocket_launch_outlined, onPressed: _tt!['status'] == 'active' ? null : () async { await ref.read(apiProvider).post('/timetables/$_ttId/activate'); ref.invalidate(_timetablesProvider); _load(); if (mounted) toast(context, 'برنامه فعال شد؛ نسخهٔ قبلی بایگانی شد و همه مطلع شدند.'); }),
          ]),
          const SizedBox(height: 14),
          if (_error != null) ErrorView(_error!, onRetry: _load) else if (_tt == null) const Padding(padding: EdgeInsets.all(30), child: Center(child: CircularProgressIndicator())) else _grid(),
        ]);
      }),
    ]);
  }

  Widget _grid() {
    final periods = ((_tt!['periods'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final entries = ((_tt!['entries'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).where((e) => e['section_id'] == _sectionId).toList();
    final days = ((_tt!['working_days'] as List).cast<int>())..sort();
    final subj = (_lookup['subjects'] as Map?) ?? {};
    final tch = (_lookup['teachers'] as Map?) ?? {};
    if (_sectionId == null) return const EmptyState('یک کلاس را برای ویرایش برنامه‌اش انتخاب کنید.', icon: Icons.touch_app_outlined);
    return SingleChildScrollView(scrollDirection: Axis.horizontal, child: Table(
      defaultColumnWidth: const FixedColumnWidth(150), columnWidths: const {0: FixedColumnWidth(110)},
      border: TableBorder.all(color: Palette.border, borderRadius: BorderRadius.circular(10)),
      children: [
        TableRow(decoration: const BoxDecoration(color: Palette.brandSoft), children: [const SizedBox(height: 42), for (final d in days) Center(child: Text(weekdayNames[d], style: const TextStyle(fontWeight: FontWeight.w700)))]),
        for (final p in periods) TableRow(decoration: BoxDecoration(color: p['kind'] == 'lesson' ? null : const Color(0xFFF7F9FC)), children: [
          Padding(padding: const EdgeInsets.all(8), child: Column(children: [Text('${p['title']}', style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)), Text('${faDigits((p['starts_at'] as String).substring(0, 5))}–${faDigits((p['ends_at'] as String).substring(0, 5))}', style: Theme.of(context).textTheme.bodySmall)])),
          for (final d in days) Builder(builder: (_) {
            if (p['kind'] != 'lesson') return const SizedBox(height: 50, child: Center(child: Text('تفریح', style: TextStyle(color: Palette.muted, fontSize: 12))));
            final e = entries.where((x) => x['weekday'] == d && x['period_id'] == p['id']).firstOrNull;
            return InkWell(
              key: Key('cell-$d-${p['id']}'), onTap: _tt!['status'] == 'archived' ? null : () => _cell(p, d, e),
              child: SizedBox(height: 62, child: e == null ? const Center(child: Icon(Icons.add, color: Palette.brandMid)) : Container(margin: const EdgeInsets.all(4), padding: const EdgeInsets.all(6), decoration: BoxDecoration(color: Palette.brandSoft, borderRadius: BorderRadius.circular(8)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [Text('${subj['${e['subject_id']}'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)), Text('${tch['${e['teacher_id']}'] ?? ''}', style: Theme.of(context).textTheme.bodySmall, overflow: TextOverflow.ellipsis)]))),
            );
          }),
        ]),
      ],
    ));
  }
}

String? _tn(Map<String, dynamic> m) => (m['user'] as Map?)?['name']?.toString();

class _NewTimetableDialog extends ConsumerStatefulWidget {
  const _NewTimetableDialog({required this.years});
  final List<Option> years;
  @override
  ConsumerState<_NewTimetableDialog> createState() => _NewTimetableDialogState();
}

class _P {
  _P(this.kind, this.title, this.start, this.end);
  String kind, title, start, end;
}

class _NewTimetableDialogState extends ConsumerState<_NewTimetableDialog> {
  final _title = TextEditingController(text: 'برنامهٔ ترم اول');
  int? _year;
  final Set<int> _days = {0, 1, 2, 3, 4};
  // Sample day from the product spec; fully editable.
  final List<_P> _periods = [
    _P('prep', 'آماده‌سازی و ورود', '07:45', '08:00'), _P('lesson', 'زنگ اول', '08:00', '08:45'), _P('break', 'تفریح اول', '08:45', '08:55'), _P('lesson', 'زنگ دوم', '08:55', '09:40'),
    _P('break', 'تفریح دوم', '09:40', '10:00'), _P('lesson', 'زنگ سوم', '10:00', '10:45'), _P('break', 'تفریح سوم', '10:45', '10:55'), _P('lesson', 'زنگ چهارم', '10:55', '11:40'),
  ];
  String? _err;
  bool _busy = false;

  Future<String?> _time(String cur) async {
    final p = cur.split(':');
    final t = await showTimePicker(context: context, initialTime: TimeOfDay(hour: int.parse(p[0]), minute: int.parse(p[1])));
    return t == null ? null : '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
  }

  Future<void> _save() async {
    if (_year == null) { setState(() => _err = 'سال تحصیلی را انتخاب کنید.'); return; }
    setState(() { _busy = true; _err = null; });
    try {
      await ref.read(apiProvider).post('/timetables', data: {'academic_year_id': _year, 'title': _title.text.trim(), 'working_days': _days.toList()..sort(), 'periods': [for (final p in _periods) {'kind': p.kind, 'title': p.title, 'starts_at': p.start, 'ends_at': p.end}]});
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() { _err = e.readable; _busy = false; });
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('برنامهٔ هفتگی جدید'),
        content: SizedBox(width: 640, child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (_err != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(_err!, style: const TextStyle(color: Palette.danger))),
          TextField(controller: _title, decoration: const InputDecoration(labelText: 'عنوان')),
          const SizedBox(height: 10),
          DropdownButtonFormField<int>(initialValue: _year, decoration: const InputDecoration(labelText: 'سال تحصیلی *'), items: [for (final y in widget.years) DropdownMenuItem(value: y.value as int, child: Text(y.label))], onChanged: (v) => setState(() => _year = v)),
          const SizedBox(height: 10),
          const Text('روزهای کاری'),
          Wrap(spacing: 6, children: [for (var d = 0; d < 7; d++) FilterChip(label: Text(weekdayNames[d]), selected: _days.contains(d), onSelected: (on) => setState(() => on ? _days.add(d) : _days.remove(d)))]),
          const Divider(height: 24),
          Row(children: [const Expanded(child: Text('زنگ‌ها و تفریح‌ها (به ترتیب)')), TextButton.icon(onPressed: () => setState(() => _periods.add(_P('lesson', 'زنگ جدید', '12:00', '12:45'))), icon: const Icon(Icons.add), label: const Text('افزودن'))]),
          for (var i = 0; i < _periods.length; i++)
            Padding(padding: const EdgeInsets.only(bottom: 6), child: Row(children: [
              SizedBox(width: 100, child: DropdownButton<String>(isExpanded: true, value: _periods[i].kind, items: const [DropdownMenuItem(value: 'lesson', child: Text('درس')), DropdownMenuItem(value: 'break', child: Text('تفریح')), DropdownMenuItem(value: 'prep', child: Text('آماده‌سازی'))], onChanged: (v) => setState(() => _periods[i].kind = v!))),
              const SizedBox(width: 8),
              Expanded(child: TextFormField(initialValue: _periods[i].title, onChanged: (v) => _periods[i].title = v, decoration: const InputDecoration(isDense: true))),
              TextButton(onPressed: () async { final t = await _time(_periods[i].start); if (t != null) setState(() => _periods[i].start = t); }, child: Text(faDigits(_periods[i].start))),
              const Text('تا'),
              TextButton(onPressed: () async { final t = await _time(_periods[i].end); if (t != null) setState(() => _periods[i].end = t); }, child: Text(faDigits(_periods[i].end))),
              IconButton(onPressed: () => setState(() => _periods.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
            ])),
        ]))),
        actions: [TextButton(onPressed: _busy ? null : () => Navigator.pop(context, false), child: const Text('انصراف')), FilledButton(key: const Key('save-timetable'), onPressed: _busy ? null : _save, child: const Text('ایجاد'))],
      );
}
