import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import 'jalali_picker.dart';
import 'theme.dart';

enum FieldType { text, multiline, number, password, email, date, time, datetime, dropdown, toggle, multiChoice }

class Option {
  const Option(this.value, this.label);
  final Object value;
  final String label;
}

/// Declarative form field. [optionsFrom] loads dropdown options from an endpoint (`data`/paginated `data` lists).
class FieldSpec {
  const FieldSpec(this.key, this.label, {this.type = FieldType.text, this.required = false, this.options, this.optionsFrom, this.labelKey = 'name', this.hint, this.initial, this.valueKey = 'id', this.optionLabel});
  final String key;
  final String label;
  final FieldType type;
  final bool required;
  final List<Option>? options;
  final String? optionsFrom;
  final String labelKey;
  final String valueKey;
  final String? Function(Map<String, dynamic>)? optionLabel;
  final String? hint;
  final Object? initial;
}

/// Opens a modal form; [submit] performs the API call with the collected values. Returns true on success.
Future<bool> showForm(BuildContext context, {required String title, required List<FieldSpec> fields, required Future<void> Function(Map<String, dynamic> values) submit, Map<String, dynamic> initial = const {}, String submitLabel = 'ذخیره'}) async {
  final r = await showDialog<bool>(context: context, barrierDismissible: false, builder: (_) => _FormDialog(title: title, fields: fields, submit: submit, initial: initial, submitLabel: submitLabel));
  return r ?? false;
}

class _FormDialog extends ConsumerStatefulWidget {
  const _FormDialog({required this.title, required this.fields, required this.submit, required this.initial, required this.submitLabel});
  final String title, submitLabel;
  final List<FieldSpec> fields;
  final Future<void> Function(Map<String, dynamic>) submit;
  final Map<String, dynamic> initial;
  @override
  ConsumerState<_FormDialog> createState() => _FormDialogState();
}

