import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:livekit_client/livekit_client.dart' as lk;
import 'recordings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/realtime.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import '../common/chat.dart';
import 'whiteboard.dart';

enum _Phase { loading, preJoin, connecting, connected, ended, error }

/// Virtual classroom: pre-join → SFU room (LiveKit) with video grid, mic/camera/screen share, hand raise,
/// host controls (mute, remove, end, recording), shared whiteboard and class chat. Automatically re-joins after network loss.
class LiveScreen extends ConsumerStatefulWidget {
  const LiveScreen({super.key, required this.sessionId});
  final int sessionId;
  @override
  ConsumerState<LiveScreen> createState() => _LiveScreenState();
}

class _LiveScreenState extends ConsumerState<LiveScreen> with SingleTickerProviderStateMixin {
  _Phase _phase = _Phase.loading;
  Map<String, dynamic>? _session;
  Map<String, dynamic>? _join;
  List<Map<String, dynamic>> _hands = [];
  List<Map<String, dynamic>> _participants = [];
  String? _error, _errorCode;
  lk.Room? _room;
  lk.EventsListener<lk.RoomEvent>? _events;
  bool _reconnecting = false, _hand = false, _recording = false;
  int? _chatConversation;
  late final TabController _tabs = TabController(length: 3, vsync: this);
  Timer? _poll;
  void Function()? _unsub;
  bool _micOn = false, _camOn = false;

