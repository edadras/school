import 'dart:async';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:record/record.dart';
import 'package:file_picker/file_picker.dart';
import 'package:uuid/uuid.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/realtime.dart';
import '../../core/session.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import 'files_ui.dart';

/// A conversation: history, live updates (WebSocket) with polling fallback, idempotent retried sends,
/// replies, file / voice attachments, reporting, and "locked" state.
class ChatView extends ConsumerStatefulWidget {
  const ChatView({super.key, required this.conversationId, this.dense = false});
  final int conversationId;
  final bool dense;
  @override
  ConsumerState<ChatView> createState() => _ChatViewState();
}

class _Msg {
  _Msg(this.m, {this.state = 'sent'});
  Map<String, dynamic> m;
  String state; // sent | sending | failed
  int? get id => m['id'] as int?;
}

class _ChatViewState extends ConsumerState<ChatView> {
  final List<_Msg> _msgs = [];
  final _text = TextEditingController();
  final _scroll = ScrollController();
  Map<int, String> _names = {};
  Map<String, dynamic>? _conv;
  Object? _error;
  bool _loading = true, _locked = false, _recording = false;
  Map<String, dynamic>? _replyTo;
  Timer? _poll;
  void Function()? _unsub;
  final _rec = AudioRecorder();

  int get _me => ref.read(sessionProvider).userId;

