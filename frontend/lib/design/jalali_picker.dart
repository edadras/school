import 'package:flutter/material.dart';
import 'package:shamsi_date/shamsi_date.dart';
import '../core/format.dart';
import 'theme.dart';

/// Persian (Jalali) calendar date picker. Returns a Gregorian [DateTime] (date only) — the API speaks Gregorian ISO dates.
Future<DateTime?> pickJalaliDate(BuildContext context, {DateTime? initial, DateTime? first, DateTime? last}) {
  return showDialog<DateTime>(context: context, builder: (_) => _JalaliDialog(initial: initial ?? DateTime.now(), first: first, last: last));
}

class _JalaliDialog extends StatefulWidget {
  const _JalaliDialog({required this.initial, this.first, this.last});
  final DateTime initial;
  final DateTime? first, last;
  @override
  State<_JalaliDialog> createState() => _JalaliDialogState();
}

class _JalaliDialogState extends State<_JalaliDialog> {
  late int year, month;
  late Jalali selected;

  @override
  void initState() {
    super.initState();
    selected = Jalali.fromDateTime(widget.initial);
    year = selected.year;
    month = selected.month;
  }

  void _shift(int d) {
    setState(() {
      month += d;
      if (month > 12) { month = 1; year++; }
      if (month < 1) { month = 12; year--; }
    });
  }

  bool _allowed(Jalali j) {
    final g = j.toDateTime();
    if (widget.first != null && g.isBefore(DateTime(widget.first!.year, widget.first!.month, widget.first!.day))) return false;
    if (widget.last != null && g.isAfter(widget.last!)) return false;
    return true;
  }

  @override
  Widget build(BuildContext context) {
    final first = Jalali(year, month, 1);
    final offset = (first.weekDay - 1); // Jalali weekDay: 1 = Saturday
    final days = Jalali(year, month, 1).monthLength;
    final today = Jalali.now();
    return Dialog(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 340),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              IconButton(onPressed: () => _shift(-1), icon: const Icon(Icons.chevron_right), tooltip: 'ماه قبل'),
              Expanded(child: Text('${jalaliMonths[month - 1]} ${faDigits(year)}', textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleMedium)),
              IconButton(onPressed: () => _shift(1), icon: const Icon(Icons.chevron_left), tooltip: 'ماه بعد'),
            ]),
            Row(children: [for (final d in ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج']) Expanded(child: Center(child: Text(d, style: Theme.of(context).textTheme.bodySmall)))]),
            const SizedBox(height: 6),
            GridView.count(
              crossAxisCount: 7, shrinkWrap: true, physics: const NeverScrollableScrollPhysics(), childAspectRatio: 1.1,
              children: [
                for (var i = 0; i < offset; i++) const SizedBox(),
                for (var d = 1; d <= days; d++)
                  Builder(builder: (_) {
                    final j = Jalali(year, month, d);
                    final sel = j == selected;
                    final ok = _allowed(j);
                    return InkWell(
                      borderRadius: BorderRadius.circular(20),
                      onTap: ok ? () => Navigator.pop(context, j.toDateTime()) : null,
                      child: Container(
                        margin: const EdgeInsets.all(2),
                        decoration: BoxDecoration(color: sel ? Palette.brand : null, shape: BoxShape.circle, border: j == today && !sel ? Border.all(color: Palette.brand) : null),
                        alignment: Alignment.center,
                        child: Text(faDigits(d), style: TextStyle(color: sel ? Colors.white : (ok ? Palette.text : Palette.border), fontWeight: sel ? FontWeight.w700 : null)),
                      ),
                    );
                  }),
              ],
            ),
            Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: () => Navigator.pop(context), child: const Text('انصراف'))),
          ]),
        ),
      ),
    );
  }
}
