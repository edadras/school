import 'package:audioplayers/audioplayers.dart';
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/api.dart';
import '../../core/session.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

/// Short-lived signed link for a private file the caller is allowed to read.
final fileLinkProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) async {
  final r = await ref.read(apiProvider).get('/files/$id/link');
  return Map<String, dynamic>.from(r as Map);
});

Future<List<int>> downloadSigned(String url) async {
  final r = await Dio().get<List<int>>(url, options: Options(responseType: ResponseType.bytes));
  return r.data ?? const [];
}

/// Renders an attachment by type: image preview, audio player, or a download chip.
class FileChip extends ConsumerWidget {
  const FileChip(this.fileId, {super.key});
  final int fileId;
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final link = ref.watch(fileLinkProvider(fileId));
    return link.when(
      loading: () => const SizedBox(height: 40, width: 40, child: Padding(padding: EdgeInsets.all(10), child: CircularProgressIndicator(strokeWidth: 2))),
      error: (e, _) => const Chip(avatar: Icon(Icons.lock_outline, size: 16), label: Text('فایل در دسترس نیست')),
      data: (l) {
        final mime = '${l['mime']}';
        final url = l['url'] as String;
        if (mime.startsWith('image/')) {
          return GestureDetector(
            onTap: () => showDialog(context: context, builder: (_) => Dialog(child: InteractiveViewer(child: Image.network(url)))),
            child: ClipRRect(borderRadius: BorderRadius.circular(10), child: Image.network(url, height: 140, fit: BoxFit.cover, errorBuilder: (_, _, _) => const Icon(Icons.broken_image))),
          );
        }
        if (mime.startsWith('audio/')) return AudioTile(url: url);
        return ActionChip(avatar: Icon(mime == 'application/pdf' ? Icons.picture_as_pdf : Icons.insert_drive_file_outlined, size: 18), label: Text('${l['name']}'), onPressed: () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication));
      },
    );
  }
}

class AudioTile extends StatefulWidget {
  const AudioTile({super.key, required this.url});
  final String url;
  @override
  State<AudioTile> createState() => _AudioTileState();
}

class _AudioTileState extends State<AudioTile> {
  final _p = AudioPlayer();
  bool _playing = false;
  @override
  void initState() {
    super.initState();
    _p.onPlayerComplete.listen((_) => mounted ? setState(() => _playing = false) : null);
  }

  @override
  void dispose() {
    _p.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: () async {
          if (_playing) { await _p.pause(); } else { await _p.play(UrlSource(widget.url)); }
          setState(() => _playing = !_playing);
        },
        borderRadius: BorderRadius.circular(20),
        child: Container(padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8), decoration: BoxDecoration(color: Palette.brandSoft, borderRadius: BorderRadius.circular(20)),
            child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(_playing ? Icons.pause_circle : Icons.play_circle, color: Palette.brand), const SizedBox(width: 8), const Text('پیام صوتی')])),
      );
}

/// Upload helper: picks files and uploads them to private storage; returns stored file ids.
Future<List<Map<String, dynamic>>> pickAndUpload(BuildContext context, WidgetRef ref, {List<String>? extensions, bool multiple = true}) async {
  final res = await FilePicker.platform.pickFiles(allowMultiple: multiple, withData: true, type: extensions == null ? FileType.any : FileType.custom, allowedExtensions: extensions);
  if (res == null) return [];
  final out = <Map<String, dynamic>>[];
  for (final f in res.files) {
    try {
      out.add(await ref.read(apiProvider).upload(f));
    } catch (e) {
      if (context.mounted) toast(context, '${f.name}: ${e is ApiException ? e.readable : e}', error: true);
    }
  }
  return out;
}

/// A list of attached/uploaded files with remove buttons (used in forms).
class UploadList extends StatelessWidget {
  const UploadList({super.key, required this.files, required this.onRemove});
  final List<Map<String, dynamic>> files;
  final void Function(Map<String, dynamic>) onRemove;
  @override
  Widget build(BuildContext context) => Wrap(spacing: 8, runSpacing: 8, children: [for (final f in files) InputChip(label: Text('${f['original_name'] ?? f['name']}'), onDeleted: () => onRemove(f), avatar: const Icon(Icons.attach_file, size: 16))]);
}

extension SessionX on WidgetRef {
  Session get session => read(sessionProvider);
}
