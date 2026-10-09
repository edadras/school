import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../core/session.dart';
import '../core/strings.dart';
import '../design/theme.dart';
import 'common/notifications.dart';

class NavItem {
  const NavItem(this.path, this.label, this.icon, [this.selectedIcon]);
  final String path;
  final String label;
  final IconData icon;
  final IconData? selectedIcon;
}

const _platformNav = [
  NavItem('/platform', 'نمای کلی', Icons.space_dashboard_outlined, Icons.space_dashboard),
  NavItem('/platform/approvals', 'درخواست‌های مدرسه', Icons.approval_outlined, Icons.approval),
  NavItem('/platform/schools', 'مدارس', Icons.apartment_outlined, Icons.apartment),
  NavItem('/platform/support', 'پشتیبانی', Icons.support_agent_outlined, Icons.support_agent),
  NavItem('/platform/announcements', 'اعلان سراسری', Icons.campaign_outlined, Icons.campaign),
  NavItem('/platform/operators', 'مدیران و پشتیبان‌ها', Icons.admin_panel_settings_outlined, Icons.admin_panel_settings),
  NavItem('/platform/audit', 'گزارش عملیات', Icons.fact_check_outlined, Icons.fact_check),
  NavItem('/platform/settings', 'تنظیمات', Icons.settings_outlined, Icons.settings),
];
const _supportNav = [NavItem('/support', 'درخواست‌های پشتیبانی', Icons.support_agent_outlined, Icons.support_agent)];
const _adminNav = [
  NavItem('/admin', 'داشبورد', Icons.space_dashboard_outlined, Icons.space_dashboard),
  NavItem('/admin/people', 'کاربران', Icons.groups_outlined, Icons.groups),
  NavItem('/admin/structure', 'ساختار آموزشی', Icons.account_tree_outlined, Icons.account_tree),
  NavItem('/admin/timetable', 'برنامه هفتگی', Icons.calendar_view_week_outlined, Icons.calendar_view_week),
  NavItem('/admin/live', 'کلاس‌های آنلاین', Icons.videocam_outlined, Icons.videocam),
  NavItem('/admin/attendance', 'حضور و غیاب', Icons.how_to_reg_outlined, Icons.how_to_reg),
  NavItem('/admin/learning', 'تکلیف و آزمون', Icons.assignment_outlined, Icons.assignment),
  NavItem('/admin/grades', 'نمرات', Icons.grade_outlined, Icons.grade),
  NavItem('/admin/reports', 'کارنامه', Icons.workspace_premium_outlined, Icons.workspace_premium),
  NavItem('/admin/messages', 'پیام‌ها و اطلاعیه', Icons.forum_outlined, Icons.forum),
  NavItem('/admin/ai', 'دستیار هوشمند', Icons.auto_awesome_outlined, Icons.auto_awesome),
  NavItem('/admin/settings', 'تنظیمات', Icons.settings_outlined, Icons.settings),
];
const _teacherNav = [
  NavItem('/teacher', 'امروز', Icons.today_outlined, Icons.today),
  NavItem('/teacher/classes', 'کلاس‌ها', Icons.class_outlined, Icons.class_),
  NavItem('/teacher/assignments', 'تکالیف', Icons.assignment_outlined, Icons.assignment),
  NavItem('/teacher/exams', 'آزمون‌ها', Icons.quiz_outlined, Icons.quiz),
  NavItem('/teacher/grades', 'نمرات', Icons.grade_outlined, Icons.grade),
  NavItem('/teacher/messages', 'پیام‌ها', Icons.forum_outlined, Icons.forum),
  NavItem('/teacher/ai', 'دستیار هوشمند', Icons.auto_awesome_outlined, Icons.auto_awesome),
];
const _studentNav = [
  NavItem('/student', 'امروز', Icons.today_outlined, Icons.today),
  NavItem('/student/assignments', 'تکالیف', Icons.assignment_outlined, Icons.assignment),
  NavItem('/student/exams', 'آزمون‌ها', Icons.quiz_outlined, Icons.quiz),
  NavItem('/student/learn', 'درس‌ها', Icons.menu_book_outlined, Icons.menu_book),
  NavItem('/student/grades', 'نمرات و کارنامه', Icons.grade_outlined, Icons.grade),
  NavItem('/student/messages', 'پیام‌ها', Icons.forum_outlined, Icons.forum),
  NavItem('/student/ai', 'دستیار', Icons.auto_awesome_outlined, Icons.auto_awesome),
];
const _guardianNav = [
  NavItem('/guardian', 'فرزندان', Icons.family_restroom_outlined, Icons.family_restroom),
  NavItem('/guardian/schedule', 'برنامه', Icons.calendar_view_week_outlined, Icons.calendar_view_week),
  NavItem('/guardian/attendance', 'حضور و غیاب', Icons.how_to_reg_outlined, Icons.how_to_reg),
  NavItem('/guardian/grades', 'نمرات و کارنامه', Icons.grade_outlined, Icons.grade),
  NavItem('/guardian/assignments', 'تکالیف', Icons.assignment_outlined, Icons.assignment),
  NavItem('/guardian/messages', 'پیام‌ها', Icons.forum_outlined, Icons.forum),
  NavItem('/guardian/meetings', 'درخواست جلسه', Icons.event_available_outlined, Icons.event_available),
];

