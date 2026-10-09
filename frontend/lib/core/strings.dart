/// Minimal translation table for shared labels. Persian is the source of truth; other languages fall back to it.
/// To add a language: add a map below, register its Locale in app.dart, and route `tr()` through the active locale.
const Map<String, Map<String, String>> _tables = {
  'fa': {
    'role.school_admin': 'مدیر مدرسه', 'role.deputy': 'معاون آموزشی', 'role.teacher': 'معلم', 'role.student': 'دانش‌آموز', 'role.guardian': 'والد',
    'role.super_admin': 'مدیر کل سامانه', 'role.support': 'پشتیبان فنی',
    'status.pending': 'در انتظار بررسی', 'status.active': 'فعال', 'status.rejected': 'ردشده', 'status.suspended': 'تعلیق', 'status.needs_changes': 'نیازمند اصلاح',
    'status.approved': 'تأییدشده', 'status.draft': 'پیش‌نویس', 'status.published': 'منتشرشده', 'status.closed': 'بسته', 'status.archived': 'بایگانی',
    'status.scheduled': 'برنامه‌ریزی‌شده', 'status.live': 'در حال برگزاری', 'status.ended': 'پایان‌یافته', 'status.not_held': 'برگزار نشد', 'status.technical_issue': 'مشکل فنی',
    'status.viewed': 'مشاهده‌شده', 'status.in_progress': 'در حال انجام', 'status.submitted': 'ارسال‌شده', 'status.late': 'دیرکرد', 'status.under_review': 'در حال تصحیح',
    'status.needs_revision': 'نیازمند اصلاح', 'status.finalized': 'نهایی‌شده', 'status.graded': 'تصحیح‌شده', 'status.overdue': 'عقب‌افتاده',
    'status.issued': 'صادرشده', 'status.revoked': 'ابطال‌شده', 'status.open': 'باز', 'status.resolved': 'حل‌شده',
    'att.present': 'حاضر', 'att.late': 'تأخیر', 'att.absent': 'غایب', 'att.excused': 'موجه',
    'result.passed': 'قبول', 'result.failed': 'مردود', 'result.makeup': 'نیازمند جبرانی',
    'kind.exam': 'آزمون', 'kind.assignment': 'تکلیف', 'kind.classwork': 'فعالیت کلاسی', 'kind.oral': 'شفاهی', 'kind.project': 'پروژه', 'kind.final': 'پایانی',
    'qtype.mcq': 'چندگزینه‌ای', 'qtype.tf': 'درست/غلط', 'qtype.fill': 'جای خالی', 'qtype.short': 'پاسخ کوتاه', 'qtype.essay': 'تشریحی',
  },
};

String tr(String key, {String locale = 'fa'}) => _tables[locale]?[key] ?? _tables['fa']![key] ?? key;
String roleName(String? r) => tr('role.$r');
String statusName(String? s) => tr('status.$s');
