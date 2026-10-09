import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

/// احراز هویت دومرحله‌ای (TOTP): enable with an authenticator app, recovery codes shown once, disable with password + code.
class TwoFactorCard extends ConsumerStatefulWidget {
  const TwoFactorCard({super.key});
  @override
  ConsumerState<TwoFactorCard> createState() => _TwoFactorState();
}

class _TwoFactorState extends ConsumerState<TwoFactorCard> {
  Map<String, dynamic>? _st;
  Map<String, dynamic>? _setup;
  List<String>? _recovery;
  final _code = TextEditingController();
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await ref.read(apiProvider).get('/me/2fa');
      if (mounted) setState(() => _st = Map<String, dynamic>.from(r as Map));
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  Future<void> _run(Future<void> Function() f) async {
    setState(() => _busy = true);
    try {
      await f();
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _start() => _run(() async {
        final r = await ref.read(apiProvider).post('/me/2fa/setup');
        setState(() => _setup = Map<String, dynamic>.from(r as Map));
      });

  Future<void> _confirm() => _run(() async {
        final r = await ref.read(apiProvider).post('/me/2fa/confirm', data: {'code': _code.text.trim()});
        _code.clear();
        setState(() { _setup = null; _recovery = List<String>.from(r['recovery_codes'] as List); });
        await _load();
        await _refreshSession();
      });

  Future<void> _refreshSession() async {
    final r = await ref.read(apiProvider).get('/auth/me');
    await ref.read(sessionProvider.notifier).refreshUser(Map<String, dynamic>.from(r['user'] as Map));
  }

  Future<void> _disable() async {
    final pw = await promptText(context, 'رمز عبور فعلی', label: 'رمز عبور', maxLines: 1);
    if (pw == null || !mounted) return;
    final code = await promptText(context, 'کد برنامهٔ احراز هویت یا کد بازیابی', label: 'کد', maxLines: 1);
    if (code == null) return;
    await _run(() async {
      await ref.read(apiProvider).delete('/me/2fa', data: {'password': pw, 'code': code.trim()});
      if (mounted) toast(context, 'احراز هویت دومرحله‌ای غیرفعال شد.');
      await _load();
    });
  }

  @override
  Widget build(BuildContext context) {
    final st = _st;
    if (st == null) return const AppCard(child: Padding(padding: EdgeInsets.all(8), child: LinearProgressIndicator()));
    final enabled = st['enabled'] == true;
    return AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        Icon(enabled ? Icons.verified_user : Icons.shield_outlined, color: enabled ? Palette.success : Palette.muted),
        const SizedBox(width: 10),
        Expanded(child: Text(enabled ? 'احراز هویت دومرحله‌ای فعال است' : 'احراز هویت دومرحله‌ای فعال نیست', style: Theme.of(context).textTheme.titleMedium)),
        if (enabled) Text('کد بازیابی باقی‌مانده: ${faDigits(st['recovery_codes_left'])}', style: Theme.of(context).textTheme.bodySmall),
      ]),
      if (st['required'] == true && !enabled) Padding(padding: const EdgeInsets.only(top: 8), child: Text('سیاست امنیتی پلتفرم فعال‌سازی را برای نقش شما اجباری کرده است.', style: TextStyle(color: Palette.danger))),
      if (_recovery != null) ...[
        const SizedBox(height: 12),
        const Text('کدهای بازیابی را همین حالا در جای امن ذخیره کنید؛ دیگر نمایش داده نمی‌شوند و هر کدام فقط یک بار کار می‌کند:', style: TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Wrap(spacing: 10, runSpacing: 6, children: [for (final c in _recovery!) Text(c, textDirection: TextDirection.ltr, style: const TextStyle(fontFamily: 'monospace'))]),
        Align(alignment: AlignmentDirectional.centerStart, child: TextButton.icon(onPressed: () { Clipboard.setData(ClipboardData(text: _recovery!.join('\n'))); toast(context, 'کپی شد.'); }, icon: const Icon(Icons.copy), label: const Text('کپی'))),
        TextButton(onPressed: () => setState(() => _recovery = null), child: const Text('ذخیره کردم')),
      ],
      if (_setup != null) ...[
        const SizedBox(height: 12),
        const Text('۱) QR را با برنامهٔ احراز هویت (Google Authenticator، Authy، …) اسکن کنید یا کلید را دستی وارد کنید.'),
        const SizedBox(height: 8),
        Center(child: QrImageView(data: _setup!['otpauth_uri'] as String, size: 170, backgroundColor: Colors.white)),
        Center(child: Text('${_setup!['secret']}', textDirection: TextDirection.ltr, style: const TextStyle(fontFamily: 'monospace', letterSpacing: 1.5))),
        Center(child: TextButton.icon(onPressed: () { Clipboard.setData(ClipboardData(text: '${_setup!['secret']}')); toast(context, 'کلید کپی شد.'); }, icon: const Icon(Icons.copy, size: 16), label: const Text('کپی کلید'))),
        const SizedBox(height: 8),
        const Text('۲) کد ۶ رقمی نمایش‌داده‌شده را وارد کنید:'),
        TextField(key: const Key('totp-code'), controller: _code, textDirection: TextDirection.ltr, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'کد تأیید')),
        const SizedBox(height: 8),
        FilledButton(key: const Key('totp-confirm'), onPressed: _busy ? null : _confirm, child: const Text('تأیید و فعال‌سازی')),
      ] else if (!enabled && _recovery == null) ...[
        const SizedBox(height: 8),
        FilledButton.icon(key: const Key('totp-start'), onPressed: _busy ? null : _start, icon: const Icon(Icons.qr_code_2), label: const Text('فعال‌سازی')),
      ] else if (enabled) ...[
        const SizedBox(height: 8),
        OutlinedButton(onPressed: _busy || st['required'] == true ? null : _disable, child: const Text('غیرفعال‌سازی')),
      ],
    ]));
  }
}
