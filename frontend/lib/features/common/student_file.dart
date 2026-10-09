import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

final _fileProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) async {
  final r = await ref.read(apiProvider).get(id == 0 ? '/me/student-file' : '/students/$id/dossier');
  return Map<String, dynamic>.from(r['data'] as Map);
});

/// پرونده تحصیلی: one continuous record per student across academic years.
/// [studentId] null = the signed-in student's own file.
class StudentFileScreen extends ConsumerWidget {
  const StudentFileScreen({super.key, this.studentId});
  final int? studentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final p = _fileProvider(studentId ?? 0);
    return Scaffold(
      appBar: AppBar(title: const Text('پرونده تحصیلی'), leading: BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/'))),
      body: PageBody(children: [
        Async<Map<String, dynamic>>(ref.watch(p), onRetry: () => ref.invalidate(p), builder: (d) {
          final s = Map<String, dynamic>.from(d['student'] as Map);
          final att = Map<String, dynamic>.from(d['attendance'] as Map);
          final asg = Map<String, dynamic>.from(d['assignments'] as Map);
          final terms = (d['terms'] as List).cast<Map>();
          final enr = (d['enrollments'] as List).cast<Map>();
          final cards = (d['report_cards'] as List).cast<Map>();
          final notes = (d['notes'] as List).cast<Map>();
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            PageHeader('${s['first_name']} ${s['last_name']}'),
            Text('کد دانش‌آموزی ${faDigits(s['student_code'])}', style: Theme.of(context).textTheme.bodySmall),
            const SectionTitle('سوابق ثبت‌نام و ارتقا'),
            if (enr.isEmpty) const EmptyState('ثبت‌نامی نیست.') else for (final e in enr)
              AppCard(child: Row(children: [
                Expanded(child: Text('${e['academic_year'] ?? ''} · ${e['grade'] ?? ''} ${e['section'] ?? ''}', style: Theme.of(context).textTheme.titleMedium)),
                StatusChip(tr('enroll.${e['status']}'), tone: e['status'] == 'active' ? Tone.success : Tone.neutral),
              ])),
            const SectionTitle('نمرات تأییدشده به تفکیک دوره'),
            if (terms.isEmpty) const EmptyState('هنوز نمرهٔ تأییدشده‌ای نیست.') else for (final t in terms)
              AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${t['academic_year'] ?? ''} · ${t['term']}', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 6),
                for (final e in (t['subjects'] as Map).entries) Row(children: [Expanded(child: Text('${e.key}')), Text(e.value == null ? '—' : fmtNum(e.value))]),
                const Divider(),
                Text('معدل: ${fmtNum((t['result'] as Map)['average'])}', style: const TextStyle(fontWeight: FontWeight.w700)),
              ])),
            const SectionTitle('کارنامه‌های صادرشده'),
            if (cards.isEmpty) const EmptyState('کارنامه‌ای صادر نشده است.') else for (final c in cards)
              AppCard(onTap: () => context.push('/report-card/${c['id']}'), child: Row(children: [const Icon(Icons.workspace_premium_outlined, color: Palette.brand), const SizedBox(width: 10), Expanded(child: Text('معدل ${fmtNum(c['average'])}')), Text(fmtDate(c['issued_at']))])),
            const SectionTitle('حضور و غیاب'),
            Wrap(spacing: 12, runSpacing: 12, children: [
              SizedBox(width: 160, child: StatCard(label: 'حاضر', value: faDigits(att['present']), icon: Icons.check_circle_outline, tone: Tone.success)),
              SizedBox(width: 160, child: StatCard(label: 'تأخیر', value: faDigits(att['late']), icon: Icons.schedule, tone: Tone.warn)),
              SizedBox(width: 160, child: StatCard(label: 'غیبت', value: faDigits(att['absent']), icon: Icons.cancel_outlined, tone: Tone.danger)),
            ]),
            const SectionTitle('تکالیف'),
            Text('ارسال‌شده: ${faDigits(asg['submitted'])} · با تأخیر: ${faDigits(asg['late'])} · میانگین نمره: ${asg['average_score'] == null ? '—' : fmtNum(asg['average_score'])}'),
            const SectionTitle('بازخورد معلمان و سوابق'),
            if (notes.isEmpty) const EmptyState('یادداشتی ثبت نشده است.') else for (final n in notes)
              AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [StatusChip(tr('note.${n['kind']}'), tone: n['kind'] == 'discipline' ? Tone.danger : (n['kind'] == 'praise' || n['kind'] == 'strength' ? Tone.success : Tone.info)), const SizedBox(width: 8), Expanded(child: Text('${n['title']}', style: Theme.of(context).textTheme.titleMedium))]),
                if (n['body'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('${n['body']}')),
              ])),
          ]);
        }),
      ]),
    );
  }
}
