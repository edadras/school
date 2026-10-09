import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../core/session.dart';
import 'widgets.dart';

typedef Row_ = Map<String, dynamic>;

/// Server-side paginated list (Laravel `paginate` envelope). Search/filters are sent as query params; "load more" appends pages.
/// Call `PagedListState.reload()` through a [GlobalKey] after a mutation.
class PagedList extends ConsumerStatefulWidget {
  const PagedList({super.key, required this.path, required this.itemBuilder, this.query = const {}, this.emptyText = 'موردی یافت نشد.', this.dataKey = 'data', this.perPage = 25, this.header, this.shrink = false});
  final String path;
  final Map<String, dynamic> query;
  final Widget Function(BuildContext context, Row_ item, PagedListState state) itemBuilder;
  final String emptyText;
  final String dataKey;
  final int perPage;
  final Widget? header;
  final bool shrink;
  @override
  ConsumerState<PagedList> createState() => PagedListState();
}

class PagedListState extends ConsumerState<PagedList> {
  final List<Row_> _items = [];
  int _page = 0, _last = 1;
  bool _loading = false;
  Object? _error;
  int? total;

  @override
  void initState() {
    super.initState();
    reload();
  }

  @override
  void didUpdateWidget(covariant PagedList old) {
    super.didUpdateWidget(old);
    if (old.path != widget.path || old.query.toString() != widget.query.toString()) reload();
  }

  Future<void> reload() async {
    _items.clear();
    _page = 0;
    _last = 1;
    _error = null;
    await _more();
  }

  Future<void> _more() async {
    if (_loading || _page >= _last) return;
    setState(() => _loading = true);
    try {
      final r = await ref.read(apiProvider).get(widget.path, query: {...widget.query, 'page': _page + 1, 'per_page': widget.perPage});
      final m = r as Map;
      final list = (widget.dataKey.isEmpty ? m : (m[widget.dataKey] as Object?)) as List;
      _items.addAll(list.map((e) => Map<String, dynamic>.from(e as Map)));
      _page = (m['current_page'] ?? 1) as int;
      _last = (m['last_page'] ?? 1) as int;
      total = m['total'] as int?;
      _error = null;
    } catch (e) {
      _error = e;
    }
    if (mounted) setState(() => _loading = false);
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _items.isEmpty) return ErrorView(_error!, onRetry: reload);
    if (_loading && _items.isEmpty) return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
    if (_items.isEmpty) return EmptyState(widget.emptyText);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (widget.header != null) widget.header!,
      for (final it in _items) Padding(padding: const EdgeInsets.only(bottom: 10), child: widget.itemBuilder(context, it, this)),
      if (_page < _last) Center(child: _loading ? const Padding(padding: EdgeInsets.all(12), child: CircularProgressIndicator()) : TextButton(onPressed: _more, child: const Text('نمایش بیشتر'))),
      if (_error != null) Padding(padding: const EdgeInsets.all(8), child: ErrorView(_error!, onRetry: _more)),
    ]);
  }
}
