import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../core/api.dart';
import 'theme.dart';

/// Centered column that stops stretching on wide screens.
class Constrained extends StatelessWidget {
  const Constrained({super.key, required this.child, this.maxWidth = 1100, this.padding});
  final Widget child;
  final double maxWidth;
  final EdgeInsets? padding;
  @override
  Widget build(BuildContext context) => Align(
        alignment: Alignment.topCenter,
        child: ConstrainedBox(constraints: BoxConstraints(maxWidth: maxWidth), child: Padding(padding: padding ?? EdgeInsets.symmetric(horizontal: MediaQuery.sizeOf(context).width < 600 ? 16 : 24, vertical: 20), child: child)),
      );
}

/// A scrollable page body with the standard gutter.
class PageBody extends StatelessWidget {
  const PageBody({super.key, required this.children, this.maxWidth = 1100, this.onRefresh});
  final List<Widget> children;
  final double maxWidth;
  final Future<void> Function()? onRefresh;
  @override
  Widget build(BuildContext context) {
    final list = ListView(padding: EdgeInsets.zero, children: [Constrained(maxWidth: maxWidth, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children))]);
    return onRefresh == null ? list : RefreshIndicator(onRefresh: onRefresh!, child: list);
  }
}

class AppCard extends StatelessWidget {
  const AppCard({super.key, required this.child, this.padding = const EdgeInsets.all(16), this.onTap, this.color});
  final Widget child;
  final EdgeInsets padding;
  final VoidCallback? onTap;
  final Color? color;
  @override
  Widget build(BuildContext context) => Card(
        color: color,
        child: InkWell(borderRadius: BorderRadius.circular(14), onTap: onTap, child: Padding(padding: padding, child: child)),
      );
}

class PageHeader extends StatelessWidget {
  const PageHeader(this.title, {super.key, this.subtitle, this.actions = const []});
  final String title;
  final String? subtitle;
  final List<Widget> actions;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 12, runSpacing: 8, children: [
          Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            Text(title, style: Theme.of(context).textTheme.headlineSmall),
            if (subtitle != null) Text(subtitle!, style: Theme.of(context).textTheme.bodySmall),
          ]),
          if (actions.isNotEmpty) Wrap(spacing: 8, runSpacing: 8, children: actions),
        ]),
      );
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key, this.trailing});
  final String text;
  final Widget? trailing;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 20, bottom: 10),
        child: Row(children: [Expanded(child: Text(text, style: Theme.of(context).textTheme.titleLarge)), ?trailing]),
      );
}

class EmptyState extends StatelessWidget {
  const EmptyState(this.message, {super.key, this.icon = Icons.inbox_outlined, this.action});
  final String message;
  final IconData icon;
  final Widget? action;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(32),
        child: Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 44, color: Palette.brandMid),
          const SizedBox(height: 10),
          Text(message, textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodySmall),
          if (action != null) ...[const SizedBox(height: 12), action!],
        ])),
      );
}

class ErrorView extends StatelessWidget {
  const ErrorView(this.error, {super.key, this.onRetry});
  final Object error;
  final VoidCallback? onRetry;
  @override
  Widget build(BuildContext context) {
    final offline = error is ApiException && (error as ApiException).isOffline;
    return Padding(
      padding: const EdgeInsets.all(32),
      child: Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
        Icon(offline ? Icons.wifi_off_rounded : Icons.error_outline, size: 44, color: offline ? Palette.warn : Palette.danger),
        const SizedBox(height: 10),
        Text(error is ApiException ? (error as ApiException).readable : 'خطایی رخ داد.', textAlign: TextAlign.center),
        if (onRetry != null) ...[const SizedBox(height: 12), OutlinedButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: const Text('تلاش دوباره'))],
      ])),
    );
  }
}

/// Renders an [AsyncValue] with consistent loading / error / data states.
class Async<T> extends StatelessWidget {
  const Async(this.value, {super.key, required this.builder, this.onRetry, this.skeleton});
  final AsyncValue<T> value;
  final Widget Function(T data) builder;
  final VoidCallback? onRetry;
  final Widget? skeleton;
  @override
  Widget build(BuildContext context) => value.when(
        data: builder,
        loading: () => skeleton ?? const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator())),
        error: (e, _) => ErrorView(e, onRetry: onRetry),
      );
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key, this.tone = Tone.info});
  final String label;
  final Tone tone;
  @override
  Widget build(BuildContext context) {
    final (bg, fg) = switch (tone) {
      Tone.success => (Palette.successSoft, Palette.success),
      Tone.warn => (Palette.warnSoft, Palette.warn),
      Tone.danger => (Palette.dangerSoft, Palette.danger),
      Tone.neutral => (const Color(0xFFEEF2F7), Palette.muted),
      Tone.info => (Palette.brandSoft, Palette.brand),
    };
    return Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3), decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
        child: Text(label, style: TextStyle(color: fg, fontSize: 12.5, fontWeight: FontWeight.w500)));
  }
}

