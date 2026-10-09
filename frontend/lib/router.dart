import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'core/session.dart';
import 'features/admin/admin_people.dart';
import 'features/admin/admin_screens.dart';
import 'features/admin/admin_settings.dart';
import 'features/admin/admin_structure.dart';
import 'features/admin/admin_timetable.dart';
import 'features/auth/auth_screens.dart';
import 'features/common/ai_chat.dart';
import 'features/common/messages_screen.dart';
import 'features/common/notifications.dart';
import 'features/common/profile.dart';
import 'features/common/report_cards.dart';
import 'features/guardian/guardian_screens.dart';
import 'features/live/live_screen.dart';
import 'features/platform/platform_screens.dart';
import 'features/shell.dart';
import 'features/student/assignments.dart';
import 'features/student/exams.dart';
import 'features/common/student_file.dart';
import 'features/student/student_screens.dart';
import 'features/teacher/teacher_assignments.dart';
import 'features/teacher/teacher_exams.dart';
import 'features/teacher/teacher_grades.dart';
import 'features/teacher/teacher_home.dart';

/// Re-evaluates redirects whenever the session changes.
class _SessionListenable extends ChangeNotifier {
  _SessionListenable(Ref ref) {
    ref.listen<Session>(sessionProvider, (_, _) => notifyListeners());
  }
}

const _publicPaths = {'/login', '/register-school', '/forgot', '/reset-password'};

