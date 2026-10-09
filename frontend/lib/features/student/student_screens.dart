import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import '../common/report_cards.dart';
import '../common/schedule_widgets.dart';

class StudentHome extends ConsumerWidget {
  const StudentHome({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(sessionProvider);
    return PageBody(
      onRefresh: () async { ref.invalidate(myScheduleProvider); ref.invalidate(todaySessionsProvider); },
      children: [
        PageHeader('سلام ${s.name}', subtitle: 'برنامهٔ امروز شما'),
        const TodayPanel(teacher: false),
        const SectionTitle('هفتهٔ من'),
        Async<ScheduleData>(ref.watch(myScheduleProvider), builder: (d) => WeeklyGrid(data: d)),
      ],
    );
  }
}

/// Published materials of the student's classes.
class LearnScreen extends ConsumerWidget {
  const LearnScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => PageBody(children: [
        const PageHeader('درس‌نامه‌ها و محتوا'),
        PagedList(
          path: '/materials', emptyText: 'هنوز محتوایی منتشر نشده است.',
          itemBuilder: (c, m, st) => AppCard(
            onTap: () async {
              if (m['kind'] == 'link') launchUrl(Uri.parse('${m['body']}'), mode: LaunchMode.externalApplication);
              if (m['kind'] == 'text') showDialog(context: c, builder: (d) => AlertDialog(title: Text('${m['title']}'), content: SizedBox(width: 520, child: SingleChildScrollView(child: SelectableText('${m['body']}'))), actions: [TextButton(onPressed: () => Navigator.pop(d), child: const Text('بستن'))]));
              if (m['kind'] == 'file' && m['file_id'] != null) {
                try { final l = await ref.read(apiProvider).get('/files/${m['file_id']}/link'); launchUrl(Uri.parse(l['url'] as String), mode: LaunchMode.externalApplication); } on ApiException catch (e) { if (c.mounted) toast(c, e.readable, error: true); }
              }
            },
            child: Row(children: [
              Icon(switch (m['kind']) { 'link' => Icons.link, 'text' => Icons.article_outlined, _ => Icons.attach_file }, color: Palette.brand),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${m['title']}', style: Theme.of(c).textTheme.titleMedium), Text(fmtDate(m['published_at']), style: Theme.of(c).textTheme.bodySmall)])),
            ]),
          ),
        ),
      ]);
}

class GradesScreen extends ConsumerStatefulWidget {
  const GradesScreen({super.key, this.studentId});
  final int? studentId;
  @override
  ConsumerState<GradesScreen> createState() => _GradesState();
}

class _GradesState extends ConsumerState<GradesScreen> {
  @override
  Widget build(BuildContext context) {
    return PageBody(children: [
      PageHeader('نمرات و کارنامه', actions: [OutlinedButton.icon(key: const Key('open-file'), onPressed: () => context.push(widget.studentId == null ? '/file' : '/file/${widget.studentId}'), icon: const Icon(Icons.folder_shared_outlined), label: const Text('پرونده تحصیلی'))]),
      const SectionTitle('کارنامه‌ها'),
      ReportCardList(studentId: widget.studentId),
      const SectionTitle('نمرات تأییدشده'),
      PagedList(
        path: '/grades', query: {'student_id': widget.studentId}, emptyText: 'هنوز نمره‌ای تأیید نشده است.',
        itemBuilder: (c, g, st) => AppCard(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(children: [
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${g['title']}', style: Theme.of(c).textTheme.titleMedium), Text(tr('kind.${g['kind']}'), style: Theme.of(c).textTheme.bodySmall)])),
            Text('${fmtNum(g['score'])} / ${fmtNum(g['max_score'])}', style: Theme.of(c).textTheme.titleMedium?.copyWith(color: Palette.brand)),
          ]),
        ),
      ),
    ]);
  }
}
