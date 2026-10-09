import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../design/forms.dart';
import '../../design/paged_list.dart';
import '../../design/resource_screen.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

String _sec(Map<String, dynamic> m) => '${(m['grade'] as Map?)?['name'] ?? ''} ${m['name']}'.trim();

/// Years, terms, grades, subjects, classes, teacher assignments, enrolments and the school calendar.
class AdminStructure extends ConsumerStatefulWidget {
  const AdminStructure({super.key});
  @override
  ConsumerState<AdminStructure> createState() => _AdminStructureState();
}

class _AdminStructureState extends ConsumerState<AdminStructure> with SingleTickerProviderStateMixin {
  late final _tabs = TabController(length: 8, vsync: this);
  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Column(children: [
        Material(color: Palette.surface, child: TabBar(controller: _tabs, isScrollable: true, tabAlignment: TabAlignment.start, tabs: const [Tab(text: 'سال تحصیلی'), Tab(text: 'ترم‌ها'), Tab(text: 'پایه‌ها'), Tab(text: 'درس‌ها'), Tab(text: 'کلاس‌ها'), Tab(text: 'تخصیص معلم'), Tab(text: 'ثبت‌نام و انتقال'), Tab(text: 'تقویم و تعطیلات')])),
        Expanded(child: TabBarView(controller: _tabs, children: [
          ResourceScreen(title: 'سال‌های تحصیلی', path: '/academics/academic-years', canDelete: false, leading: Icons.event_note_outlined, fields: const [
            FieldSpec('title', 'عنوان (مثلاً ۱۴۰۵-۱۴۰۶)', required: true), FieldSpec('starts_on', 'شروع', type: FieldType.date, required: true), FieldSpec('ends_on', 'پایان', type: FieldType.date, required: true), FieldSpec('is_current', 'سال جاری', type: FieldType.toggle)],
              itemTitle: (r) => '${r['title']}', itemSubtitle: (r) => '${fmtDate(r['starts_on'])} تا ${fmtDate(r['ends_on'])}${r['is_current'] == true ? '  ·  سال جاری' : ''}'),
          ResourceScreen(title: 'نیم‌سال‌ها', path: '/academics/terms', leading: Icons.date_range, fields: const [
            FieldSpec('academic_year_id', 'سال تحصیلی', type: FieldType.dropdown, required: true, optionsFrom: '/academics/academic-years', labelKey: 'title'),
            FieldSpec('title', 'عنوان', required: true), FieldSpec('starts_on', 'شروع', type: FieldType.date, required: true), FieldSpec('ends_on', 'پایان', type: FieldType.date, required: true)],
              itemTitle: (r) => '${r['title']}', itemSubtitle: (r) => '${fmtDate(r['starts_on'])} تا ${fmtDate(r['ends_on'])}'),
          ResourceScreen(title: 'پایه‌ها', path: '/academics/grades', leading: Icons.stairs_outlined, fields: const [FieldSpec('name', 'نام پایه', required: true), FieldSpec('stage', 'مقطع (ابتدایی، متوسطه اول...)'), FieldSpec('level', 'ترتیب/سطح (۱ تا ۲۰)', type: FieldType.number, initial: 1)],
              itemTitle: (r) => '${r['name']}', itemSubtitle: (r) => r['stage'] as String?),
          ResourceScreen(title: 'درس‌ها', path: '/academics/subjects', leading: Icons.menu_book_outlined, fields: const [FieldSpec('name', 'نام درس', required: true), FieldSpec('code', 'کد'), FieldSpec('grade_id', 'پایه', type: FieldType.dropdown, optionsFrom: '/academics/grades'), FieldSpec('coefficient', 'ضریب در معدل', type: FieldType.number, initial: 1)],
              itemTitle: (r) => '${r['name']}', itemSubtitle: (r) => 'ضریب ${fmtNum(r['coefficient'])}'),
          ResourceScreen(title: 'کلاس‌ها', path: '/academics/sections', leading: Icons.meeting_room_outlined, fields: const [
            FieldSpec('grade_id', 'پایه', type: FieldType.dropdown, required: true, optionsFrom: '/academics/grades'), FieldSpec('academic_year_id', 'سال تحصیلی', type: FieldType.dropdown, required: true, optionsFrom: '/academics/academic-years', labelKey: 'title'),
            FieldSpec('name', 'نام کلاس (الف، ب...)', required: true), FieldSpec('track', 'رشته'), FieldSpec('capacity', 'ظرفیت', type: FieldType.number, initial: 30)],
              itemTitle: _sec, itemSubtitle: (r) => 'ظرفیت ${faDigits(r['capacity'])}'),
          const _Assignments(),
          const _Enrollments(),
          ResourceScreen(title: 'تقویم مدرسه', path: '/academics/calendar-events', leading: Icons.event_outlined, fields: const [
            FieldSpec('type', 'نوع', type: FieldType.dropdown, required: true, options: [Option('holiday', 'تعطیلی'), Option('exceptional', 'برنامهٔ استثنایی'), Option('meeting', 'جلسه'), Option('event', 'مناسبت')]),
            FieldSpec('title', 'عنوان', required: true), FieldSpec('starts_on', 'از', type: FieldType.date, required: true), FieldSpec('ends_on', 'تا', type: FieldType.date, required: true), FieldSpec('cancels_classes', 'کلاس‌ها لغو شوند (زنگ نمی‌خورد)', type: FieldType.toggle)],
              itemTitle: (r) => '${r['title']}', itemSubtitle: (r) => '${fmtDate(r['starts_on'])} تا ${fmtDate(r['ends_on'])}${r['cancels_classes'] == true ? '  ·  لغو کلاس‌ها' : ''}'),
        ])),
      ]);
}

