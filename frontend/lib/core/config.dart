/// Build-time configuration (`--dart-define`). Nothing secret belongs here: the Reverb key is a public app key.
class AppConfig {
  /// REST base. On web behind the reverse proxy the default (same origin) works as-is.
  static const apiBase = String.fromEnvironment('API_BASE', defaultValue: '/api/v1');
  static const reverbHost = String.fromEnvironment('REVERB_HOST', defaultValue: '');
  static const reverbPort = int.fromEnvironment('REVERB_PORT', defaultValue: 8080);
  static const reverbScheme = String.fromEnvironment('REVERB_SCHEME', defaultValue: 'ws');
  static const reverbKey = String.fromEnvironment('REVERB_KEY', defaultValue: '');
  static bool get realtimeConfigured => reverbHost.isNotEmpty && reverbKey.isNotEmpty;
}