  @override
  void initState() {
    super.initState();
    _load();
    final s = ref.read(sessionProvider);
    _unsub = ref.read(realtimeProvider).subscribe(conversationChannel(s.activeSchoolId!, widget.conversationId), (e, d) {
      if (e == 'message.sent') _ingest([d]);
      if (e == 'message.deleted') _sync();
    });
    _poll = Timer.periodic(const Duration(seconds: 6), (_) => _sync());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _unsub?.call();
    _rec.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final api = ref.read(apiProvider);
      final p = await api.get('/conversations/${widget.conversationId}/participants');
      _names = {for (final x in p['data'] as List) x['user_id'] as int: x['name'] as String};
      _conv = Map<String, dynamic>.from(p['conversation']);
      _locked = _conv!['is_locked'] == true;
      final r = await api.get('/conversations/${widget.conversationId}/messages', query: {'limit': 80});
      _msgs
        ..clear()
        ..addAll((r['data'] as List).map((e) => _Msg(Map<String, dynamic>.from(e as Map))));
      _error = null;
    } catch (e) {
      _error = e;
    }
    if (mounted) setState(() => _loading = false);
    _toBottom();
  }

  /// Fetch only what we have not seen (also the reconnect path after an offline period).
  Future<void> _sync() async {
    final lastId = _msgs.map((m) => m.id ?? 0).fold<int>(0, (a, b) => a > b ? a : b);
    try {
      final r = await ref.read(apiProvider).get('/conversations/${widget.conversationId}/messages', query: {'after_id': lastId, 'mark_read': 1});
      final fresh = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      // Deleted messages: reflect tombstones for ids we already hold.
      if (fresh.isNotEmpty) _ingest(fresh);
    } catch (_) {/* offline: next tick */}
  }

  void _ingest(List<Map<String, dynamic>> list) {
    var changed = false;
    for (final m in list) {
      final existing = _msgs.indexWhere((x) => x.id == m['id'] || (m['client_id'] != null && x.m['client_id'] == m['client_id']));
      if (existing >= 0) {
        _msgs[existing]..m = m..state = 'sent';
      } else {
        _msgs.add(_Msg(m));
      }
      changed = true;
    }
    if (changed && mounted) { setState(() {}); _toBottom(); }
  }

  void _toBottom() => WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) _scroll.animateTo(_scroll.position.maxScrollExtent, duration: const Duration(milliseconds: 200), curve: Curves.easeOut);
      });

  Future<void> _send({List<int> fileIds = const [], Map<String, dynamic>? retry}) async {
    final body = retry?['body'] ?? _text.text.trim();
    if (body.toString().isEmpty && fileIds.isEmpty && retry == null) return;
    final clientId = retry?['client_id'] ?? const Uuid().v4();
    final reply = retry?['reply_to_id'] ?? _replyTo?['id'];
    final local = retry != null ? _msgs.firstWhere((m) => m.m['client_id'] == clientId) : _Msg({'id': null, 'user_id': _me, 'body': body, 'client_id': clientId, 'reply_to_id': reply, 'attachments': fileIds, 'kind': 'text', 'created_at': DateTime.now().toIso8601String()}, state: 'sending');
    if (retry == null) { _msgs.add(local); _text.clear(); _replyTo = null; }
    local.state = 'sending';
    setState(() {});
    _toBottom();
    try {
      final r = await ref.read(apiProvider).post('/conversations/${widget.conversationId}/messages', data: {'body': body, 'client_id': clientId, 'reply_to_id': reply, 'file_ids': fileIds}..removeWhere((k, v) => v == null || (v is List && v.isEmpty)));
      _ingest([Map<String, dynamic>.from(r['data'])]);
    } on ApiException catch (e) {
      local.state = e.isOffline ? 'failed' : 'rejected';
      if (!e.isOffline) { _msgs.remove(local); if (mounted) toast(context, e.readable, error: true); }
      if (mounted) setState(() {});
    }
  }

  Future<void> _attach() async {
    final files = await pickAndUpload(context, ref);
    if (files.isNotEmpty) await _send(fileIds: [for (final f in files) f['id'] as int]);
  }

  Future<void> _toggleRecord() async {
    if (!_recording) {
      if (!await _rec.hasPermission()) { if (mounted) toast(context, 'دسترسی میکروفون داده نشد.', error: true); return; }
      await _rec.start(const RecordConfig(encoder: AudioEncoder.opus), path: 'voice_${DateTime.now().millisecondsSinceEpoch}.webm');
      setState(() => _recording = true);
    } else {
      final path = await _rec.stop();
      setState(() => _recording = false);
      if (path == null) return;
      try {
        final bytes = await downloadSigned(path); // web: blob URL; mobile handled below
        final f = await ref.read(apiProvider).upload(PlatformFile(name: 'voice.webm', size: bytes.length, bytes: Uint8List.fromList(bytes)));
        await _send(fileIds: [f['id'] as int]);
      } catch (e) {
        if (mounted) toast(context, 'ارسال پیام صوتی ناموفق بود: ${e is ApiException ? e.readable : e}', error: true);
      }
    }
  }

  Future<void> _report(Map<String, dynamic> m) async {
    final reason = await promptText(context, 'گزارش محتوای نامناسب', label: 'دلیل');
    if (reason == null) return;
    try {
      await ref.read(apiProvider).post('/messages/${m['id']}/report', data: {'reason': reason});
      if (mounted) toast(context, 'گزارش شما برای مدیر مدرسه ارسال شد.');
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) return ErrorView(_error!, onRetry: _load);
    final s = ref.watch(sessionProvider);
    final canLock = s.active?.role != 'student' && s.active?.role != 'guardian';
    return Column(children: [
      if (_locked) Container(width: double.infinity, color: Palette.warnSoft, padding: const EdgeInsets.all(8), child: const Text('این گفت‌وگو قفل است؛ فقط معلم/مدیر می‌تواند پیام بدهد.', textAlign: TextAlign.center)),
      Expanded(
        child: _msgs.isEmpty
            ? const EmptyState('هنوز پیامی نیست. شما شروع کنید!', icon: Icons.chat_bubble_outline)
            : ListView.builder(
                controller: _scroll, padding: const EdgeInsets.all(12), itemCount: _msgs.length,
                itemBuilder: (_, i) {
                  final x = _msgs[i];
                  final mine = x.m['user_id'] == _me;
                  final reply = x.m['reply_to_id'] == null ? null : _msgs.where((q) => q.id == x.m['reply_to_id']).firstOrNull;
                  return Align(
                    alignment: mine ? AlignmentDirectional.centerStart : AlignmentDirectional.centerEnd,
                    child: GestureDetector(
                      onLongPress: x.id == null ? null : () => showModalBottomSheet(context: context, builder: (c) => SafeArea(child: Wrap(children: [
                            ListTile(leading: const Icon(Icons.reply), title: const Text('پاسخ'), onTap: () { Navigator.pop(c); setState(() => _replyTo = x.m); }),
                            if (!mine) ListTile(leading: const Icon(Icons.flag_outlined), title: const Text('گزارش محتوای نامناسب'), onTap: () { Navigator.pop(c); _report(x.m); }),
                          ]))),
                      child: Container(
                        constraints: BoxConstraints(maxWidth: widget.dense ? 260 : 460),
                        margin: const EdgeInsets.only(bottom: 8),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        decoration: BoxDecoration(color: mine ? Palette.brandSoft : Colors.white, borderRadius: BorderRadius.circular(14), border: Border.all(color: Palette.border)),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          if (!mine) Text(_names[x.m['user_id']] ?? '—', style: const TextStyle(fontWeight: FontWeight.w700, color: Palette.brand, fontSize: 12.5)),
                          if (reply != null) Container(margin: const EdgeInsets.only(bottom: 4), padding: const EdgeInsets.all(6), decoration: BoxDecoration(color: Colors.black.withValues(alpha: .04), borderRadius: BorderRadius.circular(8)), child: Text('${reply.m['body'] ?? 'پیوست'}', maxLines: 1, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.bodySmall)),
                          if (x.m['deleted'] == true) const Text('این پیام حذف شد.', style: TextStyle(color: Palette.muted, fontStyle: FontStyle.italic))
                          else ...[
                            if ((x.m['body'] ?? '').toString().isNotEmpty) SelectableText('${x.m['body']}'),
                            for (final f in (x.m['attachments'] as List? ?? [])) Padding(padding: const EdgeInsets.only(top: 6), child: FileChip(f as int)),
                          ],
                          Row(mainAxisSize: MainAxisSize.min, children: [
                            Text(fmtDate(x.m['created_at'], withTime: true), style: Theme.of(context).textTheme.bodySmall),
                            const SizedBox(width: 6),
                            if (x.state == 'sending') const SizedBox(width: 10, height: 10, child: CircularProgressIndicator(strokeWidth: 1.5)),
                            if (x.state == 'failed') InkWell(onTap: () => _send(retry: x.m), child: const Row(children: [Icon(Icons.refresh, size: 14, color: Palette.danger), Text(' ارسال مجدد', style: TextStyle(color: Palette.danger, fontSize: 12))])),
                          ]),
                        ]),
                      ),
                    ),
                  );
                },
              ),
      ),
      if (_replyTo != null) Container(color: Palette.brandSoft, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6), child: Row(children: [const Icon(Icons.reply, size: 16), const SizedBox(width: 6), Expanded(child: Text('پاسخ به: ${_replyTo!['body'] ?? 'پیوست'}', maxLines: 1, overflow: TextOverflow.ellipsis)), IconButton(onPressed: () => setState(() => _replyTo = null), icon: const Icon(Icons.close, size: 16))])),
      if (!_locked || canLock)
        Container(
          padding: const EdgeInsets.all(8), decoration: const BoxDecoration(color: Colors.white, border: Border(top: BorderSide(color: Palette.border))),
          child: Row(children: [
            IconButton(onPressed: _attach, icon: const Icon(Icons.attach_file), tooltip: 'پیوست فایل'),
            IconButton(onPressed: _toggleRecord, icon: Icon(_recording ? Icons.stop_circle : Icons.mic_none, color: _recording ? Palette.danger : null), tooltip: _recording ? 'پایان ضبط' : 'پیام صوتی'),
            Expanded(child: TextField(key: const Key('chat-input'), controller: _text, minLines: 1, maxLines: 4, onSubmitted: (_) => _send(), decoration: const InputDecoration(hintText: 'پیام...', isDense: true))),
            const SizedBox(width: 6),
            IconButton.filled(key: const Key('chat-send'), onPressed: () => _send(), icon: const Icon(Icons.send)),
          ]),
        ),
    ]);
  }
}
