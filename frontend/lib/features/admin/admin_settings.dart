import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../design/forms.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

final _aiStatusProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async => Map<String, dynamic>.from(await ref.read(apiProvider).get('/ai/status')));
final _aiUsageProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async => Map<String, dynamic>.from(await ref.read(apiProvider).get('/ai/usage')));
final _profileProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async => Map<String, dynamic>.from(await ref.read(apiProvider).get('/school/profile')));

class AdminAi extends ConsumerStatefulWidget {
  const AdminAi({super.key});
  @override
  ConsumerState<AdminAi> createState() => _AdminAiState();
}

class _AdminAiState extends ConsumerState<AdminAi> {
  String? _summary;

  Future<void> _set(Map<String, dynamic> settings) async {
    try {
      await ref.read(apiProvider).patch('/school/settings', data: {'settings': settings});
      ref.invalidate(_profileProvider);
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = ref.watch(_profileProvider);
    final status = ref.watch(_aiStatusProvider);
    final usage = ref.watch(_aiUsageProvider);
    return PageBody(children: [
      const PageHeader('دستیار هوشمند'),
      Async<Map<String, dynamic>>(status, builder: (s) => AppCard(color: s['configured'] == true ? Palette.successSoft : Palette.warnSoft, child: Row(children: [Icon(s['configured'] == true ? Icons.check_circle_outline : Icons.settings_suggest_outlined, color: s['configured'] == true ? Palette.success : Palette.warn), const SizedBox(width: 10), Expanded(child: Text(s['configured'] == true ? 'ارائه‌دهنده: ${s['provider']}' : 'سرویس هوش مصنوعی هنوز توسط مدیر سامانه پیکربندی نشده است (AI_PROVIDER / AI_API_KEY).'))]))),
      const SectionTitle('سیاست مدرسه'),
      Async<Map<String, dynamic>>(p, builder: (d) {
        final st = Map<String, dynamic>.from(d['settings'] as Map);
        return AppCard(child: Column(children: [
          SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('فعال‌سازی دستیار هوشمند برای مدرسه'), subtitle: const Text('تا فعال نشود، هیچ دادهای به سرویس خارجی ارسال نمی‌شود.'), value: st['ai.enabled'] == true, onChanged: (v) => _set({'ai.enabled': v})),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('اجازهٔ استفادهٔ دانش‌آموزان'), value: st['ai.student_enabled'] != false, onChanged: (v) => _set({'ai.student_enabled': v})),
          ListTile(contentPadding: EdgeInsets.zero, title: const Text('حداقل سطح پایه برای دانش‌آموز'), trailing: Text(faDigits(st['ai.student_min_grade_level'] ?? 1)), onTap: () async { final v = await promptText(context, 'حداقل سطح پایه (۱ تا ۱۲)', label: 'عدد'); if (v != null && int.tryParse(v) != null) _set({'ai.student_min_grade_level': int.parse(v)}); }),
          ListTile(contentPadding: EdgeInsets.zero, title: const Text('سقف روزانهٔ پرسش هر دانش‌آموز'), trailing: Text(faDigits(st['ai.daily_limit_student'] ?? 30)), onTap: () async { final v = await promptText(context, 'سقف روزانه', label: 'عدد'); if (v != null && int.tryParse(v) != null) _set({'ai.daily_limit_student': int.parse(v)}); }),
          ListTile(contentPadding: EdgeInsets.zero, title: const Text('بودجهٔ روزانهٔ توکن مدرسه'), trailing: Text(faDigits(st['ai.daily_token_budget'] ?? 300000)), onTap: () async { final v = await promptText(context, 'بودجهٔ توکن در روز', label: 'عدد'); if (v != null && int.tryParse(v) != null) _set({'ai.daily_token_budget': int.parse(v)}); }),
        ]));
      }),
      const SectionTitle('مصرف ۳۰ روز اخیر'),
      Async<Map<String, dynamic>>(usage, builder: (u) {
        final t = u['totals'] as Map;
        return Row(children: [Expanded(child: StatCard(label: 'درخواست‌ها', value: faDigits(t['requests'] ?? 0), icon: Icons.chat)), const SizedBox(width: 10), Expanded(child: StatCard(label: 'توکن ورودی', value: faDigits(t['input_tokens'] ?? 0), icon: Icons.input)), const SizedBox(width: 10), Expanded(child: StatCard(label: 'توکن خروجی', value: faDigits(t['output_tokens'] ?? 0), icon: Icons.output))]);
      }),
      const SectionTitle('گزارش مدیریتی هوشمند'),
      AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('فقط آمار تجمیعی (بدون نام دانش‌آموز) برای خلاصه‌سازی ارسال می‌شود؛ تصمیم نهایی با شماست.'),
        const SizedBox(height: 10),
        ActionButton(label: 'تولید خلاصه', icon: Icons.auto_awesome, onPressed: () async {
          try { final r = await ref.read(apiProvider).post('/ai/admin/summary'); setState(() { _summary = r['summary'] as String; }); } on ApiException catch (e) { if (mounted) toast(context, e.code == 'ai_unconfigured' ? 'سرویس هوش مصنوعی پیکربندی نشده است.' : e.readable, error: true); }
        }),
        if (_summary != null) ...[const Divider(), SelectableText(_summary!)],
      ])),
    ]);
  }
}

