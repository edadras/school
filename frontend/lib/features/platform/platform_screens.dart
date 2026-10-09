import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/forms.dart';
import '../../design/paged_list.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';

final _statsProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async => Map<String, dynamic>.from(await ref.read(apiProvider).get('/platform/stats')));
final _healthProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async => Map<String, dynamic>.from(await ref.read(apiProvider).get('/platform/health')));

class PlatformDashboard extends ConsumerWidget {
  const PlatformDashboard({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => PageBody(maxWidth: 1250, onRefresh: () async { ref.invalidate(_statsProvider); ref.invalidate(_healthProvider); }, children: [
        const PageHeader('نمای کلی سامانه'),
        Async<Map<String, dynamic>>(ref.watch(_statsProvider), onRetry: () => ref.invalidate(_statsProvider), builder: (s) {
          final t = s['totals'] as Map;
          final by = Map<String, dynamic>.from(t['by_status'] as Map);
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Wrap(spacing: 12, runSpacing: 12, children: [
              SizedBox(width: 250, child: StatCard(label: 'مدارس', value: faDigits(t['schools']), icon: Icons.apartment)),
              SizedBox(width: 250, child: StatCard(label: 'در انتظار تأیید', value: faDigits(by['pending'] ?? 0), icon: Icons.approval, tone: Tone.warn)),
              SizedBox(width: 250, child: StatCard(label: 'کاربران', value: faDigits(t['users']), icon: Icons.people)),
              SizedBox(width: 250, child: StatCard(label: 'دانش‌آموزان', value: faDigits(t['students']), icon: Icons.groups)),
              SizedBox(width: 250, child: StatCard(label: 'کلاس آنلاین فعال', value: faDigits(t['live_sessions']), icon: Icons.videocam, tone: Tone.success)),
              SizedBox(width: 250, child: StatCard(label: 'فضای مصرفی', value: fmtBytes(t['storage_bytes'] as num), icon: Icons.storage)),
            ]),
            const SectionTitle('مصرف منابع به تفکیک مدرسه'),
            for (final sc in s['schools'] as List) Padding(padding: const EdgeInsets.only(bottom: 8), child: AppCard(child: Row(children: [
              Expanded(flex: 3, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${sc['name']}', style: Theme.of(context).textTheme.titleMedium), Text('${sc['code']} · ${statusName(sc['status'] as String)} · طرح ${sc['plan'] ?? '—'}', style: Theme.of(context).textTheme.bodySmall)])),
              Expanded(flex: 4, child: Wrap(spacing: 14, children: [Text('کاربر: ${faDigits(sc['users'])}'), Text('دانش‌آموز: ${faDigits(sc['students'])}${sc['limits'] != null ? '/${faDigits(sc['limits']['students'])}' : ''}'), Text('کلاس ۳۰ روز: ${faDigits(sc['sessions_30d'])}'), Text('فضا: ${fmtBytes(sc['storage_bytes'] as num)}'), Text('AI: ${faDigits(sc['ai_requests_30d'])}')])),
            ]))),
          ]);
        }),
        const SectionTitle('سلامت سرویس‌ها'),
        Async<Map<String, dynamic>>(ref.watch(_healthProvider), onRetry: () => ref.invalidate(_healthProvider), builder: (h) => Wrap(spacing: 12, runSpacing: 12, children: [
              for (final e in h.entries.where((e) => e.value is Map)) SizedBox(width: 250, child: AppCard(child: Row(children: [
                    Icon((e.value as Map)['ok'] == true ? Icons.check_circle : ((e.value as Map)['configured'] == false ? Icons.settings_suggest_outlined : Icons.error), color: (e.value as Map)['ok'] == true ? Palette.success : ((e.value as Map)['configured'] == false ? Palette.warn : Palette.danger)), const SizedBox(width: 10),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(_hn(e.key), style: const TextStyle(fontWeight: FontWeight.w700)), Text(((e.value as Map)['configured'] == false) ? 'پیکربندی نشده' : '${(e.value as Map)['detail'] ?? ''}${(e.value as Map)['failed_jobs'] != null ? ' · خطای صف: ${faDigits((e.value as Map)['failed_jobs'])}' : ''}', style: Theme.of(context).textTheme.bodySmall)])),
                  ]))),
              if (h['bell_last_tick'] != null) SizedBox(width: 250, child: AppCard(child: Row(children: [const Icon(Icons.notifications_active_outlined, color: Palette.brand), const SizedBox(width: 10), Expanded(child: Text('آخرین زنگ‌زن: ${fmtDate(h['bell_last_tick'], withTime: true)}'))]))),
            ])),
      ]);

  static String _hn(String k) => {'database': 'پایگاه داده', 'cache': 'حافظهٔ نهان', 'storage': 'ذخیره‌سازی فایل', 'queue': 'صف پردازش', 'media': 'سرور رسانه (کلاس آنلاین)', 'ai': 'هوش مصنوعی', 'push': 'اعلان فشاری', 'realtime': 'ارتباط زنده (WebSocket)'}[k] ?? k;
}

