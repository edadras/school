import 'package:audioplayers/audioplayers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// In-app bell. Browsers only allow audio after a user gesture and not while the tab is closed; mobile OSes limit
/// background audio. Reliable delivery therefore also uses the notification feed + (when configured) push — see docs/realtime-and-media.md.
class BellSettings {
  const BellSettings({this.enabled = true, this.volume = 0.8, this.blocked = false});
  final bool enabled;
  final double volume;
  final bool blocked; // last play attempt was rejected by the browser (needs a click first)
  BellSettings copy({bool? enabled, double? volume, bool? blocked}) => BellSettings(enabled: enabled ?? this.enabled, volume: volume ?? this.volume, blocked: blocked ?? this.blocked);
}

class BellController extends StateNotifier<BellSettings> {
  BellController() : super(const BellSettings()) {
    _load();
  }
  final _player = AudioPlayer();

  Future<void> _load() async {
    final p = await SharedPreferences.getInstance();
    state = state.copy(enabled: p.getBool('bell.enabled') ?? true, volume: p.getDouble('bell.volume') ?? 0.8);
  }

  Future<void> set({bool? enabled, double? volume}) async {
    state = state.copy(enabled: enabled, volume: volume);
    final p = await SharedPreferences.getInstance();
    await p.setBool('bell.enabled', state.enabled);
    await p.setDouble('bell.volume', state.volume);
  }

  Future<void> ring({bool force = false}) async {
    if (!state.enabled && !force) return;
    try {
      await _player.setVolume(state.volume);
      await _player.play(AssetSource('sounds/bell.wav'));
      if (state.blocked) state = state.copy(blocked: false);
    } catch (_) {
      state = state.copy(blocked: true);
    }
  }

  @override
  void dispose() {
    _player.dispose();
    super.dispose();
  }
}

final bellProvider = StateNotifierProvider<BellController, BellSettings>((ref) => BellController());