class _FormDialogState extends ConsumerState<_FormDialog> {
  final _key = GlobalKey<FormState>();
  final Map<String, dynamic> _v = {};
  final Map<String, TextEditingController> _c = {};
  final Map<String, List<Option>> _remote = {};
  Map<String, List<String>> _serverErrors = {};
  String? _topError;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    for (final f in widget.fields) {
      final init = widget.initial.containsKey(f.key) ? widget.initial[f.key] : f.initial;
      _v[f.key] = init;
      if ({FieldType.text, FieldType.multiline, FieldType.number, FieldType.password, FieldType.email}.contains(f.type)) {
        _c[f.key] = TextEditingController(text: init?.toString() ?? '');
      }
      if (f.optionsFrom != null) _loadOptions(f);
    }
  }

  Future<void> _loadOptions(FieldSpec f) async {
    try {
      final r = await ref.read(apiProvider).get(f.optionsFrom!, query: {'per_page': 100});
      final list = (r is Map ? r['data'] : r) as List;
      if (mounted) {
        setState(() => _remote[f.key] = list.map((e) {
              final m = Map<String, dynamic>.from(e as Map);
              return Option(m[f.valueKey] as Object, f.optionLabel?.call(m) ?? '${m[f.labelKey]}');
            }).toList());
      }
    } catch (_) {
      if (mounted) setState(() => _remote[f.key] = []);
    }
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _send() async {
    if (!_key.currentState!.validate()) return;
    final out = <String, dynamic>{};
    for (final f in widget.fields) {
      final c = _c[f.key];
      var v = c != null ? c.text.trim() : _v[f.key];
      if (f.type == FieldType.number && v is String) v = v.isEmpty ? null : num.tryParse(v.replaceAll(RegExp(r'[٠-٩۰-۹]'), '')) ?? v;
      if (v is String && v.isEmpty) v = null;
      out[f.key] = v;
    }
    setState(() { _busy = true; _serverErrors = {}; _topError = null; });
    try {
      await widget.submit(out);
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() { _serverErrors = e.errors; _topError = e.errors.isEmpty ? e.message : null; _busy = false; });
      _key.currentState?.validate();
    } catch (e) {
      setState(() { _topError = '$e'; _busy = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.title),
      content: SizedBox(
        width: 460,
        child: Form(
          key: _key,
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (_topError != null) Container(margin: const EdgeInsets.only(bottom: 12), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Palette.dangerSoft, borderRadius: BorderRadius.circular(10)), child: Text(_topError!, style: const TextStyle(color: Palette.danger))),
              for (final f in widget.fields) Padding(padding: const EdgeInsets.only(bottom: 14), child: _field(f)),
            ]),
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: _busy ? null : () => Navigator.pop(context, false), child: const Text('انصراف')),
        FilledButton(onPressed: _busy ? null : _send, child: _busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Text(widget.submitLabel)),
      ],
    );
  }

  String? _req(FieldSpec f, Object? v) {
    final server = _serverErrors[f.key]?.first;
    if (server != null) return server;
    if (f.required && (v == null || (v is String && v.trim().isEmpty) || (v is List && v.isEmpty))) return 'این فیلد الزامی است.';
    return null;
  }

  Widget _field(FieldSpec f) {
    switch (f.type) {
      case FieldType.text:
      case FieldType.multiline:
      case FieldType.number:
      case FieldType.password:
      case FieldType.email:
        return TextFormField(
          controller: _c[f.key], obscureText: f.type == FieldType.password, maxLines: f.type == FieldType.multiline ? 4 : 1, minLines: f.type == FieldType.multiline ? 3 : 1,
          keyboardType: f.type == FieldType.number ? TextInputType.number : (f.type == FieldType.email ? TextInputType.emailAddress : null),
          textDirection: f.type == FieldType.email || f.type == FieldType.password ? TextDirection.ltr : null,
          decoration: InputDecoration(labelText: f.label + (f.required ? ' *' : ''), hintText: f.hint),
          validator: (v) => _req(f, v),
        );
      case FieldType.date:
      case FieldType.datetime:
        final v = _v[f.key] as String?;
        return FormField<String>(
          validator: (_) => _req(f, v),
          builder: (st) => InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: () async {
              final cur = v != null ? DateTime.tryParse(v) : null;
              final d = await pickJalaliDate(context, initial: cur);
              if (d == null) return;
              if (f.type == FieldType.datetime) {
                if (!mounted) return;
                final t = await showTimePicker(context: context, initialTime: cur != null ? TimeOfDay.fromDateTime(cur) : const TimeOfDay(hour: 8, minute: 0));
                if (t == null) return;
                setState(() => _v[f.key] = DateTime(d.year, d.month, d.day, t.hour, t.minute).toIso8601String());
              } else {
                setState(() => _v[f.key] = '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}');
              }
              st.didChange(_v[f.key] as String);
            },
            child: InputDecorator(decoration: InputDecoration(labelText: f.label + (f.required ? ' *' : ''), suffixIcon: const Icon(Icons.calendar_month), errorText: st.errorText),
                child: Text(v == null ? '—' : fmtDate(v, withTime: f.type == FieldType.datetime))),
          ),
        );
      case FieldType.time:
        final v = _v[f.key] as String?;
        return FormField<String>(
          validator: (_) => _req(f, v),
          builder: (st) => InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: () async {
              final p = v?.split(':');
              final t = await showTimePicker(context: context, initialTime: p != null ? TimeOfDay(hour: int.parse(p[0]), minute: int.parse(p[1])) : const TimeOfDay(hour: 8, minute: 0));
              if (t == null) return;
              setState(() => _v[f.key] = '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}');
              st.didChange(_v[f.key] as String);
            },
            child: InputDecorator(decoration: InputDecoration(labelText: f.label + (f.required ? ' *' : ''), suffixIcon: const Icon(Icons.schedule), errorText: st.errorText), child: Text(v == null ? '—' : faDigits(v))),
          ),
        );
      case FieldType.dropdown:
        final opts = f.options ?? _remote[f.key];
        if (opts == null) return const LinearProgressIndicator();
        final cur = opts.any((o) => o.value == _v[f.key]) ? _v[f.key] : null;
        return DropdownButtonFormField<Object>(
          initialValue: cur, isExpanded: true,
          decoration: InputDecoration(labelText: f.label + (f.required ? ' *' : '')),
          items: [for (final o in opts) DropdownMenuItem(value: o.value, child: Text(o.label, overflow: TextOverflow.ellipsis))],
          onChanged: (x) => setState(() => _v[f.key] = x),
          validator: (v) => _req(f, v),
        );
      case FieldType.toggle:
        return SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(f.label), value: (_v[f.key] as bool?) ?? false, onChanged: (b) => setState(() => _v[f.key] = b));
      case FieldType.multiChoice:
        final opts = f.options ?? _remote[f.key] ?? [];
        final sel = List<Object>.from((_v[f.key] as List?) ?? []);
        return FormField<List<Object>>(
          validator: (_) => _req(f, sel),
          builder: (st) => InputDecorator(
            decoration: InputDecoration(labelText: f.label + (f.required ? ' *' : ''), errorText: st.errorText),
            child: Wrap(spacing: 8, runSpacing: 4, children: [
              for (final o in opts) FilterChip(label: Text(o.label), selected: sel.contains(o.value), onSelected: (on) { setState(() { on ? sel.add(o.value) : sel.remove(o.value); _v[f.key] = sel; }); st.didChange(sel); }),
            ]),
          ),
        );
    }
  }
}