class PlatformApprovals extends ConsumerStatefulWidget {
  const PlatformApprovals({super.key});
  @override
  ConsumerState<PlatformApprovals> createState() => _ApprovalsState();
}

class _ApprovalsState extends ConsumerState<PlatformApprovals> {
  String _status = 'pending';
  final _key = GlobalKey<PagedListState>();

  Future<void> _decide(Map<String, dynamic> r, String decision) async {
    String? note;
    if (decision != 'approve') {
      note = await promptText(context, decision == 'reject' ? 'دلیل رد درخواست' : 'چه چیزی باید اصلاح شود؟');
      if (note == null) return;
    } else if (!await confirm(context, 'مدرسهٔ «${r['school']?['name']}» تأیید و فعال شود؟', ok: 'تأیید و فعال‌سازی')) {
      return;
    }
    try {
      await ref.read(apiProvider).post('/platform/approval-requests/${r['id']}/decision', data: {'decision': decision, 'note': ?note});
      if (mounted) toast(context, 'ثبت شد.');
      _key.currentState?.reload();
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  @override
  Widget build(BuildContext context) => PageBody(children: [
        const PageHeader('درخواست‌های فعال‌سازی مدرسه'),
        Wrap(spacing: 8, children: [for (final s in const [('pending', 'در انتظار'), ('needs_changes', 'نیازمند اصلاح'), ('approved', 'تأییدشده'), ('rejected', 'ردشده')]) ChoiceChip(label: Text(s.$2), selected: _status == s.$1, onSelected: (_) => setState(() => _status = s.$1))]),
        const SizedBox(height: 12),
        PagedList(key: _key, path: '/platform/approval-requests', query: {'status': _status}, emptyText: 'درخواستی در این وضعیت نیست.', itemBuilder: (c, r, st) {
          final p = (r['payload'] as Map?) ?? {};
          return AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [Expanded(child: Text('${r['school']?['name']}', style: Theme.of(c).textTheme.titleMedium)), StatusChip(statusName(r['status'] as String), tone: r['status'] == 'pending' ? Tone.warn : (r['status'] == 'approved' ? Tone.success : Tone.neutral))]),
            Text('شناسه: ${r['school']?['code']} · شهر: ${r['school']?['city'] ?? '—'} · تلفن: ${p['phone'] ?? '—'} · ایمیل: ${p['email'] ?? '—'}', style: Theme.of(c).textTheme.bodySmall),
            Text('ثبت‌شده: ${fmtDate(r['created_at'], withTime: true)}', style: Theme.of(c).textTheme.bodySmall),
            if (r['decision_note'] != null) Text('توضیح: ${r['decision_note']}', style: const TextStyle(color: Palette.muted)),
            if (r['status'] == 'pending') ...[const SizedBox(height: 10), Wrap(spacing: 8, runSpacing: 8, children: [
              FilledButton.icon(key: Key('approve-${r['id']}'), onPressed: () => _decide(r, 'approve'), icon: const Icon(Icons.check), label: const Text('تأیید')),
              OutlinedButton.icon(onPressed: () => _decide(r, 'needs_changes'), icon: const Icon(Icons.edit_note), label: const Text('بازگرداندن برای اصلاح')),
              OutlinedButton.icon(style: OutlinedButton.styleFrom(foregroundColor: Palette.danger), onPressed: () => _decide(r, 'reject'), icon: const Icon(Icons.close), label: const Text('رد')),
            ])],
          ]));
        }),
      ]);
}

