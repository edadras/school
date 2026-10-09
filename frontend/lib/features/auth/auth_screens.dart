import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/api.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/forms.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

class _AuthFrame extends StatelessWidget {
  const _AuthFrame({required this.title, required this.child, this.subtitle});
  final String title;
  final String? subtitle;
  final Widget child;
  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Center(child: Container(width: 64, height: 64, decoration: BoxDecoration(color: Palette.brandSoft, borderRadius: BorderRadius.circular(18)), child: const Icon(Icons.school_rounded, size: 34, color: Palette.brand))),
                  const SizedBox(height: 16),
                  Text(title, textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineSmall),
                  if (subtitle != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(subtitle!, textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodySmall)),
                  const SizedBox(height: 20),
                  AppCard(padding: const EdgeInsets.all(20), child: child),
                ]),
              ),
            ),
          ),
        ),
      );
}

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});
  @override
  ConsumerState<LoginScreen> createState() => _LoginState();
}

class _LoginState extends ConsumerState<LoginScreen> {
  final _login = TextEditingController();
  final _pass = TextEditingController();
  final _form = GlobalKey<FormState>();
  final _code = TextEditingController();
  String? _error, _challenge;
  bool _busy = false, _show = false;

  Future<void> _verify() async {
    if (_code.text.trim().isEmpty) return;
    setState(() { _busy = true; _error = null; });
    try {
      final r = await ref.read(apiProvider).post('/auth/2fa/verify', data: {'challenge': _challenge, 'code': _code.text.trim(), 'device_name': 'web'});
      await ref.read(sessionProvider.notifier).signIn(r['token'], Map<String, dynamic>.from(r['user']));
    } on ApiException catch (e) {
      setState(() => _error = e.readable);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() { _busy = true; _error = null; });
    try {
      final api = ref.read(apiProvider);
      final r = await api.post('/auth/login', data: {'login': _login.text.trim(), 'password': _pass.text, 'device_name': 'web'});
      if (r['two_factor_required'] == true) {
        setState(() => _challenge = r['challenge'] as String);
        return;
      }
      await ref.read(sessionProvider.notifier).signIn(r['token'], Map<String, dynamic>.from(r['user']));
    } on ApiException catch (e) {
      setState(() => _error = e.readable);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _twoFactor() => _AuthFrame(
        title: 'تأیید دومرحله‌ای',
        subtitle: 'کد ۶ رقمی برنامهٔ احراز هویت (یا یکی از کدهای بازیابی) را وارد کنید',
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (_error != null) Container(margin: const EdgeInsets.only(bottom: 12), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Palette.dangerSoft, borderRadius: BorderRadius.circular(10)), child: Text(_error!, key: const Key('login-error'), style: const TextStyle(color: Palette.danger))),
          TextField(key: const Key('code-field'), controller: _code, autofocus: true, textDirection: TextDirection.ltr, keyboardType: TextInputType.text, onSubmitted: (_) => _verify(), decoration: const InputDecoration(labelText: 'کد تأیید')),
          const SizedBox(height: 18),
          FilledButton(key: const Key('verify-button'), onPressed: _busy ? null : _verify, child: const Text('تأیید و ورود')),
          TextButton(onPressed: () => setState(() { _challenge = null; _code.clear(); _error = null; }), child: const Text('بازگشت')),
        ]),
      );

  @override
  Widget build(BuildContext context) => _challenge != null ? _twoFactor() : _AuthFrame(
        title: 'ورود به سامانه مدرسه',
        subtitle: 'برای ادامه وارد حساب خود شوید',
        child: Form(
          key: _form,
          child: AutofillGroup(
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (_error != null) Container(margin: const EdgeInsets.only(bottom: 12), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Palette.dangerSoft, borderRadius: BorderRadius.circular(10)), child: Text(_error!, key: const Key('login-error'), style: const TextStyle(color: Palette.danger))),
              TextFormField(key: const Key('login-field'), controller: _login, autofillHints: const [AutofillHints.username], textDirection: TextDirection.ltr, decoration: const InputDecoration(labelText: 'ایمیل یا شمارهٔ موبایل'), validator: (v) => (v ?? '').trim().isEmpty ? 'الزامی است.' : null),
              const SizedBox(height: 14),
              TextFormField(key: const Key('password-field'), controller: _pass, obscureText: !_show, autofillHints: const [AutofillHints.password], textDirection: TextDirection.ltr, onFieldSubmitted: (_) => _submit(),
                  decoration: InputDecoration(labelText: 'رمز عبور', suffixIcon: IconButton(icon: Icon(_show ? Icons.visibility_off : Icons.visibility), onPressed: () => setState(() => _show = !_show))), validator: (v) => (v ?? '').isEmpty ? 'الزامی است.' : null),
              const SizedBox(height: 18),
              FilledButton(key: const Key('login-button'), onPressed: _busy ? null : _submit, child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Text('ورود')),
              const SizedBox(height: 8),
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                TextButton(onPressed: () => context.go('/forgot'), child: const Text('فراموشی رمز عبور')),
                TextButton(key: const Key('register-link'), onPressed: () => context.go('/register-school'), child: const Text('ثبت مدرسه جدید')),
              ]),
            ]),
          ),
        ),
      );
}

