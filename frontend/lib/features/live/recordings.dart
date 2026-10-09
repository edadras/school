import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../design/widgets.dart';

/// Recordings of one class (host and school managers only; the server enforces it).
Future<void> showRecordings(BuildContext context, WidgetRef ref, int sessionId) async {
  List<Map<String, dynamic>> items;
  try {
    final r = await ref.read(apiProvider).get('/sessions/$sessionId/recordings');
    items = [for (final x in r['data'] as List) Map<String, dynamic>.from(x as Map)];
  } on ApiException catch (e) {
    toast(context, e.readable, error: true);
    return;
  }
  if (!context.mounted) return;
  const names = {'starting': 'در حال شروع', 'recording': 'در حال ضبط', 'stopping': 'در حال پردازش', 'ready': 'آماده', 'failed': 'ناموفق'};
  await showDialog<void>(
    context: context,
    builder: (d) => AlertDialog(
      title: const Text('ضبط‌های این کلاس'),
      content: SizedBox(width: 460, child: items.isEmpty
          ? const Text('ضبطی انجام نشده است.')
          : Column(mainAxisSize: MainAxisSize.min, children: [
              for (final r in items) ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text('${fmtDate(r['started_at'], withTime: true)} · ${names[r['status']] ?? r['status']}'),
                subtitle: r['error'] != null ? Text('${r['error']}') : (r['duration_seconds'] != null ? Text('مدت: ${faDigits((r['duration_seconds'] as int) ~/ 60)} دقیقه · ${fmtBytes(r['size'] as num? ?? 0)}') : null),
                trailing: r['status'] == 'ready' && r['file_id'] != null
                    ? IconButton(tooltip: 'پخش / دانلود', icon: const Icon(Icons.play_circle_outline), onPressed: () async {
                        try {
                          final l = await ref.read(apiProvider).get('/files/${r['file_id']}/link');
                          await launchUrl(Uri.parse(l['url'] as String), mode: LaunchMode.externalApplication);
                        } on ApiException catch (e) { if (context.mounted) toast(context, e.readable, error: true); }
                      })
                    : null,
              ),
            ])),
      actions: [TextButton(onPressed: () => Navigator.pop(d), child: const Text('بستن'))],
    ),
  );
}