class PlatformSchools extends ConsumerStatefulWidget {
  const PlatformSchools({super.key});
  @override
  ConsumerState<PlatformSchools> createState() => _SchoolsState();
}

class _SchoolsState extends ConsumerState<PlatformSchools> {
  String _q = '';
  String? _status;
  final _key = GlobalKey<PagedListState>();

  @override
  Widget build(BuildContext context) => PageBody(children: [
        const PageHeader('مدارس'),
        SearchField(onChanged: (v) => setState(() => _q = v), hint: 'نام مدرسه'),
        const SizedBox(height: 8),
        Wrap(spacing: 8, children: [for (final s in const ['pending', 'active', 'suspended', 'rejected', 'needs_changes']) FilterChip(label: Text(statusName(s)), selected: _status == s, onSelected: (on) => setState(() => _status = on ? s : null))]),
        const SizedBox(height: 12),
        PagedList(key: _key, path: '/platform/schools', query: {'q': _q, 'status': _status}, emptyText: 'مدرسه‌ای یافت نشد.', itemBuilder: (c, s, st) => AppCard(child: Row(children: [
              const Icon(Icons.apartment, color: Palette.brand), const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${s['name']}', style: Theme.of(c).textTheme.titleMedium), Text('${s['code']} · طرح ${s['subscription']?['plan'] ?? '—'}', style: Theme.of(c).textTheme.bodySmall)])),
              StatusChip(statusName(s['status'] as String), tone: s['status'] == 'active' ? Tone.success : (s['status'] == 'suspended' ? Tone.danger : Tone.warn)),
              IconButton(tooltip: 'اشتراک و محدودیت‌ها', icon: const Icon(Icons.tune), onPressed: () async { final sub = (s['subscription'] as Map?) ?? {}; final ok = await showForm(context, title: 'اشتراک ${s['name']}', initial: Map<String, dynamic>.from(sub), fields: const [FieldSpec('plan', 'طرح'), FieldSpec('max_students', 'حداکثر دانش‌آموز', type: FieldType.number), FieldSpec('max_teachers', 'حداکثر معلم', type: FieldType.number), FieldSpec('max_live_sessions', 'کلاس آنلاین هم‌زمان', type: FieldType.number), FieldSpec('max_storage_mb', 'فضای ذخیره‌سازی (MB)', type: FieldType.number)], submit: (v) async => ref.read(apiProvider).patch('/platform/schools/${s['id']}/subscription', data: v..removeWhere((k, x) => x == null))); if (ok) st.reload(); }),
              if (s['status'] == 'active') IconButton(tooltip: 'تعلیق', icon: const Icon(Icons.pause_circle_outline, color: Palette.danger), onPressed: () async { final why = await promptText(context, 'دلیل تعلیق'); if (why == null) return; await ref.read(apiProvider).post('/platform/schools/${s['id']}/suspend', data: {'reason': why}); st.reload(); }),
              if (s['status'] == 'suspended') IconButton(tooltip: 'رفع تعلیق', icon: const Icon(Icons.play_circle_outline, color: Palette.success), onPressed: () async { await ref.read(apiProvider).post('/platform/schools/${s['id']}/reactivate'); st.reload(); }),
            ]))),
      ]);
}

/// Platform-side support inbox (super admin and support staff).
class PlatformSupport extends ConsumerStatefulWidget {
  const PlatformSupport({super.key});
  @override
  ConsumerState<PlatformSupport> createState() => _PlatformSupportState();
}