class ForgotScreen extends ConsumerStatefulWidget {
  const ForgotScreen({super.key});
  @override
  ConsumerState<ForgotScreen> createState() => _ForgotState();
}

class _ForgotState extends ConsumerState<ForgotScreen> {
  final _email = TextEditingController();
  bool _sent = false;
  @override
  Widget build(BuildContext context) => _AuthFrame(
        title: 'بازیابی رمز عبور',
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (_sent) const Text('در صورت وجود حساب، پیوند بازیابی به ایمیل شما ارسال شد.') else ...[
            TextField(controller: _email, textDirection: TextDirection.ltr, decoration: const InputDecoration(labelText: 'ایمیل')),
            const SizedBox(height: 14),
            ActionButton(label: 'ارسال پیوند بازیابی', onPressed: () async { await ref.read(apiProvider).post('/auth/forgot-password', data: {'email': _email.text.trim()}); setState(() => _sent = true); }),
          ],
          TextButton(onPressed: () => context.go('/login'), child: const Text('بازگشت به ورود')),
        ]),
      );
}

class ResetPasswordScreen extends ConsumerStatefulWidget {
  const ResetPasswordScreen({super.key, required this.token, required this.email});
  final String token, email;
  @override
  ConsumerState<ResetPasswordScreen> createState() => _ResetState();
}

class _ResetState extends ConsumerState<ResetPasswordScreen> {
  final _p1 = TextEditingController();
  final _p2 = TextEditingController();
  @override
  Widget build(BuildContext context) => _AuthFrame(
        title: 'رمز عبور جدید',
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          TextField(controller: _p1, obscureText: true, decoration: const InputDecoration(labelText: 'رمز جدید (حداقل ۱۰ نویسه)')),
          const SizedBox(height: 12),
          TextField(controller: _p2, obscureText: true, decoration: const InputDecoration(labelText: 'تکرار رمز')),
          const SizedBox(height: 14),
          ActionButton(label: 'تغییر رمز', onPressed: () async {
            await ref.read(apiProvider).post('/auth/reset-password', data: {'token': widget.token, 'email': widget.email, 'password': _p1.text, 'password_confirmation': _p2.text});
            if (mounted) { toast(context, 'رمز عبور تغییر کرد؛ وارد شوید.'); context.go('/login'); }
          }),
        ]),
      );
}

/// Organisation sign-up. The school stays "pending" until the platform admin approves it.
class RegisterSchoolScreen extends ConsumerStatefulWidget {
  const RegisterSchoolScreen({super.key});
  @override
  ConsumerState<RegisterSchoolScreen> createState() => _RegisterState();
}

class _RegisterState extends ConsumerState<RegisterSchoolScreen> {
  final _form = GlobalKey<FormState>();
  final c = {for (final k in ['name', 'code', 'city', 'phone', 'email', 'address', 'owner_name', 'owner_email', 'owner_phone', 'password', 'password2']) k: TextEditingController()};
  Map<String, List<String>> _errors = {};
  String? _top;
  bool _busy = false, _done = false;

  String? _v(String key, {bool req = true, String? serverKey}) {
    final s = _errors[serverKey ?? key]?.first;
    if (s != null) return s;
    if (req && c[key]!.text.trim().isEmpty) return 'الزامی است.';
    return null;
  }

