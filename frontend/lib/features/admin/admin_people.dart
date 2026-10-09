import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../design/forms.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

/// Teachers, students (+guardians), bulk import/export.
class AdminPeople extends ConsumerStatefulWidget {
  const AdminPeople({super.key});
  @override
  ConsumerState<AdminPeople> createState() => _AdminPeopleState();
}

class _AdminPeopleState extends ConsumerState<AdminPeople> with SingleTickerProviderStateMixin {
  late final _tabs = TabController(length: 3, vsync: this);
  final _teachers = GlobalKey<PagedListState>();
  final _students = GlobalKey<PagedListState>();
  String _q = '';

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Column(children: [
        Material(color: Palette.surface, child: TabBar(controller: _tabs, tabs: const [Tab(text: 'دانش‌آموزان'), Tab(text: 'معلمان'), Tab(text: 'ورود و خروج گروهی')])),
        Expanded(child: TabBarView(controller: _tabs, children: [_studentsTab(), _teachersTab(), _importTab()])),
      ]);

  Widget _studentsTab() => PageBody(children: [
        PageHeader('دانش‌آموزان', actions: [FilledButton.icon(key: const Key('add-student'), onPressed: _addStudent, icon: const Icon(Icons.person_add_alt_1), label: const Text('دانش‌آموز جدید'))]),
        Padding(padding: const EdgeInsets.only(bottom: 12), child: SearchField(onChanged: (v) => setState(() => _q = v), hint: 'نام یا کد دانش‌آموزی')),
        PagedList(key: _students, path: '/academics/students', query: {'q': _q}, emptyText: 'دانش‌آموزی ثبت نشده است.', itemBuilder: (c, s, st) => AppCard(child: Row(children: [
              const CircleAvatar(backgroundColor: Palette.brandSoft, child: Icon(Icons.person, color: Palette.brand)), const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${s['first_name']} ${s['last_name']}', style: Theme.of(c).textTheme.titleMedium), Text('کد ${faDigits(s['student_code'])}  ·  ${s['status']}', style: Theme.of(c).textTheme.bodySmall)])),
              IconButton(tooltip: 'ثبت والد/سرپرست', icon: const Icon(Icons.family_restroom_outlined), onPressed: () => _addGuardian(s)),
              IconButton(tooltip: 'ویرایش', icon: const Icon(Icons.edit_outlined), onPressed: () => _editStudent(s)),
            ]))),
      ]);

  Future<void> _addStudent() async {
    final ok = await showForm(context, title: 'دانش‌آموز جدید', fields: const [FieldSpec('first_name', 'نام', required: true), FieldSpec('last_name', 'نام خانوادگی', required: true), FieldSpec('student_code', 'کد دانش‌آموزی', required: true), FieldSpec('birth_date', 'تاریخ تولد', type: FieldType.date)],
        submit: (v) async => ref.read(apiProvider).post('/academics/students', data: v));
    if (ok) _students.currentState?.reload();
  }

  Future<void> _editStudent(Map<String, dynamic> s) async {
    final ok = await showForm(context, title: 'ویرایش دانش‌آموز', initial: s, fields: const [FieldSpec('first_name', 'نام', required: true), FieldSpec('last_name', 'نام خانوادگی', required: true), FieldSpec('student_code', 'کد دانش‌آموزی', required: true), FieldSpec('birth_date', 'تاریخ تولد', type: FieldType.date),
      FieldSpec('status', 'وضعیت', type: FieldType.dropdown, options: [Option('active', 'فعال'), Option('transferred', 'منتقل‌شده'), Option('graduated', 'فارغ‌التحصیل'), Option('archived', 'بایگانی')])],
        submit: (v) async => ref.read(apiProvider).patch('/academics/students/${s['id']}', data: v));
    if (ok) _students.currentState?.reload();
  }

  Future<void> _addGuardian(Map<String, dynamic> s) async {
    await showForm(context, title: 'والد یا سرپرست ${s['first_name']}', submitLabel: 'ثبت و تأیید پیوند', fields: const [
      FieldSpec('name', 'نام و نام خانوادگی', required: true), FieldSpec('email', 'ایمیل (نام کاربری)', type: FieldType.email, required: true), FieldSpec('phone', 'موبایل'),
      FieldSpec('relation', 'نسبت', type: FieldType.dropdown, initial: 'parent', options: [Option('parent', 'والد'), Option('guardian', 'سرپرست قانونی')]), FieldSpec('password', 'رمز اولیه (حداقل ۱۰ نویسه، حرف و عدد)', type: FieldType.password, required: true),
    ], submit: (v) async { await ref.read(apiProvider).post('/students/${s['id']}/guardians', data: v); if (mounted) toast(context, 'والد ثبت و پیوند تأیید شد؛ فقط همین فرزند برای او قابل مشاهده است.'); });
  }