enum Tone { info, success, warn, danger, neutral }

class StatCard extends StatelessWidget {
  const StatCard({super.key, required this.label, required this.value, this.icon, this.tone = Tone.info, this.onTap});
  final String label;
  final String value;
  final IconData? icon;
  final Tone tone;
  final VoidCallback? onTap;
  @override
  Widget build(BuildContext context) {
    final color = switch (tone) { Tone.success => Palette.success, Tone.warn => Palette.warn, Tone.danger => Palette.danger, _ => Palette.brand };
    return AppCard(
      onTap: onTap,
      child: Row(children: [
        if (icon != null) Container(width: 42, height: 42, decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)), child: Icon(icon, color: color)),
        if (icon != null) const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(value, style: Theme.of(context).textTheme.titleLarge?.copyWith(color: color)),
          Text(label, style: Theme.of(context).textTheme.bodySmall, maxLines: 2, overflow: TextOverflow.ellipsis),
        ])),
      ]),
    );
  }
}

/// A button that shows progress and disables itself while the async action runs; errors become toasts.
class ActionButton extends StatefulWidget {
  const ActionButton({super.key, required this.label, required this.onPressed, this.icon, this.outlined = false, this.danger = false});
  final String label;
  final Future<void> Function()? onPressed;
  final IconData? icon;
  final bool outlined;
  final bool danger;
  @override
  State<ActionButton> createState() => _ActionButtonState();
}

class _ActionButtonState extends State<ActionButton> {
  bool _busy = false;
  Future<void> _run() async {
    setState(() => _busy = true);
    try {
      await widget.onPressed!();
    } catch (e) {
      if (mounted) toast(context, e is ApiException ? e.readable : '$e', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final child = _busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Row(mainAxisSize: MainAxisSize.min, children: [if (widget.icon != null) ...[Icon(widget.icon, size: 18), const SizedBox(width: 6)], Flexible(child: Text(widget.label))]);
    final cb = widget.onPressed == null || _busy ? null : _run;
    if (widget.outlined) {
      return OutlinedButton(onPressed: cb, style: widget.danger ? OutlinedButton.styleFrom(foregroundColor: Palette.danger, side: const BorderSide(color: Palette.danger)) : null, child: child);
    }
    return FilledButton(onPressed: cb, style: widget.danger ? FilledButton.styleFrom(backgroundColor: Palette.danger) : null, child: child);
  }
}

void toast(BuildContext context, String msg, {bool error = false}) {
  final m = ScaffoldMessenger.maybeOf(context);
  m?.hideCurrentSnackBar();
  m?.showSnackBar(SnackBar(content: Text(msg), backgroundColor: error ? Palette.danger : Palette.text, duration: Duration(seconds: error ? 6 : 3)));
}

Future<bool> confirm(BuildContext context, String message, {String ok = 'تأیید', bool danger = false}) async {
  final r = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
            content: Text(message),
            actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('انصراف')), FilledButton(style: danger ? FilledButton.styleFrom(backgroundColor: Palette.danger) : null, onPressed: () => Navigator.pop(c, true), child: Text(ok))],
          ));
  return r ?? false;
}

/// Prompts for a short text (e.g. a reason). Returns null when cancelled.
Future<String?> promptText(BuildContext context, String title, {String label = 'توضیح', bool required = true, int maxLines = 3}) async {
  final c = TextEditingController();
  return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
            title: Text(title),
            content: SizedBox(width: 420, child: TextField(controller: c, autofocus: true, maxLines: maxLines, decoration: InputDecoration(labelText: label))),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('انصراف')),
              FilledButton(onPressed: () => (required && c.text.trim().isEmpty) ? null : Navigator.pop(ctx, c.text.trim()), child: const Text('ثبت')),
            ],
          ));
}

class LabeledRow extends StatelessWidget {
  const LabeledRow(this.label, this.value, {super.key});
  final String label;
  final Widget value;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 120, child: Text(label, style: Theme.of(context).textTheme.bodySmall)),
          Expanded(child: value),
        ]),
      );
}

/// Debounced search box.
class SearchField extends StatefulWidget {
  const SearchField({super.key, required this.onChanged, this.hint = 'جست‌وجو'});
  final ValueChanged<String> onChanged;
  final String hint;
  @override
  State<SearchField> createState() => _SearchFieldState();
}

class _SearchFieldState extends State<SearchField> {
  Timer? _t;
  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
        decoration: InputDecoration(hintText: widget.hint, prefixIcon: const Icon(Icons.search), isDense: true),
        onChanged: (v) {
          _t?.cancel();
          _t = Timer(const Duration(milliseconds: 350), () => widget.onChanged(v));
        },
      );
}
