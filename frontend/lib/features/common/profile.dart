import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/api.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import 'bell.dart';
import 'two_factor.dart';

class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key});
  @override
  ConsumerState<ProfileScreen> createState() => _ProfileState();
}

class _ProfileState extends ConsumerState<ProfileScreen> {
  Map<String, bool> _prefs = {};
  bool _loaded = false;

  @override
  void initState() {
    super.initState();
    if (ref.read(sessionProvider).active != null) _load();
  }

  Future<void> _load() async {
    try {
      final r = await ref.read(apiProvider).get('/me/notification-preferences');
      _prefs = {for (final p in r['data'] as List) '${p['type']}|${p['channel']}': p['enabled'] == true};
    } catch (_) {}
    if (mounted) setState(() => _loaded = true);
  }

  Future<void> _set(String channel, bool v) async {
    setState(() => _prefs['*|$channel'] = v);
    try {
      await ref.read(apiProvider).put('/me/notification-preferences', data: {'preferences': [{'type': '*', 'channel': channel, 'enabled': v}]});
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = ref.watch(sessionProvider);
    final bell = ref.watch(bellProvider);
    final hasSchool = s.active != null;
    return Scaffold(
      appBar: AppBar(title: const Text('پروفایل و تنظیمات'), leading: BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/'))),
      body: PageBody(maxWidth: 720, children: [
        AppCard(child: Row(children: [
          CircleAvatar(radius: 26, backgroundColor: Palette.brandSoft, child: Text(s.name.isEmpty ? '؟' : s.name.characters.first, style: const TextStyle(fontSize: 22, color: Palette.brand))),
          const SizedBox(width: 14),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(s.name, style: Theme.of(context).textTheme.titleLarge), Text('${s.user?['email'] ?? ''}', textDirection: TextDirection.ltr), Text(roleName(s.platformRole ?? s.active?.role), style: Theme.of(context).textTheme.bodySmall), if (hasSchool) Text(s.active!.schoolName, style: Theme.of(context).textTheme.bodySmall)])),
        ])),
        const SectionTitle('صدای زنگ مدرسه'),
        AppCard(child: Column(children: [
          SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('پخش صدای زنگ درون برنامه'), subtitle: const Text('مرورگرها پخش صدا را تا اولین کلیک شما و فقط وقتی صفحه باز است اجازه می‌دهند؛ برای اطمینان، اعلان‌ها هم ارسال می‌شود.'), value: bell.enabled, onChanged: (v) => ref.read(bellProvider.notifier).set(enabled: v)),
          Row(children: [const Icon(Icons.volume_down), Expanded(child: Slider(value: bell.volume, onChanged: (v) => ref.read(bellProvider.notifier).set(volume: v))), const Icon(Icons.volume_up)]),
          Align(alignment: AlignmentDirectional.centerStart, child: OutlinedButton.icon(onPressed: () => ref.read(bellProvider.notifier).ring(force: true), icon: const Icon(Icons.notifications_active_outlined), label: const Text('آزمایش صدا'))),
          if (bell.blocked) const Padding(padding: EdgeInsets.only(top: 8), child: Text('مرورگر پخش صدا را مسدود کرد؛ یک‌بار دکمهٔ آزمایش را بزنید.', style: TextStyle(color: Palette.warn))),
        ])),
        if (hasSchool) ...[
          const SectionTitle('اعلان‌های من'),
          AppCard(child: !_loaded ? const LinearProgressIndicator() : Column(children: [
            for (final c in const [('realtime', 'اعلان زنده درون برنامه'), ('push', 'اعلان فشاری (موبایل/وب)'), ('email', 'ایمیل (برای رویدادهای مهم)')])
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(c.$2), value: _prefs['*|${c.$1}'] ?? (c.$1 != 'email'), onChanged: (v) => _set(c.$1, v)),
          ])),
        ],
        const SectionTitle('امنیت حساب'),
        const TwoFactorCard(),
        const SectionTitle('حساب'),
        AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          OutlinedButton.icon(onPressed: () async { try { await ref.read(apiProvider).post('/auth/logout'); } catch (_) {} await ref.read(sessionProvider.notifier).signOut(); }, icon: const Icon(Icons.logout), label: const Text('خروج از حساب')),
        ])),
      ]),
    );
  }
}