class _Assignments extends ConsumerStatefulWidget {
  const _Assignments();
  @override
  ConsumerState<_Assignments> createState() => _AssignmentsState();
}

class _AssignmentsState extends ConsumerState<_Assignments> {
  final _key = GlobalKey<PagedListState>();
  @override
  Widget build(BuildContext context) => PageBody(children: [
        PageHeader('تخصیص معلم به درس و کلاس', actions: [FilledButton.icon(key: const Key('add-assignment'), onPressed: () async {
          final ok = await showForm(context, title: 'تخصیص جدید', fields: const [
            FieldSpec('teacher_id', 'معلم', type: FieldType.dropdown, required: true, optionsFrom: '/teachers', labelKey: 'id', optionLabel: _teacherLabel),
            FieldSpec('section_id', 'کلاس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/sections', optionLabel: _sec),
            FieldSpec('subject_id', 'درس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/subjects'), FieldSpec('weekly_hours', 'ساعت در هفته', type: FieldType.number, initial: 2),
          ], submit: (v) async => ref.read(apiProvider).post('/teacher-assignments', data: v));
          if (ok) _key.currentState?.reload();
        }, icon: const Icon(Icons.add), label: const Text('تخصیص'))]),
        PagedList(key: _key, path: '/me/teaching', dataKey: 'data', emptyText: 'تخصیصی انجام نشده است.', itemBuilder: (c, a, st) => AppCard(child: Row(children: [
              const Icon(Icons.assignment_ind_outlined, color: Palette.brand), const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${a['teacher_name']}', style: Theme.of(c).textTheme.titleMedium), Text('${a['label']} · ${faDigits(a['weekly_hours'])} ساعت در هفته', style: Theme.of(c).textTheme.bodySmall)])),
              IconButton(icon: const Icon(Icons.delete_outline, color: Palette.danger), onPressed: () async { try { await ref.read(apiProvider).delete('/teacher-assignments/${a['id']}'); st.reload(); } on ApiException catch (e) { if (c.mounted) toast(c, e.readable, error: true); } }),
            ]))),
      ]);
}

String? _teacherLabel(Map<String, dynamic> m) => (m['user'] as Map?)?['name']?.toString();

class _Enrollments extends ConsumerStatefulWidget {
  const _Enrollments();
  @override
  ConsumerState<_Enrollments> createState() => _EnrollmentsState();
}

class _EnrollmentsState extends ConsumerState<_Enrollments> {
  final _key = GlobalKey<PagedListState>();
  @override
  Widget build(BuildContext context) => PageBody(children: [
        PageHeader('ثبت‌نام، انتقال و ارتقا', actions: [FilledButton.icon(key: const Key('add-enrollment'), onPressed: () async {
          final ok = await showForm(context, title: 'ثبت‌نام / انتقال به کلاس', fields: const [
            FieldSpec('student_id', 'دانش‌آموز', type: FieldType.dropdown, required: true, optionsFrom: '/academics/students', optionLabel: _studentLabel),
            FieldSpec('section_id', 'کلاس', type: FieldType.dropdown, required: true, optionsFrom: '/academics/sections', optionLabel: _sec),
          ], submit: (v) async => ref.read(apiProvider).post('/enrollments', data: v));
          if (ok) _key.currentState?.reload();
        }, icon: const Icon(Icons.person_add_alt), label: const Text('ثبت‌نام / انتقال'))]),
        const Text('اگر دانش‌آموز در همین سال تحصیلی کلاس دیگری داشته باشد، به کلاس جدید منتقل می‌شود؛ ظرفیت کلاس کنترل می‌شود.'),
        const SizedBox(height: 12),
        PagedList(key: _key, path: '/enrollments', emptyText: 'ثبت‌نامی نیست.', itemBuilder: (c, e, st) => AppCard(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10), child: Row(children: [Expanded(child: Text('${e['student_name']}  ←  ${e['section_label']}')), StatusChip('${e['status']}', tone: e['status'] == 'active' ? Tone.success : Tone.neutral)]))),
      ]);
}

String? _studentLabel(Map<String, dynamic> m) => '${m['first_name']} ${m['last_name']} (${m['student_code']})';