/// School profile, policies (messenger, recording, files, attendance), audit log and support access.
class AdminSettings extends ConsumerStatefulWidget {
  const AdminSettings({super.key});
  @override
  ConsumerState<AdminSettings> createState() => _AdminSettingsState();
}

class _AdminSettingsState extends ConsumerState<AdminSettings> with SingleTickerProviderStateMixin {
  late final _tabs = TabController(length: 4, vsync: this);
  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Column(children: [
        Material(color: Palette.surface, child: TabBar(controller: _tabs, tabs: const [Tab(text: 'مشخصات و سیاست‌ها'), Tab(text: 'سابقهٔ حسابرسی'), Tab(text: 'پشتیبانی'), Tab(text: 'اشتراک')])),
        Expanded(child: TabBarView(controller: _tabs, children: [_policies(), _audit(), _support(), _subscription()])),
      ]);

  Future<void> _set(Map<String, dynamic> settings) async {
    try {
      await ref.read(apiProvider).patch('/school/settings', data: {'settings': settings});
      ref.invalidate(_profileProvider);
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  Widget _policies() => Async<Map<String, dynamic>>(ref.watch(_profileProvider), onRetry: () => ref.invalidate(_profileProvider), builder: (d) {
        final s = Map<String, dynamic>.from(d['data'] as Map);
        final st = Map<String, dynamic>.from(d['settings'] as Map);
        bool b(String k, [bool def = false]) => (st[k] ?? def) == true;
        return PageBody(maxWidth: 820, children: [
          const PageHeader('مشخصات مدرسه'),
          AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            LabeledRow('نام', Text('${s['name']}')), LabeledRow('شناسه', Text('${s['code']}', textDirection: TextDirection.ltr)), LabeledRow('منطقهٔ زمانی', Text('${s['timezone']}', textDirection: TextDirection.ltr)), LabeledRow('تقویم', Text(s['calendar'] == 'jalali' ? 'شمسی' : 'میلادی')),
            const SizedBox(height: 8),
            OutlinedButton.icon(onPressed: () async { final ok = await showForm(context, title: 'ویرایش مشخصات', initial: s, fields: const [FieldSpec('name', 'نام'), FieldSpec('phone', 'تلفن'), FieldSpec('email', 'ایمیل', type: FieldType.email), FieldSpec('address', 'نشانی', type: FieldType.multiline), FieldSpec('city', 'شهر'), FieldSpec('timezone', 'منطقهٔ زمانی (مثل Asia/Tehran)'), FieldSpec('calendar', 'تقویم نمایش', type: FieldType.dropdown, options: [Option('jalali', 'شمسی'), Option('gregorian', 'میلادی')])], submit: (v) async => ref.read(apiProvider).patch('/school/profile', data: v)); if (ok) ref.invalidate(_profileProvider); }, icon: const Icon(Icons.edit_outlined), label: const Text('ویرایش')),
          ])),
          const SectionTitle('پیام‌رسان و ایمنی دانش‌آموزان'),
          AppCard(child: Column(children: [
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('پیام‌رسان فعال باشد'), value: b('messaging.enabled', true), onChanged: (v) => _set({'messaging.enabled': v})),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('غیرفعال در زمان کلاس (زنگ درس)'), value: b('messaging.block_during_lessons'), onChanged: (v) => _set({'messaging.block_during_lessons': v})),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('پیام خصوصی دانش‌آموز ↔ دانش‌آموز'), value: b('messaging.student_to_student'), onChanged: (v) => _set({'messaging.student_to_student': v})),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('پیام خصوصی دانش‌آموز ↔ معلم'), subtitle: const Text('پیشنهاد: خاموش؛ پرسش دربارهٔ تکلیف در گفت‌وگوی همان تکلیف انجام می‌شود.'), value: b('messaging.student_to_teacher'), onChanged: (v) => _set({'messaging.student_to_teacher': v})),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('پیام والد ↔ معلم'), value: b('messaging.guardian_to_teacher', true), onChanged: (v) => _set({'messaging.guardian_to_teacher': v})),
            ListTile(contentPadding: EdgeInsets.zero, title: const Text('ساعت‌های سکوت (پیام‌رسان بسته است)'), subtitle: Text(((st['messaging.quiet_hours'] as List?) ?? []).isEmpty ? 'تعریف نشده' : ((st['messaging.quiet_hours'] as List).map((w) => '${faDigits(w['start'])}–${faDigits(w['end'])}').join('، '))),
                trailing: const Icon(Icons.edit_outlined), onTap: () async { final t = await promptText(context, 'ساعت سکوت (مثلاً 22:00-06:30؛ خالی = حذف)', label: 'شروع-پایان', required: false, maxLines: 1); if (t == null) return; final m = RegExp(r'^(\d{2}:\d{2})-(\d{2}:\d{2})$').firstMatch(t.trim()); _set({'messaging.quiet_hours': m == null ? [] : [{'start': m[1], 'end': m[2]}]}); }),
          ])),
          const SectionTitle('کلاس آنلاین، فایل‌ها و حضور'),
          AppCard(child: Column(children: [
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('اجازهٔ ضبط کلاس'), subtitle: const Text('در صورت فعال‌سازی، معلم می‌تواند ضبط را روشن کند و شرکت‌کنندگان مطلع می‌شوند. رضایت‌نامه‌های قانونی با مدرسه است.'), value: b('recording.allowed'), onChanged: (v) => _set({'recording.allowed': v})),
            ListTile(contentPadding: EdgeInsets.zero, title: const Text('حداکثر حجم هر فایل (مگابایت)'), trailing: Text(faDigits(st['files.max_mb'] ?? 20)), onTap: () async { final v = await promptText(context, 'حداکثر حجم فایل', label: 'مگابایت'); if (v != null && int.tryParse(v) != null) _set({'files.max_mb': int.parse(v)}); }),
            ListTile(contentPadding: EdgeInsets.zero, title: const Text('تأخیر مجاز پیش از «دیرکرد» (دقیقه)'), trailing: Text(faDigits(st['attendance.late_after_minutes'] ?? 5)), onTap: () async { final v = await promptText(context, 'دقیقه', label: 'عدد'); if (v != null && int.tryParse(v) != null) _set({'attendance.late_after_minutes': int.parse(v)}); }),
          ])),
          const SectionTitle('فیلدهای اختصاصی دانش‌آموز'),
          AppCard(child: ListTile(contentPadding: EdgeInsets.zero, title: Text(((st['custom_fields.students'] as List?) ?? []).isEmpty ? 'فیلد اضافه‌ای تعریف نشده' : (st['custom_fields.students'] as List).join('، ')), subtitle: const Text('نام‌ها را با ویرگول جدا کنید (حداکثر ۲۰ مورد؛ مقدار متنی کوتاه).'), trailing: const Icon(Icons.edit_outlined), onTap: () async { final t = await promptText(context, 'فیلدهای اختصاصی', label: 'مثال: گروه خونی، بیماری خاص', required: false, maxLines: 1); if (t != null) _set({'custom_fields.students': t.split(RegExp(r'[,،]')).map((e) => e.trim()).where((e) => e.isNotEmpty).take(20).toList()}); })),
        ]);
      });

  Widget _audit() => PageBody(maxWidth: 900, children: [
        const PageHeader('سابقهٔ حسابرسی', subtitle: 'تغییر نمره، ورود مدیران، خروجی داده، تصمیم‌های مدیریتی و دسترسی پشتیبانی'),
        PagedList(path: '/school/audit', perPage: 40, emptyText: 'رویدادی ثبت نشده است.', itemBuilder: (c, a, st) => AppCard(padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10), child: Row(children: [
              const Icon(Icons.history_toggle_off, size: 20, color: Palette.muted), const SizedBox(width: 10),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${a['action']}', textDirection: TextDirection.ltr, style: const TextStyle(fontWeight: FontWeight.w500)), Text('کاربر ${a['user_id'] == null ? '—' : faDigits(a['user_id'])} · ${a['subject_type'] ?? ''} ${a['subject_id'] ?? ''} · ${a['ip'] ?? ''}', style: Theme.of(c).textTheme.bodySmall)])),
              Text(fmtDate(a['created_at'], withTime: true), style: Theme.of(c).textTheme.bodySmall),
            ]))),
      ]);

  final _grants = GlobalKey<PagedListState>();
  final _tickets = GlobalKey<PagedListState>();

  Widget _support() => PageBody(maxWidth: 900, children: [
        PageHeader('پشتیبانی فنی', actions: [FilledButton.icon(onPressed: () async { final ok = await showForm(context, title: 'درخواست پشتیبانی', submitLabel: 'ارسال', fields: const [FieldSpec('subject', 'موضوع', required: true), FieldSpec('body', 'شرح مشکل', type: FieldType.multiline, required: true), FieldSpec('category', 'دسته', type: FieldType.dropdown, initial: 'technical', options: [Option('technical', 'فنی'), Option('billing', 'مالی'), Option('abuse', 'تخلف'), Option('other', 'سایر')])], submit: (v) async => ref.read(apiProvider).post('/support/tickets', data: v)); if (ok) _tickets.currentState?.reload(); }, icon: const Icon(Icons.add), label: const Text('درخواست جدید'))]),
        PagedList(key: _tickets, path: '/support/tickets', emptyText: 'درخواستی ندارید.', itemBuilder: (c, t, st) => AppCard(onTap: () => _ticket(t), child: Row(children: [Expanded(child: Text('${t['subject']}', style: Theme.of(c).textTheme.titleMedium)), StatusChip({'open': 'باز', 'pending': 'در حال پیگیری', 'resolved': 'حل‌شده', 'closed': 'بسته'}[t['status']] ?? '${t['status']}', tone: t['status'] == 'resolved' ? Tone.success : Tone.info)]))),
        const SectionTitle('دسترسی موقت پشتیبان به مدرسه'),
        AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('پشتیبان فنی به‌طور پیش‌فرض هیچ دسترسی به داده‌های مدرسه ندارد. فقط شما می‌توانید برای مدت محدود، دسترسی «فقط‌خواندنیِ ساختار» (بدون نمرات، پیام‌ها و پرونده‌ها) بدهید؛ هر دسترسی ثبت می‌شود.'),
          const SizedBox(height: 10),
          OutlinedButton.icon(onPressed: () async { final ok = await showForm(context, title: 'اعطای دسترسی موقت', fields: const [FieldSpec('support_user_id', 'شناسهٔ کاربر پشتیبان', type: FieldType.number, required: true), FieldSpec('reason', 'دلیل', required: true), FieldSpec('hours', 'مدت (ساعت، حداکثر ۷۲)', type: FieldType.number, required: true, initial: 2)], submit: (v) async => ref.read(apiProvider).post('/support/grants', data: v)); if (ok) _grants.currentState?.reload(); }, icon: const Icon(Icons.key_outlined), label: const Text('اعطای دسترسی')),
        ])),
        PagedList(key: _grants, path: '/support/grants', dataKey: 'data', emptyText: 'دسترسی فعالی نیست.', itemBuilder: (c, g, st) => AppCard(child: Row(children: [Expanded(child: Text('پشتیبان ${faDigits(g['support_user_id'])} — ${g['reason']} — تا ${fmtDate(g['expires_at'], withTime: true)}')), if (g['revoked_at'] == null) TextButton(onPressed: () async { await ref.read(apiProvider).delete('/support/grants/${g['id']}'); st.reload(); }, child: const Text('لغو')) else const StatusChip('لغو شد', tone: Tone.neutral)]))),
      ]);

  Future<void> _ticket(Map<String, dynamic> t) async {
    final r = await ref.read(apiProvider).get('/support/tickets/${t['id']}');
    if (!mounted) return;
    await showDialog(context: context, builder: (c) => AlertDialog(
          title: Text('${t['subject']}'),
          content: SizedBox(width: 520, child: ListView(shrinkWrap: true, children: [for (final m in r['messages'] as List) Container(margin: const EdgeInsets.only(bottom: 8), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: m['user_id'] == ref.read(sessionProvider).userId ? Palette.brandSoft : Palette.bg, borderRadius: BorderRadius.circular(10)), child: Text('${m['body']}'))])),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('بستن')), FilledButton(onPressed: () async { final t2 = await promptText(c, 'پاسخ'); if (t2 != null) { await ref.read(apiProvider).post('/support/tickets/${t['id']}/reply', data: {'body': t2}); if (c.mounted) Navigator.pop(c); } }, child: const Text('پاسخ'))],
        ));
  }

  Widget _subscription() => Async<Map<String, dynamic>>(ref.watch(_profileProvider), builder: (d) {
        final sub = (d['data'] as Map)['subscription'] as Map?;
        return PageBody(maxWidth: 720, children: [
          const PageHeader('اشتراک و محدودیت‌ها'),
          if (sub == null) const EmptyState('اشتراکی ثبت نشده است.') else AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            LabeledRow('طرح', Text('${sub['plan']}')), LabeledRow('دانش‌آموز', Text(faDigits(sub['max_students']))), LabeledRow('معلم', Text(faDigits(sub['max_teachers']))), LabeledRow('کلاس آنلاین هم‌زمان', Text(faDigits(sub['max_live_sessions']))), LabeledRow('فضای ذخیره‌سازی', Text('${faDigits(sub['max_storage_mb'])} مگابایت')),
            const SizedBox(height: 8), const Text('برای تغییر طرح با مدیر کل سامانه تماس بگیرید.', style: TextStyle(color: Palette.muted)),
          ])),
        ]);
      });
}