class _PlatformSupportState extends ConsumerState<PlatformSupport> {
  String _status = 'open';
  final _key = GlobalKey<PagedListState>();
  @override
  Widget build(BuildContext context) => PageBody(children: [
        const PageHeader('درخواست‌های پشتیبانی'),
        Wrap(spacing: 8, children: [for (final s in const [('open', 'باز'), ('pending', 'در حال پیگیری'), ('resolved', 'حل‌شده'), ('closed', 'بسته')]) ChoiceChip(label: Text(s.$2), selected: _status == s.$1, onSelected: (_) => setState(() => _status = s.$1))]),
        const SizedBox(height: 12),
        PagedList(key: _key, path: '/platform/support/tickets', query: {'status': _status}, emptyText: 'درخواستی نیست.', itemBuilder: (c, t, st) => AppCard(onTap: () async {
              final r = await ref.read(apiProvider).get('/platform/support/tickets/${t['id']}');
              if (!c.mounted) return;
              await showDialog(context: c, builder: (d) => AlertDialog(
                    title: Text('${t['subject']}'),
                    content: SizedBox(width: 560, child: ListView(shrinkWrap: true, children: [for (final m in r['messages'] as List) Container(margin: const EdgeInsets.only(bottom: 8), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: m['internal'] == true ? Palette.warnSoft : Palette.bg, borderRadius: BorderRadius.circular(10)), child: Text('${m['internal'] == true ? '[یادداشت داخلی] ' : ''}${m['body']}'))])),
                    actions: [TextButton(onPressed: () => Navigator.pop(d), child: const Text('بستن')), OutlinedButton(onPressed: () async { final b = await promptText(d, 'یادداشت داخلی'); if (b != null) await ref.read(apiProvider).post('/platform/support/tickets/${t['id']}/reply', data: {'body': b, 'internal': true}); }, child: const Text('یادداشت داخلی')), FilledButton(onPressed: () async { final b = await promptText(d, 'پاسخ به کاربر'); if (b != null) { await ref.read(apiProvider).post('/platform/support/tickets/${t['id']}/reply', data: {'body': b, 'status': 'pending'}); if (d.mounted) Navigator.pop(d); st.reload(); } }, child: const Text('پاسخ'))],
                  ));
            }, child: Row(children: [Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${t['subject']}', style: Theme.of(c).textTheme.titleMedium), Text('مدرسه ${t['school_id'] == null ? '—' : faDigits(t['school_id'])} · ${t['category']} · ${fmtDate(t['created_at'])}', style: Theme.of(c).textTheme.bodySmall)])), StatusChip('${t['priority']}', tone: t['priority'] == 'high' ? Tone.danger : Tone.neutral)]))),
      ]);
}

class PlatformAnnouncements extends ConsumerStatefulWidget {
  const PlatformAnnouncements({super.key});
  @override
  ConsumerState<PlatformAnnouncements> createState() => _PlatformAnnouncementsState();
}

class _PlatformAnnouncementsState extends ConsumerState<PlatformAnnouncements> {
  final _key = GlobalKey<PagedListState>();
  @override
  Widget build(BuildContext context) => PageBody(children: [
        PageHeader('اعلان‌های سراسری', actions: [FilledButton.icon(onPressed: () async { final ok = await showForm(context, title: 'اعلان برای مدیران همهٔ مدارس', submitLabel: 'انتشار', fields: const [FieldSpec('title', 'عنوان', required: true), FieldSpec('body', 'متن', type: FieldType.multiline, required: true)], submit: (v) async { final r = await ref.read(apiProvider).post('/platform/announcements', data: v); if (mounted) toast(context, 'برای ${faDigits(r['notified'])} مدیر ارسال شد.'); }); if (ok) _key.currentState?.reload(); }, icon: const Icon(Icons.campaign_outlined), label: const Text('اعلان جدید'))]),
        PagedList(key: _key, path: '/platform/announcements', dataKey: 'data', emptyText: 'اعلانی منتشر نشده است.', itemBuilder: (c, a, st) => AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${a['title']}', style: Theme.of(c).textTheme.titleMedium), Text('${a['body']}'), Text(fmtDate(a['published_at'], withTime: true), style: Theme.of(c).textTheme.bodySmall)]))),
      ]);
}

class PlatformOperators extends ConsumerStatefulWidget {
  const PlatformOperators({super.key});
  @override
  ConsumerState<PlatformOperators> createState() => _OperatorsState();
}

class _OperatorsState extends ConsumerState<PlatformOperators> {
  final _key = GlobalKey<PagedListState>();
  @override
  Widget build(BuildContext context) => PageBody(children: [
        PageHeader('مدیران کل و پشتیبان‌ها', actions: [FilledButton.icon(onPressed: () async { final ok = await showForm(context, title: 'کاربر جدید', fields: const [FieldSpec('name', 'نام', required: true), FieldSpec('email', 'ایمیل', type: FieldType.email, required: true), FieldSpec('role', 'نقش', type: FieldType.dropdown, required: true, initial: 'support', options: [Option('support', 'پشتیبان فنی (دسترسی محدود)'), Option('super_admin', 'مدیر کل')]), FieldSpec('password', 'رمز (حداقل ۱۲ نویسه با حرف و عدد)', type: FieldType.password, required: true)], submit: (v) async => ref.read(apiProvider).post('/platform/operators', data: v)); if (ok) _key.currentState?.reload(); }, icon: const Icon(Icons.person_add_alt), label: const Text('افزودن'))]),
        PagedList(key: _key, path: '/platform/operators', dataKey: 'data', emptyText: 'کاربری نیست.', itemBuilder: (c, u, st) => AppCard(child: Row(children: [
              const Icon(Icons.admin_panel_settings_outlined, color: Palette.brand), const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${u['name']}', style: Theme.of(c).textTheme.titleMedium), Text('${u['email']} · ${roleName(u['platform_role'])}', textDirection: TextDirection.ltr, style: Theme.of(c).textTheme.bodySmall)])),
              StatusChip(u['status'] == 'active' ? 'فعال' : 'غیرفعال', tone: u['status'] == 'active' ? Tone.success : Tone.danger),
              if (u['id'] != ref.read(sessionProvider).userId) IconButton(tooltip: u['status'] == 'active' ? 'غیرفعال‌سازی' : 'فعال‌سازی', icon: Icon(u['status'] == 'active' ? Icons.block : Icons.check_circle_outline), onPressed: () async { await ref.read(apiProvider).patch('/platform/operators/${u['id']}', data: {'status': u['status'] == 'active' ? 'disabled' : 'active'}); st.reload(); }),
            ]))),
      ]);
}

class PlatformAudit extends StatelessWidget {
  const PlatformAudit({super.key});
  @override
  Widget build(BuildContext context) => PageBody(maxWidth: 1000, children: [
        const PageHeader('گزارش کامل عملیات مدیریتی'),
        PagedList(path: '/platform/audit', perPage: 40, emptyText: 'رویدادی نیست.', itemBuilder: (c, a, st) => AppCard(padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10), child: Row(children: [Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${a['action']}', textDirection: TextDirection.ltr, style: const TextStyle(fontWeight: FontWeight.w500)), Text('مدرسه ${a['school_id'] == null ? '—' : faDigits(a['school_id'])} · کاربر ${a['user_id'] == null ? '—' : faDigits(a['user_id'])} · ${a['ip'] ?? ''}', style: Theme.of(c).textTheme.bodySmall)])), Text(fmtDate(a['created_at'], withTime: true), style: Theme.of(c).textTheme.bodySmall)]))),
      ]);
}

class PlatformSettings extends ConsumerWidget {
  const PlatformSettings({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => PageBody(maxWidth: 760, children: [
        const PageHeader('تنظیمات عمومی'),
        FutureBuilder(future: ref.read(apiProvider).get('/platform/settings'), builder: (c, s) {
          if (!s.hasData) return const LinearProgressIndicator();
          final d = Map<String, dynamic>.from((s.data as Map)['data'] as Map);
          String v(String k) => ((d[k] as List?)?.firstOrNull ?? '—').toString();
          return AppCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            LabeledRow('منطقهٔ زمانی پیش‌فرض', Text(v('default_timezone'), textDirection: TextDirection.ltr)), LabeledRow('زبان پیش‌فرض', Text(v('default_locale'))), LabeledRow('ثبت‌نام مدرسه باز است', Text(v('registration_open'))), LabeledRow('بنر اطلاع‌رسانی', Text(v('maintenance_banner'))),
            const SizedBox(height: 10),
            OutlinedButton.icon(onPressed: () => showForm(context, title: 'ویرایش تنظیمات', fields: const [FieldSpec('default_timezone', 'منطقهٔ زمانی'), FieldSpec('default_locale', 'زبان', type: FieldType.dropdown, options: [Option('fa', 'فارسی'), Option('en', 'English'), Option('tr', 'Türkçe')]), FieldSpec('registration_open', 'ثبت‌نام مدارس باز باشد', type: FieldType.toggle, initial: true), FieldSpec('maintenance_banner', 'بنر اطلاع‌رسانی')], submit: (x) async => ref.read(apiProvider).patch('/platform/settings', data: {'settings': x..removeWhere((k, y) => y == null)})), icon: const Icon(Icons.edit_outlined), label: const Text('ویرایش')),
          ]));
        }),
      ]);
}
