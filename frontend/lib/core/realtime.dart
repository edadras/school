import 'dart:async';
import 'dart:convert';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:web_socket_channel/web_socket_channel.dart';
import 'api.dart';
import 'config.dart';
import 'session.dart';

enum RtStatus { off, connecting, online }

typedef RtHandler = void Function(String event, Map<String, dynamic> data);

/// Minimal Pusher-protocol client (Laravel Reverb speaks it). Private channels are authorised by the API
/// (`/broadcasting/auth`) which re-checks membership server-side. Auto-reconnects with backoff and re-subscribes.
/// Realtime is an accelerator only: every screen also works via REST polling / `since_id` sync.
class RealtimeClient {
  RealtimeClient(this._api);
  final Api _api;
  WebSocketChannel? _ch;
  String? _socketId;
  final Map<String, List<RtHandler>> _subs = {};
  final _status = StreamController<RtStatus>.broadcast();
  RtStatus current = RtStatus.off;
  Timer? _retry;
  int _attempt = 0;
  bool _disposed = false;

  Stream<RtStatus> get status => _status.stream;
  bool get available => AppConfig.realtimeConfigured;

  void _set(RtStatus s) {
    current = s;
    if (!_status.isClosed) _status.add(s);
  }

  void connect() {
    if (!available || _disposed || current != RtStatus.off) return;
    _set(RtStatus.connecting);
    final url = '${AppConfig.reverbScheme}://${AppConfig.reverbHost}:${AppConfig.reverbPort}/app/${AppConfig.reverbKey}?protocol=7&client=school&version=1';
    try {
      _ch = WebSocketChannel.connect(Uri.parse(url));
      _ch!.stream.listen(_onMessage, onDone: _lost, onError: (_) => _lost(), cancelOnError: true);
    } catch (_) {
      _lost();
    }
  }

  void _lost() {
    _ch = null;
    _socketId = null;
    if (_disposed) return;
    _set(RtStatus.off);
    _retry?.cancel();
    _attempt++;
    _retry = Timer(Duration(seconds: (1 << (_attempt.clamp(0, 5))).clamp(1, 30)), connect);
  }

  Future<void> _onMessage(dynamic raw) async {
    final m = jsonDecode(raw as String) as Map<String, dynamic>;
    final ev = m['event'] as String;
    final data = m['data'] is String && (m['data'] as String).isNotEmpty ? jsonDecode(m['data'] as String) : m['data'];
    switch (ev) {
      case 'pusher:connection_established':
        _socketId = (data as Map)['socket_id'] as String;
        _attempt = 0;
        _set(RtStatus.online);
        for (final c in _subs.keys) {
          _subscribe(c);
        }
      case 'pusher:ping':
        _ch?.sink.add(jsonEncode({'event': 'pusher:pong', 'data': {}}));
      default:
        final ch = m['channel'] as String?;
        if (ch != null && !ev.startsWith('pusher')) {
          for (final h in List.of(_subs[ch] ?? [])) {
            h(ev, data is Map ? Map<String, dynamic>.from(data) : {});
          }
        }
    }
  }

  Future<void> _subscribe(String channel) async {
    if (_socketId == null) return;
    try {
      final r = await _api.post('/broadcasting/auth', data: {'socket_id': _socketId, 'channel_name': channel});
      _ch?.sink.add(jsonEncode({'event': 'pusher:subscribe', 'data': {'auth': r['auth'], 'channel': channel}}));
    } catch (_) {/* unauthorised channel: silently not subscribed */}
  }

  /// Returns an unsubscribe callback.
  void Function() subscribe(String channel, RtHandler h) {
    final first = !_subs.containsKey(channel);
    (_subs[channel] ??= []).add(h);
    connect();
    if (first && current == RtStatus.online) _subscribe(channel);
    return () {
      _subs[channel]?.remove(h);
      if (_subs[channel]?.isEmpty ?? false) {
        _subs.remove(channel);
        _ch?.sink.add(jsonEncode({'event': 'pusher:unsubscribe', 'data': {'channel': channel}}));
      }
    };
  }

  void dispose() {
    _disposed = true;
    _retry?.cancel();
    _ch?.sink.close();
    _status.close();
  }
}

final realtimeProvider = Provider<RealtimeClient>((ref) {
  final c = RealtimeClient(ref.watch(apiProvider));
  ref.onDispose(c.dispose);
  return c;
});

String userChannel(int schoolId, int userId) => 'private-school.$schoolId.user.$userId';
String sessionChannel(int schoolId, int sessionId) => 'private-school.$schoolId.session.$sessionId';
String conversationChannel(int schoolId, int id) => 'private-school.$schoolId.conversation.$id';