List<NavItem> navFor(Panel p) => switch (p) { Panel.platform => _platformNav, Panel.support => _supportNav, Panel.admin => _adminNav, Panel.teacher => _teacherNav, Panel.student => _studentNav, Panel.guardian => _guardianNav };

String basePath(Panel p) => switch (p) { Panel.platform => '/platform', Panel.support => '/support', Panel.admin => '/admin', Panel.teacher => '/teacher', Panel.student => '/student', Panel.guardian => '/guardian' };

/// Responsive shell: rail on desktop/tablet, bottom bar (+ "more") on phones.
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.panel, required this.child});
  final Panel panel;
  final Widget child;

  int _index(List<NavItem> items, String loc) {
    var best = 0;
    var len = -1;
    for (var i = 0; i < items.length; i++) {
      final p = items[i].path;
      if ((loc == p || loc.startsWith('$p/')) && p.length > len) { best = i; len = p.length; }
    }
    return best;
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = navFor(panel);
    final loc = GoRouterState.of(context).uri.path;
    final idx = _index(items, loc);
    final w = MediaQuery.sizeOf(context).width;
    final session = ref.watch(sessionProvider);
    final unread = ref.watch(unreadCountProvider).valueOrNull ?? 0;
    final title = session.active?.schoolName ?? (panel == Panel.platform ? 'مدیریت سامانه' : 'سامانه مدرسه');

    final actions = [
      if (panel != Panel.platform && panel != Panel.support)
        IconButton(
          tooltip: 'اعلان‌ها',
          onPressed: () => context.push('/notifications'),
          icon: Badge(isLabelVisible: unread > 0, label: Text('$unread'), child: const Icon(Icons.notifications_outlined)),
        ),
      PopupMenuButton<String>(
        tooltip: 'حساب کاربری',
        icon: CircleAvatar(radius: 16, backgroundColor: Palette.brandSoft, child: Text(session.name.isEmpty ? '؟' : session.name.characters.first, style: const TextStyle(color: Palette.brand, fontWeight: FontWeight.w700))),
        onSelected: (v) async {
          if (v == 'profile') context.push('/profile');
          if (v == 'switch') ref.read(sessionProvider.notifier).clearSchool();
          if (v == 'logout') {
            try { await ref.read(apiProvider).post('/auth/logout'); } catch (_) {}
            await ref.read(sessionProvider.notifier).signOut();
          }
        },
        itemBuilder: (_) => [
          PopupMenuItem(enabled: false, child: Text('${session.name}\n${roleName(session.platformRole ?? session.active?.role)}', style: const TextStyle(fontSize: 13))),
          const PopupMenuDivider(),
          const PopupMenuItem(value: 'profile', child: Text('پروفایل و تنظیمات')),
          if (session.memberships.length > 1) const PopupMenuItem(value: 'switch', child: Text('تغییر مدرسه')),
          const PopupMenuItem(value: 'logout', child: Text('خروج')),
        ],
      ),
      const SizedBox(width: 8),
    ];

    if (w >= 760) {
      return Scaffold(
        appBar: AppBar(title: Text(title), actions: actions),
        body: Row(children: [
          SingleChildScrollView(
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: MediaQuery.sizeOf(context).height - kToolbarHeight),
              child: IntrinsicHeight(
                child: NavigationRail(
                  extended: w >= 1100, selectedIndex: idx, labelType: w >= 1100 ? NavigationRailLabelType.none : NavigationRailLabelType.all, minExtendedWidth: 210,
                  onDestinationSelected: (i) => context.go(items[i].path),
                  destinations: [for (final it in items) NavigationRailDestination(icon: Icon(it.icon), selectedIcon: Icon(it.selectedIcon ?? it.icon), label: Text(it.label), padding: const EdgeInsets.symmetric(vertical: 2))],
                ),
              ),
            ),
          ),
          const VerticalDivider(width: 1),
          Expanded(child: child),
        ]),
      );
    }
    // Phone: first 4 destinations + "more".
    final main = items.length > 5 ? items.take(4).toList() : items;
    final more = items.length > 5 ? items.skip(4).toList() : <NavItem>[];
    final onMore = idx >= main.length && more.isNotEmpty;
    return Scaffold(
      appBar: AppBar(title: Text(title), actions: actions),
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: onMore ? main.length : (idx < main.length ? idx : 0),
        onDestinationSelected: (i) async {
          if (i < main.length) {
            context.go(main[i].path);
          } else {
            final picked = await showModalBottomSheet<NavItem>(context: context, showDragHandle: true, builder: (c) => SafeArea(child: ListView(shrinkWrap: true, children: [for (final it in more) ListTile(leading: Icon(it.icon), title: Text(it.label), onTap: () => Navigator.pop(c, it))])));
            if (picked != null && context.mounted) context.go(picked.path);
          }
        },
        destinations: [
          for (final it in main) NavigationDestination(icon: Icon(it.icon), selectedIcon: Icon(it.selectedIcon ?? it.icon), label: it.label),
          if (more.isNotEmpty) const NavigationDestination(icon: Icon(Icons.more_horiz), label: 'بیشتر'),
        ],
      ),
    );
  }
}