  Future<void> _submit() async {
    _errors = {};
    if (!_form.currentState!.validate()) return;
    if (c['password']!.text != c['password2']!.text) { setState(() => _top = 'تکرار رمز عبور یکسان نیست.'); return; }
    setState(() { _busy = true; _top = null; });
    try {
      await ref.read(apiProvider).post('/schools/register', data: {
        'school': {'name': c['name']!.text.trim(), 'code': c['code']!.text.trim().toLowerCase(), 'city': _n(c['city']!), 'phone': _n(c['phone']!), 'email': _n(c['email']!), 'address': _n(c['address']!), 'timezone': 'Asia/Tehran'},
        'owner': {'name': c['owner_name']!.text.trim(), 'email': c['owner_email']!.text.trim(), 'phone': _n(c['owner_phone']!), 'password': c['password']!.text, 'password_confirmation': c['password2']!.text},
      });
      setState(() => _done = true);
    } on ApiException catch (e) {
      setState(() { _errors = e.errors.map((k, v) => MapEntry(k.replaceFirst('school.', '').replaceFirst('owner.', k.startsWith('owner.') ? 'owner_' : ''), v)); _top = e.errors.isEmpty ? e.message : 'لطفاً خطاهای فرم را اصلاح کنید.'; });
      _form.currentState?.validate();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? _n(TextEditingController t) => t.text.trim().isEmpty ? null : t.text.trim();

  Widget _f(String key, String label, {bool req = true, bool ltr = false, bool pass = false, String? serverKey, int lines = 1}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextFormField(key: Key('reg-$key'), controller: c[key], obscureText: pass, maxLines: lines, textDirection: ltr ? TextDirection.ltr : null, decoration: InputDecoration(labelText: label + (req ? ' *' : '')), validator: (_) => _v(key, req: req, serverKey: serverKey)),
      );

  @override
  Widget build(BuildContext context) {
    if (_done) {
      return _AuthFrame(title: 'درخواست شما ثبت شد', child: Column(children: [
        const Icon(Icons.check_circle, color: Palette.success, size: 48),
        const SizedBox(height: 12),
        const Text('درخواست فعال‌سازی مدرسه برای مدیر کل سامانه ارسال شد. پس از تأیید می‌توانید وارد شوید و مدرسهٔ خود را بسازید.', textAlign: TextAlign.center),
        const SizedBox(height: 12),
        FilledButton(onPressed: () => context.go('/login'), child: const Text('رفتن به صفحهٔ ورود')),
      ]));
    }
    return _AuthFrame(
      title: 'ثبت‌نام سازمانی مدرسه', subtitle: 'اطلاعات مدرسه و مدیر را وارد کنید؛ درخواست شما توسط مدیر کل سامانه بررسی می‌شود.',
      child: Form(key: _form, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (_top != null) Container(margin: const EdgeInsets.only(bottom: 12), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Palette.dangerSoft, borderRadius: BorderRadius.circular(10)), child: Text(_top!, style: const TextStyle(color: Palette.danger))),
        Text('اطلاعات مدرسه', style: Theme.of(context).textTheme.titleMedium), const SizedBox(height: 10),
        _f('name', 'نام مدرسه'), _f('code', 'شناسهٔ یکتا (انگلیسی، مثل hope-school)', ltr: true), _f('city', 'شهر', req: false), _f('phone', 'تلفن', req: false, ltr: true), _f('email', 'ایمیل مدرسه', req: false, ltr: true), _f('address', 'نشانی', req: false, lines: 2),
        const Divider(), Text('اطلاعات مدیر مدرسه', style: Theme.of(context).textTheme.titleMedium), const SizedBox(height: 10),
        _f('owner_name', 'نام و نام خانوادگی', serverKey: 'owner_name'), _f('owner_email', 'ایمیل (نام کاربری)', ltr: true, serverKey: 'owner_email'), _f('owner_phone', 'موبایل', req: false, ltr: true, serverKey: 'owner_phone'),
        _f('password', 'رمز عبور (حداقل ۱۰ نویسه با حرف و عدد)', pass: true, ltr: true, serverKey: 'owner_password'), _f('password2', 'تکرار رمز عبور', pass: true, ltr: true),
        FilledButton(key: const Key('reg-submit'), onPressed: _busy ? null : _submit, child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Text('ارسال درخواست')),
        TextButton(onPressed: () => context.go('/login'), child: const Text('حساب دارم؛ ورود')),
      ])),
    );
  }
}