  bool get _isHost => _join?['role'] == 'host';
  bool get _observer => _join?['role'] == 'observer';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _poll?.cancel();
    _unsub?.call();
    _events?.dispose();
    _room?.disconnect();
    _room?.dispose();
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await ref.read(apiProvider).get('/sessions/${widget.sessionId}');
      _session = Map<String, dynamic>.from(r['data']);
      _hands = ((r['hands'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      _participants = ((r['participants'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      _recording = _session!['recording_enabled'] == true;
      final convs = await ref.read(apiProvider).get('/conversations');
      _chatConversation = ((convs['data'] as List).firstWhere((c) => c['type'] == 'class' && c['section_id'] == _session!['section_id'], orElse: () => null) as Map?)?['id'] as int?;
      setState(() => _phase = _session!['status'] == 'ended' || _session!['status'] == 'not_held' ? _Phase.ended : _Phase.preJoin);
    } on ApiException catch (e) {
      setState(() { _phase = _Phase.error; _error = e.readable; _errorCode = e.code; });
    }
  }

  Future<void> _start() async {
    await ref.read(apiProvider).post('/sessions/${widget.sessionId}/start');
    await _load();
  }

  Future<void> _connect() async {
    setState(() { _phase = _Phase.connecting; _error = null; });
    try {
      final api = ref.read(apiProvider);
      final j = await api.post('/sessions/${widget.sessionId}/join');
      _join = Map<String, dynamic>.from(j);
      final ice = ((j['ice_servers'] as List?) ?? []).map((s) => lk.RTCIceServer(urls: List<String>.from(s['urls']), username: s['username'], credential: s['credential'])).toList();
      final room = lk.Room(roomOptions: const lk.RoomOptions(adaptiveStream: true, dynacast: true));
      await room.connect(_join!['url'], _join!['token'], connectOptions: lk.ConnectOptions(rtcConfiguration: lk.RTCConfiguration(iceServers: ice.isEmpty ? null : ice)));
      _room = room;
      _events = room.createListener()
        ..on<lk.RoomReconnectingEvent>((_) => setState(() => _reconnecting = true))
        ..on<lk.RoomReconnectedEvent>((_) => setState(() => _reconnecting = false))
        ..on<lk.RoomDisconnectedEvent>((_) => _onDisconnected())
        ..on<lk.ParticipantConnectedEvent>((_) => setState(() {}))
        ..on<lk.ParticipantDisconnectedEvent>((_) => setState(() {}))
        ..on<lk.TrackSubscribedEvent>((_) => setState(() {}))
        ..on<lk.TrackUnsubscribedEvent>((_) => setState(() {}))
        ..on<lk.TrackMutedEvent>((_) => setState(() {}))
        ..on<lk.TrackUnmutedEvent>((_) => setState(() {}))
        ..on<lk.ActiveSpeakersChangedEvent>((_) => setState(() {}))
        ..on<lk.ParticipantConnectionQualityUpdatedEvent>((_) => setState(() {}));
      if (!_observer) {
        // Teacher starts with mic+camera; students join muted with camera off (they choose).
        await room.localParticipant?.setMicrophoneEnabled(_isHost);
        if (_isHost) await room.localParticipant?.setCameraEnabled(true);
        _micOn = _isHost;
        _camOn = _isHost;
      }
      final s = ref.read(sessionProvider);
      _unsub = ref.read(realtimeProvider).subscribe(sessionChannel(s.activeSchoolId!, widget.sessionId), (e, d) {
        if (e == 'session.ended') _onEnded();
        if (e.startsWith('hand.') || e.startsWith('participant.')) _refreshHost();
        if (e == 'participant.removed' && d['user_id'] == s.userId) _onEnded(message: 'شما از کلاس خارج شدید.');
        if (e == 'recording') setState(() => _recording = d['enabled'] == true);
      });
      _poll = Timer.periodic(const Duration(seconds: 6), (_) { _refreshHost(); _checkStatus(); });
      setState(() => _phase = _Phase.connected);
    } on ApiException catch (e) {
      setState(() { _phase = _Phase.error; _error = e.readable; _errorCode = e.code; });
    } catch (e) {
      setState(() { _phase = _Phase.error; _error = 'اتصال به کلاس برقرار نشد: $e'; });
    }
  }

  Future<void> _checkStatus() async {
    try {
      final r = await ref.read(apiProvider).get('/sessions/${widget.sessionId}');
      if (r['data']['status'] == 'ended') _onEnded();
    } catch (_) {}
  }

  Future<void> _refreshHost() async {
    if (!_isHost) return;
    try {
      final r = await ref.read(apiProvider).get('/sessions/${widget.sessionId}');
      if (!mounted) return;
      setState(() {
        _hands = ((r['hands'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _participants = ((r['participants'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      });
    } catch (_) {}
  }

  /// Network loss → fetch a fresh token and join again (the server record is truthful: a re-join closes the stale row).
  Future<void> _onDisconnected() async {
    if (!mounted || _phase != _Phase.connected) return;
    setState(() => _reconnecting = true);
    for (var i = 0; i < 6 && mounted && _phase == _Phase.connected; i++) {
      await Future<void>.delayed(Duration(seconds: 2 + i * 2));
      try {
        _events?.dispose();
        await _room?.dispose();
        _poll?.cancel();
        _unsub?.call();
        await _connect();
        if (_phase == _Phase.connected) { setState(() => _reconnecting = false); return; }
        if (_phase == _Phase.ended) return;
      } catch (_) {}
    }
    if (mounted) setState(() { _phase = _Phase.error; _error = 'ارتباط قطع شد و اتصال مجدد ممکن نبود.'; });
  }

  void _onEnded({String? message}) {
    if (!mounted || _phase == _Phase.ended) return;
    _room?.disconnect();
    _poll?.cancel();
    setState(() { _phase = _Phase.ended; _error = message; });
  }

  Future<void> _leave() async {
    try { await ref.read(apiProvider).post('/sessions/${widget.sessionId}/leave'); } catch (_) {}
    await _room?.disconnect();
    if (mounted) context.canPop() ? context.pop() : context.go('/');
  }

  Future<void> _endClass() async {
    if (!await confirm(context, 'کلاس برای همه پایان یابد و حضور و غیاب نهایی شود؟', danger: true, ok: 'پایان کلاس')) return;
    await ref.read(apiProvider).post('/sessions/${widget.sessionId}/end');
    _onEnded();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _phase == _Phase.connected ? const Color(0xFF0F1B2D) : Palette.bg,
      appBar: _phase == _Phase.connected ? null : AppBar(title: Text(_session?['title'] ?? 'کلاس آنلاین'), leading: BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/'))),
      body: switch (_phase) {
        _Phase.loading => const Center(child: CircularProgressIndicator()),
        _Phase.preJoin => _preJoin(),
        _Phase.connecting => const Center(child: Column(mainAxisSize: MainAxisSize.min, children: [CircularProgressIndicator(), SizedBox(height: 12), Text('در حال اتصال به کلاس...')])),
        _Phase.connected => _room_(),
        _Phase.ended => Center(child: Column(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.check_circle_outline, size: 56, color: Palette.success), const SizedBox(height: 10), Text(_error ?? 'کلاس پایان یافت.'), const SizedBox(height: 12), FilledButton(onPressed: () => context.canPop() ? context.pop() : context.go('/'), child: const Text('بازگشت'))])),
        _Phase.error => Center(child: Padding(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(_errorCode == 'media_unconfigured' ? Icons.settings_suggest_outlined : Icons.error_outline, size: 52, color: Palette.warn),
            const SizedBox(height: 10),
            Text(_error ?? 'خطا', textAlign: TextAlign.center),
            if (_errorCode == 'media_unconfigured') const Padding(padding: EdgeInsets.only(top: 8), child: Text('مدیر سامانه باید سرور رسانه (SFU) را پیکربندی کند.', textAlign: TextAlign.center)),
            const SizedBox(height: 14),
            Wrap(spacing: 8, children: [OutlinedButton(onPressed: _load, child: const Text('تلاش دوباره')), TextButton(onPressed: () => context.canPop() ? context.pop() : context.go('/'), child: const Text('بازگشت'))]),
          ]))),
      },
    );
  }

  Widget _preJoin() {
    final s = _session!;
    final isTeacher = ref.read(sessionProvider).active?.role == 'teacher';
    final live = s['status'] == 'live';
    return Center(
      child: Constrained(maxWidth: 520, child: AppCard(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('${s['title']}', style: Theme.of(context).textTheme.headlineSmall, textAlign: TextAlign.center),
        const SizedBox(height: 6),
        Center(child: StatusChip(statusName(s['status'] as String), tone: live ? Tone.success : Tone.neutral)),
        const SizedBox(height: 10),
        Text('${fmtDate(s['scheduled_start'], withTime: true)} تا ${fmtDate(s['scheduled_end'], withTime: true)}', textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodySmall),
        if (_recording) Container(margin: const EdgeInsets.only(top: 12), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Palette.warnSoft, borderRadius: BorderRadius.circular(10)), child: const Row(children: [Icon(Icons.fiber_manual_record, color: Palette.danger, size: 16), SizedBox(width: 8), Expanded(child: Text('این کلاس طبق سیاست مدرسه ضبط می‌شود.'))])),
        const SizedBox(height: 18),
        if (live) FilledButton.icon(key: const Key('join-live'), onPressed: _connect, icon: const Icon(Icons.login), label: const Text('ورود به کلاس'))
        else if (isTeacher) ActionButton(label: 'شروع کلاس', icon: Icons.play_arrow, onPressed: _start)
        else const Text('کلاس هنوز شروع نشده است؛ پس از شروع معلم اعلان می‌گیرید.', textAlign: TextAlign.center),
        if (live && isTeacher) const SizedBox(height: 4),
      ]))),
    );
  }

  // ------------------------------------------------------------------ connected room
  Widget _room_() {
    final room = _room!;
    final wide = MediaQuery.sizeOf(context).width >= 980;
    final side = _side();
    return SafeArea(
      child: Column(children: [
        if (_reconnecting) Container(width: double.infinity, color: Palette.warn, padding: const EdgeInsets.all(6), child: const Text('اتصال ضعیف است؛ در حال اتصال مجدد...', textAlign: TextAlign.center, style: TextStyle(color: Colors.white))),
        Expanded(child: wide ? Row(children: [Expanded(flex: 3, child: _stage(room)), SizedBox(width: 380, child: side)]) : Column(children: [Expanded(flex: 3, child: _stage(room)), Expanded(flex: 2, child: side)])),
        _controls(room),
      ]),
    );
  }

  List<lk.Participant> _all(lk.Room room) => [if (room.localParticipant != null) room.localParticipant!, ...room.remoteParticipants.values];

  /// Everyone in the room must always see when the class is being recorded.
  Widget _stage(lk.Room room) => Stack(children: [
        Positioned.fill(child: _stageBody(room)),
        if (_recording) Positioned(top: 10, left: 0, right: 0, child: Center(child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
          decoration: BoxDecoration(color: Palette.danger, borderRadius: BorderRadius.circular(999)),
          child: const Row(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.fiber_manual_record, color: Colors.white, size: 14), SizedBox(width: 6), Text('این کلاس در حال ضبط است', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700))]),
        ))),
      ]);

  Widget _stageBody(lk.Room room) {
    final parts = _all(room);
    final screen = parts.expand((p) => p.videoTrackPublications.where((t) => t.source == lk.TrackSource.screenShareVideo && t.track != null && !t.muted).map((t) => (p, t))).firstOrNull;
    if (screen != null) {
      return Column(children: [
        Expanded(child: Container(margin: const EdgeInsets.all(8), decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), color: Colors.black), clipBehavior: Clip.antiAlias, child: lk.VideoTrackRenderer(screen.$2.track as lk.VideoTrack, fit: lk.VideoViewFit.contain))),
        SizedBox(height: 96, child: ListView(scrollDirection: Axis.horizontal, children: [for (final p in parts) SizedBox(width: 140, child: _tile(p))])),
      ]);
    }
    final cols = parts.length <= 1 ? 1 : (parts.length <= 4 ? 2 : (parts.length <= 9 ? 3 : 4));
    return Padding(padding: const EdgeInsets.all(8), child: GridView.count(crossAxisCount: cols, crossAxisSpacing: 8, mainAxisSpacing: 8, childAspectRatio: 4 / 3, children: [for (final p in parts) _tile(p)]));
  }

  Widget _tile(lk.Participant p) {
    final cam = p.videoTrackPublications.where((t) => t.source == lk.TrackSource.camera && t.track != null && !t.muted).firstOrNull;
    final speaking = p.isSpeaking;
    final muted = !p.isMicrophoneEnabled();
    final raised = _hands.any((h) => 'u${h['user_id']}' == p.identity);
    return Container(
      decoration: BoxDecoration(color: const Color(0xFF1B2A41), borderRadius: BorderRadius.circular(12), border: Border.all(color: speaking ? Palette.success : Colors.transparent, width: 2)),
      clipBehavior: Clip.antiAlias,
      child: Stack(fit: StackFit.expand, children: [
        if (cam != null) lk.VideoTrackRenderer(cam.track as lk.VideoTrack, fit: lk.VideoViewFit.cover) else Center(child: CircleAvatar(radius: 28, backgroundColor: Palette.brand, child: Text(p.name.isEmpty ? '؟' : p.name.characters.first, style: const TextStyle(color: Colors.white, fontSize: 22)))),
        Positioned(bottom: 6, right: 6, left: 6, child: Row(children: [
          Expanded(child: Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2), decoration: BoxDecoration(color: Colors.black54, borderRadius: BorderRadius.circular(8)), child: Text(p.name.isEmpty ? p.identity : p.name, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 12)))),
          const SizedBox(width: 4),
          if (raised) const Icon(Icons.back_hand, color: Colors.amber, size: 18),
          Icon(muted ? Icons.mic_off : Icons.mic, color: muted ? Colors.redAccent : Colors.white, size: 16),
          _quality(p.connectionQuality),
        ])),
      ]),
    );
  }

  Widget _quality(lk.ConnectionQuality q) {
    final (bars, color) = switch (q) { lk.ConnectionQuality.excellent => (3, Colors.greenAccent), lk.ConnectionQuality.good => (2, Colors.lightGreen), lk.ConnectionQuality.poor => (1, Colors.orange), lk.ConnectionQuality.lost => (0, Colors.redAccent), _ => (0, Colors.grey) };
    return Tooltip(message: 'کیفیت اتصال', child: Row(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.end, children: [for (var i = 1; i <= 3; i++) Container(width: 3, height: 4.0 + i * 3, margin: const EdgeInsets.only(left: 1), color: i <= bars ? color : Colors.white24)]));
  }

  Widget _side() {
    return Container(
      color: Palette.bg,
      child: Column(children: [
        TabBar(controller: _tabs, tabs: [const Tab(text: 'گفت‌وگو'), const Tab(text: 'تخته'), Tab(text: _isHost ? 'شرکت‌کنندگان${_hands.isNotEmpty ? ' (${faDigits(_hands.length)}✋)' : ''}' : 'درباره')]),
        Expanded(child: TabBarView(controller: _tabs, physics: const NeverScrollableScrollPhysics(), children: [
          _chatConversation == null ? const EmptyState('گفت‌وگوی کلاس در دسترس نیست.') : ChatView(conversationId: _chatConversation!, dense: true),
          Whiteboard(sessionId: widget.sessionId, canDraw: _isHost),
          _isHost ? _hostPanel() : _aboutPanel(),
        ])),
      ]),
    );
  }

  Widget _aboutPanel() => ListView(padding: const EdgeInsets.all(16), children: [
        Text('${_session?['title']}', style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 8),
        if (_recording) const StatusChip('در حال ضبط', tone: Tone.danger),
        const SizedBox(height: 12),
        Text('برای سؤال پرسیدن «دست بلند کردن» را بزنید؛ معلم به ترتیب نوبت می‌دهد.', style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 12),
        OutlinedButton.icon(onPressed: () async {
          final t = await showDialog<String>(context: context, builder: (c) => SimpleDialog(title: const Text('گزارش مشکل فنی'), children: [for (final o in const [('audio', 'صدا'), ('video', 'تصویر'), ('connection', 'اتصال'), ('teacher_absent', 'معلم حاضر نیست'), ('other', 'سایر')]) SimpleDialogOption(onPressed: () => Navigator.pop(c, o.$1), child: Text(o.$2))]));
          if (t != null) { await ref.read(apiProvider).post('/sessions/${widget.sessionId}/issues', data: {'type': t}); if (mounted) toast(context, 'گزارش شما ثبت شد.'); }
        }, icon: const Icon(Icons.report_problem_outlined), label: const Text('گزارش مشکل فنی')),
      ]);

  Widget _hostPanel() {
    final room = _room!;
    final names = {for (final p in _all(room)) p.identity: p.name};
    return ListView(padding: const EdgeInsets.all(12), children: [
      if (_hands.isNotEmpty) ...[
        const Text('نوبت صحبت (دست‌های بالا)', style: TextStyle(fontWeight: FontWeight.w700)),
        for (var i = 0; i < _hands.length; i++) ListTile(dense: true, leading: CircleAvatar(radius: 14, child: Text(faDigits(i + 1))), title: Text(names['u${_hands[i]['user_id']}'] ?? 'کاربر ${_hands[i]['user_id']}'), trailing: TextButton(onPressed: () => ref.read(apiProvider).put('/sessions/${widget.sessionId}/participants/${_hands[i]['user_id']}/mute', data: {'muted': false}).then((_) => toast(context, 'میکروفون باز شد.')), child: const Text('اجازهٔ صحبت'))),
        const Divider(),
      ],
      const Text('شرکت‌کنندگان', style: TextStyle(fontWeight: FontWeight.w700)),
      for (final p in _participants.where((x) => x['role'] != 'host'))
        ListTile(
          dense: true, leading: const Icon(Icons.person_outline), title: Text(names['u${p['user_id']}'] ?? 'کاربر ${p['user_id']}'),
          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
            IconButton(tooltip: 'قطع میکروفون', icon: const Icon(Icons.mic_off), onPressed: () async { try { await ref.read(apiProvider).put('/sessions/${widget.sessionId}/participants/${p['user_id']}/mute', data: {'muted': true}); } on ApiException catch (e) { if (mounted) toast(context, e.readable, error: true); } }),
            IconButton(tooltip: 'خروج از کلاس', icon: const Icon(Icons.person_remove_outlined, color: Palette.danger), onPressed: () async { if (await confirm(context, 'این فرد از کلاس خارج شود؟', danger: true)) { await ref.read(apiProvider).delete('/sessions/${widget.sessionId}/participants/${p['user_id']}'); _refreshHost(); } }),
          ]),
        ),
      if (_participants.where((x) => x['role'] != 'host').isEmpty) const Padding(padding: EdgeInsets.all(12), child: Text('هنوز کسی وارد نشده است.')),
      const Divider(),
      TextButton.icon(onPressed: () => showRecordings(context, ref, widget.sessionId), icon: const Icon(Icons.video_library_outlined), label: const Text('ضبط‌های این کلاس')),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('ضبط کلاس'), subtitle: const Text('فقط در صورت فعال‌بودن سیاست ضبط در مدرسه؛ شرکت‌کنندگان مطلع می‌شوند.'), value: _recording, onChanged: (v) async {
        try { final r = await ref.read(apiProvider).put('/sessions/${widget.sessionId}/recording', data: {'enabled': v}); setState(() => _recording = r['data']['recording_enabled'] == true); } on ApiException catch (e) { if (mounted) toast(context, e.readable, error: true); }
      }),
    ]);
  }

  Widget _controls(lk.Room room) {
    final lp = room.localParticipant;
    Widget btn(IconData on, IconData off, bool active, String tip, VoidCallback? cb, {Color? bg, Key? key}) => Padding(padding: const EdgeInsets.symmetric(horizontal: 5), child: IconButton.filled(key: key, tooltip: tip, onPressed: cb, style: IconButton.styleFrom(backgroundColor: bg ?? (active ? Colors.white24 : Colors.redAccent), foregroundColor: Colors.white, fixedSize: const Size(48, 48)), icon: Icon(active ? on : off)));
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 8), color: const Color(0xFF0B1422),
      child: SingleChildScrollView(scrollDirection: Axis.horizontal, child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        if (!_observer) ...[
          btn(Icons.mic, Icons.mic_off, _micOn, 'میکروفون', () async { final v = !_micOn; await lp?.setMicrophoneEnabled(v); setState(() => _micOn = v); }, key: const Key('toggle-mic')),
          btn(Icons.videocam, Icons.videocam_off, _camOn, 'دوربین', () async { final v = !_camOn; await lp?.setCameraEnabled(v); setState(() => _camOn = v); }, key: const Key('toggle-cam')),
          if (_isHost) btn(Icons.screen_share, Icons.screen_share, lp?.isScreenShareEnabled() ?? false, 'اشتراک صفحه', () async { try { await lp?.setScreenShareEnabled(!(lp.isScreenShareEnabled())); setState(() {}); } catch (e) { if (mounted) toast(context, 'اشتراک صفحه ممکن نشد.', error: true); } }, bg: Colors.white24),
          if (!_isHost) btn(Icons.back_hand, Icons.back_hand_outlined, _hand, 'دست بلند کردن', () async { final v = !_hand; await ref.read(apiProvider).put('/sessions/${widget.sessionId}/hand', data: {'raise': v}); setState(() => _hand = v); }, bg: _hand ? Colors.amber.shade700 : Colors.white24, key: const Key('raise-hand')),
        ],
        const SizedBox(width: 10),
        if (_isHost) FilledButton.icon(onPressed: _endClass, style: FilledButton.styleFrom(backgroundColor: Palette.danger), icon: const Icon(Icons.stop_circle_outlined), label: const Text('پایان کلاس')),
        const SizedBox(width: 6),
        OutlinedButton.icon(key: const Key('leave-live'), onPressed: _leave, style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white38)), icon: const Icon(Icons.logout), label: const Text('خروج')),
      ])),
    );
  }
}
