import 'package:shamsi_date/shamsi_date.dart';


const _fa = '۰۱۲۳۴۵۶۷۸۹';

/// Latin digits → Persian digits (display only).
String faDigits(Object? v) => (v ?? '').toString().replaceAllMapped(RegExp(r'\d'), (m) => _fa[int.parse(m[0]!)]);

const jalaliMonths = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

/// Weekday index used by the API: 0 = Saturday … 6 = Friday.
const weekdayNames = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

int apiWeekday(DateTime d) => (d.weekday + 1) % 7; // Dart: Mon=1..Sun=7 → Sat=0

/// Date display preference (school setting `calendar`). Default Jalali.
String fmtDate(Object? iso, {bool jalali = true, bool withTime = false}) {
  if (iso == null) return '—';
  final d = DateTime.tryParse(iso.toString())?.toLocal();
  if (d == null) return iso.toString();
  final time = withTime ? '  ${faDigits('${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}')}' : '';
  if (!jalali) return '${d.year}/${d.month.toString().padLeft(2, '0')}/${d.day.toString().padLeft(2, '0')}$time';
  final j = Jalali.fromDateTime(d);
  return faDigits('${j.year}/${j.month.toString().padLeft(2, '0')}/${j.day.toString().padLeft(2, '0')}') + time;
}

String fmtLongDate(DateTime d) {
  final j = Jalali.fromDateTime(d);
  return '${weekdayNames[apiWeekday(d)]} ${faDigits(j.day)} ${jalaliMonths[j.month - 1]} ${faDigits(j.year)}';
}

/// Parses `HH:mm[:ss]` to minutes since midnight.
int minutesOf(String hhmm) {
  final p = hhmm.split(':');
  return int.parse(p[0]) * 60 + int.parse(p[1]);
}

String fmtDuration(Duration d) {
  final neg = d.isNegative;
  final a = d.abs();
  final h = a.inHours;
  final m = a.inMinutes.remainder(60).toString().padLeft(2, '0');
  final s = a.inSeconds.remainder(60).toString().padLeft(2, '0');
  return faDigits('${neg ? '-' : ''}${h > 0 ? '$h:' : ''}$m:$s');
}

String fmtBytes(num b) {
  if (b < 1024) return '${faDigits(b.toInt())} بایت';
  if (b < 1048576) return '${faDigits((b / 1024).toStringAsFixed(0))} کیلوبایت';
  return '${faDigits((b / 1048576).toStringAsFixed(1))} مگابایت';
}

String fmtNum(Object? n) {
  if (n == null) return '—';
  final v = n is num ? n : num.tryParse('$n');
  if (v == null) return '$n';
  return faDigits(v % 1 == 0 ? v.toInt() : v.toStringAsFixed(2).replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), ''));
}