final routerProvider = Provider<GoRouter>((ref) {
  final listenable = _SessionListenable(ref);

  ShellRoute shell(Panel p, List<RouteBase> routes) => ShellRoute(builder: (c, s, child) => AppShell(panel: p, child: child), routes: routes);
  GoRoute r(String path, Widget Function(GoRouterState) b) => GoRoute(path: path, pageBuilder: (c, s) => NoTransitionPage(key: s.pageKey, child: b(s)));

  return GoRouter(
    initialLocation: '/',
    refreshListenable: listenable,
    redirect: (context, state) {
      final s = ref.read(sessionProvider);
      final loc = state.uri.path;
      if (!s.ready) return loc == '/' ? null : '/';
      if (!s.signedIn) return _publicPaths.contains(loc) ? null : '/login';
      if (s.user?['two_factor_setup_required'] == true) return loc == '/profile' ? null : '/profile';   // platform policy: enrol first
      if (s.needsSchoolChoice) return loc == '/choose-school' ? null : '/choose-school';
      // Organisation whose school is not active yet (pending / needs changes / rejected / suspended).
      if (s.platformRole == null && s.active != null && s.active!.schoolStatus != 'active') return loc == '/pending' ? null : '/pending';
      final panel = s.panel;
      if (panel == null) return '/login';
      final base = basePath(panel);
      if (loc == '/' || _publicPaths.contains(loc) || loc == '/choose-school' || loc == '/pending') return base;
      const shared = ['/notifications', '/profile', '/live/', '/exam/', '/assignment/', '/report-card/', '/chat/', '/file'];
      if (shared.any(loc.startsWith)) return null;
      if (!loc.startsWith(base)) return base;
      return null;
    },
    routes: [
      GoRoute(path: '/', builder: (c, s) => const Scaffold(body: Center(child: CircularProgressIndicator()))),
      GoRoute(path: '/login', builder: (c, s) => const LoginScreen()),
      GoRoute(path: '/register-school', builder: (c, s) => const RegisterSchoolScreen()),
      GoRoute(path: '/forgot', builder: (c, s) => const ForgotScreen()),
      GoRoute(path: '/reset-password', builder: (c, s) => ResetPasswordScreen(token: s.uri.queryParameters['token'] ?? '', email: s.uri.queryParameters['email'] ?? '')),
      GoRoute(path: '/choose-school', builder: (c, s) => const ChooseSchoolScreen()),
      GoRoute(path: '/pending', builder: (c, s) => const PendingScreen()),
      GoRoute(path: '/notifications', builder: (c, s) => const NotificationsScreen()),
      GoRoute(path: '/profile', builder: (c, s) => const ProfileScreen()),
      GoRoute(path: '/live/:id', builder: (c, s) => LiveScreen(key: ValueKey('live-${s.pathParameters['id']}'), sessionId: int.parse(s.pathParameters['id']!))),
      GoRoute(path: '/exam/:id', builder: (c, s) => ExamTakeScreen(attemptId: int.parse(s.pathParameters['id']!))),
      GoRoute(path: '/assignment/:id', builder: (c, s) => AssignmentDetail(id: int.parse(s.pathParameters['id']!))),
      GoRoute(path: '/report-card/:id', builder: (c, s) => ReportCardViewer(id: int.parse(s.pathParameters['id']!))),
      GoRoute(path: '/file', builder: (c, s) => const StudentFileScreen()),
      GoRoute(path: '/file/:id', builder: (c, s) => StudentFileScreen(studentId: int.parse(s.pathParameters['id']!))),
      GoRoute(path: '/chat/:id', builder: (c, s) => ChatPage(id: int.parse(s.pathParameters['id']!))),

      shell(Panel.platform, [
        r('/platform', (s) => const PlatformDashboard()), r('/platform/approvals', (s) => const PlatformApprovals()), r('/platform/schools', (s) => const PlatformSchools()),
        r('/platform/support', (s) => const PlatformSupport()), r('/platform/announcements', (s) => const PlatformAnnouncements()), r('/platform/operators', (s) => const PlatformOperators()),
        r('/platform/audit', (s) => const PlatformAudit()), r('/platform/settings', (s) => const PlatformSettings()),
      ]),
      shell(Panel.support, [r('/support', (s) => const PlatformSupport())]),
      shell(Panel.admin, [
        r('/admin', (s) => const AdminDashboard()), r('/admin/people', (s) => const AdminPeople()), r('/admin/structure', (s) => const AdminStructure()), r('/admin/timetable', (s) => const AdminTimetable()),
        r('/admin/live', (s) => const AdminLive()), r('/admin/attendance', (s) => const AdminAttendance()), r('/admin/learning', (s) => const AdminLearning()), r('/admin/grades', (s) => const AdminGrades()),
        r('/admin/reports', (s) => const AdminReports()), r('/admin/messages', (s) => const AdminMessages()), r('/admin/ai', (s) => const AdminAi()), r('/admin/settings', (s) => const AdminSettings()),
      ]),
      shell(Panel.teacher, [
        r('/teacher', (s) => const TeacherHome()), r('/teacher/classes', (s) => const TeacherClasses()),
        r('/teacher/classes/:section/:subject', (s) => ClassDetail(key: ValueKey('${s.pathParameters}'), sectionId: int.parse(s.pathParameters['section']!), subjectId: int.parse(s.pathParameters['subject']!))),
        r('/teacher/assignments', (s) => const TeacherAssignments()), r('/teacher/assignments/:id', (s) => AssignmentReview(id: int.parse(s.pathParameters['id']!))),
        r('/teacher/exams', (s) => const TeacherExams()), r('/teacher/exams/:id', (s) => ExamManage(id: int.parse(s.pathParameters['id']!))),
        r('/teacher/grades', (s) => const GradeBook()), r('/teacher/messages', (s) => const MessagesScreen()), r('/teacher/ai', (s) => const AiScreen(teacher: true)),
      ]),
      shell(Panel.student, [
        r('/student', (s) => const StudentHome()), r('/student/assignments', (s) => const AssignmentsList()), r('/student/exams', (s) => const ExamsList()), r('/student/learn', (s) => const LearnScreen()),
        r('/student/grades', (s) => const GradesScreen()), r('/student/messages', (s) => const MessagesScreen()), r('/student/ai', (s) => const AiScreen()),
      ]),
      shell(Panel.guardian, [
        r('/guardian', (s) => const GuardianHome()), r('/guardian/schedule', (s) => const GuardianSchedule()), r('/guardian/attendance', (s) => const GuardianAttendance()),
        r('/guardian/grades', (s) => const GuardianGrades()), r('/guardian/assignments', (s) => const GuardianAssignments()), r('/guardian/messages', (s) => const MessagesScreen()), r('/guardian/meetings', (s) => const GuardianMeetings()),
      ]),
    ],
    errorBuilder: (c, s) => Scaffold(body: Center(child: Column(mainAxisSize: MainAxisSize.min, children: [const Text('صفحه یافت نشد.'), TextButton(onPressed: () => c.go('/'), child: const Text('بازگشت'))]))),
  );
});