class ChooseSchoolScreen extends ConsumerWidget {
  const ChooseSchoolScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(sessionProvider);
    return _AuthFrame(title: 'کدام مدرسه؟', subtitle: 'حساب شما در چند مدرسه عضو است', child: Column(children: [
      for (final m in s.memberships)
        ListTile(leading: const Icon(Icons.apartment), title: Text(m.schoolName), subtitle: Text(roleName(m.role)), trailing: m.schoolStatus == 'active' ? null : StatusChip(statusName(m.schoolStatus), tone: Tone.warn), onTap: () => ref.read(sessionProvider.notifier).chooseSchool(m.schoolId)),
      TextButton(onPressed: () => ref.read(sessionProvider.notifier).signOut(), child: const Text('خروج')),
    ]));
  }
}

/// Shown to a school owner whose organisation is not active yet (pending / needs changes / rejected / suspended).
class PendingScreen extends ConsumerStatefulWidget {
  const PendingScreen({super.key});
  @override
  ConsumerState<PendingScreen> createState() => _PendingState();
}

class _PendingState extends ConsumerState<PendingScreen> {
  Map<String, dynamic>? _req;
  Object? _err;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await ref.read(apiProvider).get('/schools/my-request');
      setState(() => _req = Map<String, dynamic>.from(r['request']));
      // Approved meanwhile? Refresh the profile so the shell unlocks.
      if (_req!['status'] == 'approved') {
        final me = await ref.read(apiProvider).get('/auth/me');
        await ref.read(sessionProvider.notifier).refreshUser(Map<String, dynamic>.from(me['user']));
      }
    } catch (e) {
      setState(() => _err = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final status = _req?['status'] as String? ?? 'pending';
    final m = ref.watch(sessionProvider).memberships.firstOrNull;
    final suspended = m?.schoolStatus == 'suspended';
    return _AuthFrame(
      title: suspended ? 'مدرسه تعلیق شده است' : 'وضعیت درخواست مدرسه',
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (_err != null) ErrorView(_err!, onRetry: _load) else ...[
          Center(child: StatusChip(statusName(suspended ? 'suspended' : status), tone: status == 'approved' ? Tone.success : (status == 'rejected' || suspended ? Tone.danger : Tone.warn))),
          const SizedBox(height: 12),
          if (_req?['decision_note'] != null) AppCard(color: Palette.brandSoft, child: Text('توضیح مدیر کل: ${_req!['decision_note']}')),
          const SizedBox(height: 8),
          Text(switch (status) {
            'needs_changes' => 'لطفاً اطلاعات را اصلاح و دوباره ارسال کنید.',
            'rejected' => 'درخواست شما رد شده است. برای پیگیری با پشتیبانی تماس بگیرید.',
            _ => suspended ? 'برای رفع تعلیق با مدیر کل سامانه تماس بگیرید.' : 'درخواست شما در صف بررسی مدیر کل است. این صفحه را می‌توانید بعداً دوباره باز کنید.',
          }, textAlign: TextAlign.center),
          const SizedBox(height: 12),
          if (status == 'needs_changes')
            FilledButton(onPressed: () async {
              final ok = await showForm(context, title: 'اصلاح اطلاعات مدرسه', fields: const [FieldSpec('name', 'نام مدرسه'), FieldSpec('phone', 'تلفن'), FieldSpec('email', 'ایمیل', type: FieldType.email), FieldSpec('address', 'نشانی', type: FieldType.multiline), FieldSpec('city', 'شهر')],
                  submit: (v) async => ref.read(apiProvider).post('/schools/my-request/resubmit', data: Map.of(v)..removeWhere((k, x) => x == null)), submitLabel: 'ارسال مجدد');
              if (ok) _load();
            }, child: const Text('اصلاح و ارسال مجدد')),
          OutlinedButton(onPressed: _load, child: const Text('بروزرسانی وضعیت')),
        ],
        TextButton(onPressed: () => ref.read(sessionProvider.notifier).signOut(), child: const Text('خروج')),
      ]),
    );
  }
}
