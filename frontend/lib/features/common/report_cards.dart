import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:printing/printing.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

/// Issued report cards of a student (own/children). Opens the secure PDF viewer.
class ReportCardList extends ConsumerWidget {
  const ReportCardList({super.key, this.studentId});
  final int? studentId;
  @override
  Widget build(BuildContext context, WidgetRef ref) => PagedList(
        path: '/report-cards', query: {'student_id': studentId}, emptyText: 'هنوز کارنامه‌ای صادر نشده است.',
        itemBuilder: (c, r, st) => AppCard(
          onTap: () => c.push('/report-card/${r['id']}'),
          child: Row(children: [
            const Icon(Icons.workspace_premium_outlined, color: Palette.brand),
            const SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('کارنامه — معدل ${fmtNum(r['average'])}', style: Theme.of(c).textTheme.titleMedium),
              Text('صادرشده: ${fmtDate(r['issued_at'])}', style: Theme.of(c).textTheme.bodySmall),
            ])),
            StatusChip(tr('result.${r['result']}'), tone: r['result'] == 'passed' ? Tone.success : (r['result'] == 'failed' ? Tone.danger : Tone.warn)),
          ]),
        ),
      );
}

class ReportCardViewer extends ConsumerStatefulWidget {
  const ReportCardViewer({super.key, required this.id});
  final int id;
  @override
  ConsumerState<ReportCardViewer> createState() => _ReportCardViewerState();
}

class _ReportCardViewerState extends ConsumerState<ReportCardViewer> {
  late Future<List<int>> _pdf = ref.read(apiProvider).bytes('/report-cards/${widget.id}/pdf');
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('کارنامه'), leading: BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/'))),
        body: FutureBuilder<List<int>>(
          future: _pdf,
          builder: (c, s) {
            if (s.hasError) return ErrorView(s.error!, onRetry: () => setState(() => _pdf = ref.read(apiProvider).bytes('/report-cards/${widget.id}/pdf')));
            if (!s.hasData) return const Center(child: CircularProgressIndicator());
            final bytes = Uint8List.fromList(s.data!);
            return PdfPreview(build: (_) async => bytes, allowPrinting: true, allowSharing: true, canChangeOrientation: false, canChangePageFormat: false, canDebug: false, pdfFileName: 'report-card-${widget.id}.pdf');
          },
        ),
      );
}
