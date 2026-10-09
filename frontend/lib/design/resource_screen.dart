import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../core/api.dart';
import '../core/session.dart';
import 'forms.dart';
import 'paged_list.dart';
import 'theme.dart';
import 'widgets.dart';

/// Declarative CRUD screen over `/academics/{resource}` style endpoints: server-side search/pagination,
/// create/edit through the schema-driven form, delete with server-side dependency checks (409 shown to the user).
class ResourceScreen extends ConsumerStatefulWidget {
  const ResourceScreen({
    super.key, required this.title, required this.path, required this.fields, required this.itemTitle, this.itemSubtitle,
    this.canDelete = true, this.searchable = true, this.query = const {}, this.extraActions, this.createLabel = 'افزودن', this.leading, this.embed = false,
  });
  final String title, path, createLabel;
  final List<FieldSpec> fields;
  final String Function(Map<String, dynamic> row) itemTitle;
  final String? Function(Map<String, dynamic> row)? itemSubtitle;
  final bool canDelete, searchable, embed;
  final Map<String, dynamic> query;
  final List<Widget> Function(Map<String, dynamic> row, PagedListState list)? extraActions;
  final IconData? leading;

  @override
  ConsumerState<ResourceScreen> createState() => _ResourceScreenState();
}

class _ResourceScreenState extends ConsumerState<ResourceScreen> {
  final _key = GlobalKey<PagedListState>();
  String _q = '';

  Future<void> _edit([Map<String, dynamic>? row]) async {
    final api = ref.read(apiProvider);
    final ok = await showForm(context, title: row == null ? '${widget.createLabel} — ${widget.title}' : 'ویرایش', fields: widget.fields, initial: row ?? const {},
        submit: (v) async => row == null ? api.post(widget.path, data: v) : api.patch('${widget.path}/${row['id']}', data: v));
    if (ok) _key.currentState?.reload();
  }

  Future<void> _delete(Map<String, dynamic> row) async {
    if (!await confirm(context, 'این مورد حذف شود؟', danger: true, ok: 'حذف')) return;
    try {
      await ref.read(apiProvider).delete('${widget.path}/${row['id']}');
      _key.currentState?.reload();
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final body = <Widget>[
      PageHeader(widget.title, actions: [FilledButton.icon(key: Key('add-${widget.path}'), onPressed: () => _edit(), icon: const Icon(Icons.add), label: Text(widget.createLabel))]),
      if (widget.searchable) Padding(padding: const EdgeInsets.only(bottom: 12), child: SearchField(onChanged: (v) => setState(() => _q = v))),
      PagedList(
        key: _key, path: widget.path, query: {...widget.query, 'q': _q}, emptyText: 'موردی ثبت نشده است.',
        itemBuilder: (c, row, st) => AppCard(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(children: [
            if (widget.leading != null) ...[Icon(widget.leading, color: Palette.brand), const SizedBox(width: 12)],
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(widget.itemTitle(row), style: Theme.of(c).textTheme.titleMedium),
              if (widget.itemSubtitle?.call(row) != null) Text(widget.itemSubtitle!(row)!, style: Theme.of(c).textTheme.bodySmall),
            ])),
            ...?widget.extraActions?.call(row, st),
            IconButton(tooltip: 'ویرایش', onPressed: () => _edit(row), icon: const Icon(Icons.edit_outlined)),
            if (widget.canDelete) IconButton(tooltip: 'حذف', onPressed: () => _delete(row), icon: const Icon(Icons.delete_outline, color: Palette.danger)),
          ]),
        ),
      ),
    ];
    return widget.embed ? PageBody(children: body) : PageBody(children: body);
  }
}