  Widget _teachersTab() => PageBody(children: [
        PageHeader('معلمان', actions: [FilledButton.icon(key: const Key('add-teacher'), onPressed: () async {
          final ok = await showForm(context, title: 'معلم جدید', fields: const [FieldSpec('name', 'نام و نام خانوادگی', required: true), FieldSpec('email', 'ایمیل (نام کاربری)', type: FieldType.email, required: true), FieldSpec('phone', 'موبایل'), FieldSpec('personnel_code', 'کد پرسنلی'), FieldSpec('password', 'رمز اولیه (حداقل ۱۰ نویسه، حرف و عدد)', type: FieldType.password, required: true)],
              submit: (v) async => ref.read(apiProvider).post('/teachers', data: v));
          if (ok) _teachers.currentState?.reload();
        }, icon: const Icon(Icons.person_add_alt_1), label: const Text('معلم جدید'))]),
        PagedList(key: _teachers, path: '/teachers', emptyText: 'معلمی ثبت نشده است.', itemBuilder: (c, t, st) => AppCard(child: Row(children: [
              const CircleAvatar(backgroundColor: Palette.brandSoft, child: Icon(Icons.school, color: Palette.brand)), const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${t['user']?['name']}', style: Theme.of(c).textTheme.titleMedium), Text('${t['user']?['email'] ?? ''}', textDirection: TextDirection.ltr, style: Theme.of(c).textTheme.bodySmall)])),
            ]))),
      ]);

  Widget _importTab() => PageBody(children: [
        const PageHeader('ورود گروهی از Excel / CSV'),
        AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('فایل را با ستون‌های الگو آماده کنید. ابتدا «آزمایشی» را اجرا کنید تا خطاها ردیف‌به‌ردیف گزارش شوند؛ سپس ورود نهایی.'),
          const SizedBox(height: 12),
          Wrap(spacing: 8, runSpacing: 8, children: [
            OutlinedButton.icon(onPressed: () => _template('students'), icon: const Icon(Icons.download), label: const Text('الگوی دانش‌آموزان')),
            OutlinedButton.icon(onPressed: () => _template('teachers'), icon: const Icon(Icons.download), label: const Text('الگوی معلمان')),
          ]),
          const Divider(height: 28),
          Wrap(spacing: 8, runSpacing: 8, children: [
            FilledButton.icon(key: const Key('import-students'), onPressed: () => _import('students'), icon: const Icon(Icons.upload_file), label: const Text('ورود دانش‌آموزان')),
            FilledButton.tonalIcon(onPressed: () => _import('teachers'), icon: const Icon(Icons.upload_file), label: const Text('ورود معلمان')),
          ]),
        ])),
        const SectionTitle('خروجی گرفتن (ثبت در سابقهٔ حسابرسی)'),
        AppCard(child: Wrap(spacing: 8, children: [
          OutlinedButton.icon(onPressed: () => _export('students'), icon: const Icon(Icons.table_view), label: const Text('خروجی دانش‌آموزان (CSV)')),
          OutlinedButton.icon(onPressed: () => _export('grades'), icon: const Icon(Icons.table_view), label: const Text('خروجی نمرات (CSV)')),
        ])),
      ]);

  Future<void> _template(String type) async {
    final b = await ref.read(apiProvider).bytes('/imports/$type/template');
    _download(b, '$type-template.csv');
  }

  Future<void> _export(String type) async {
    try {
      final b = await ref.read(apiProvider).bytes('/exports/$type');
      _download(b, '$type-export.csv');
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  void _download(List<int> bytes, String name) => launchUrl(Uri.dataFromBytes(bytes, mimeType: 'text/csv;charset=utf-8'), webOnlyWindowName: '_blank');

  Future<void> _import(String type) async {
    final pick = await FilePicker.platform.pickFiles(type: FileType.custom, allowedExtensions: ['csv', 'xlsx'], withData: true);
    if (pick == null || pick.files.isEmpty || !mounted) return;
    final f = pick.files.first;
    final pw = await promptText(context, 'رمز اولیهٔ حساب‌های جدید', label: 'حداقل ۱۰ نویسه با حرف و عدد');
    if (pw == null || !mounted) return;
    final dry = await confirm(context, 'ابتدا اجرای آزمایشی (بدون ذخیره) انجام شود؟', ok: 'آزمایشی', danger: false);
    try {
      final r = await ref.read(apiProvider).postForm('/imports/$type', FormData.fromMap({'file': MultipartFile.fromBytes(f.bytes!, filename: f.name), 'initial_password': pw, 'dry_run': dry ? '1' : '0'}));
      _report(r['data'] as Map, dry);
    } on ApiException catch (e) {
      if (e.status == 422 && e.errors.isEmpty) { toast(context, e.message, error: true); return; }
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  void _report(Map d, bool dry) {
    if (!mounted) return;
    showDialog(context: context, builder: (c) => AlertDialog(
          title: Text(dry ? 'نتیجهٔ اجرای آزمایشی' : 'نتیجهٔ ورود'),
          content: SizedBox(width: 520, child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('ردیف‌ها: ${faDigits(d['total'])}  ·  موفق: ${faDigits(d['created'])}  ·  خطا: ${faDigits(d['failed'])}'),
            if ((d['errors'] as List).isNotEmpty) ...[const Divider(), for (final e in d['errors'] as List) Text('ردیف ${faDigits(e['line'])}: ${(e['errors'] as List).join('، ')}', style: const TextStyle(color: Palette.danger, fontSize: 13))],
          ]))),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('بستن'))],
        ));
    _students.currentState?.reload();
    _teachers.currentState?.reload();
  }
}
