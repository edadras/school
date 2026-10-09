import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/strings.dart';
import '../../design/theme.dart';
import '../../design/widgets.dart';
import 'chat.dart';

final conversationsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final r = await ref.read(apiProvider).get('/conversations');
  return [for (final c in r['data'] as List) Map<String, dynamic>.from(c as Map)];
});

/// Class chats + direct conversations. Wide screens: list + chat side by side; phones: list → chat route.
class MessagesScreen extends ConsumerStatefulWidget {
  const MessagesScreen({super.key});
  @override
  ConsumerState<MessagesScreen> createState() => _MessagesState();
}

class _MessagesState extends ConsumerState<MessagesScreen> {
  int? _open;

  Future<void> _newDirect() async {
    final api = ref.read(apiProvider);
    final r = await api.get('/contacts');
    final contacts = [for (final c in r['data'] as List) Map<String, dynamic>.from(c as Map)];
    if (!mounted) return;
    final picked = await showDialog<Map<String, dynamic>>(context: context, builder: (c) => SimpleDialog(title: const Text('پیام خصوصی به...'), children: [
          if (contacts.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('مخاطبی در دسترس نیست.')),
          for (final u in contacts) SimpleDialogOption(onPressed: () => Navigator.pop(c, u), child: Row(children: [Expanded(child: Text(u['name'])), Text(roleName(u['role']), style: Theme.of(c).textTheme.bodySmall)])),
        ]));
    if (picked == null) return;
    try {
      final d = await api.post('/conversations/direct', data: {'user_id': picked['user_id']});
      ref.invalidate(conversationsProvider);
      final id = d['data']['id'] as int;
      if (MediaQuery.sizeOf(context).width >= 900) { setState(() => _open = id); } else if (mounted) { context.push('/chat/$id'); }
    } on ApiException catch (e) {
      if (mounted) toast(context, e.readable, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width >= 900;
    final list = Async<List<Map<String, dynamic>>>(ref.watch(conversationsProvider), onRetry: () => ref.invalidate(conversationsProvider), builder: (items) {
      if (items.isEmpty) return const EmptyState('گفت‌وگویی وجود ندارد.', icon: Icons.forum_outlined);
      return ListView(padding: const EdgeInsets.all(12), children: [
        for (final c in items)
          Padding(padding: const EdgeInsets.only(bottom: 8), child: AppCard(
            color: _open == c['id'] ? Palette.brandSoft : null,
            onTap: () => wide ? setState(() => _open = c['id'] as int) : context.push('/chat/${c['id']}'),
            child: Row(children: [
              Icon(c['type'] == 'class' ? Icons.groups_outlined : Icons.person_outline, color: Palette.brand),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${c['title'] ?? 'پیام خصوصی'}', style: Theme.of(context).textTheme.titleMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
                Text(c['last'] == null ? 'بدون پیام' : '${(c['last'] as Map)['body'] ?? 'پیوست'}', maxLines: 1, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.bodySmall),
              ])),
              if ((c['unread'] ?? 0) > 0) Badge(label: Text(faDigits(c['unread']))),
            ]),
          )),
      ]);
    });
    final header = Padding(padding: const EdgeInsets.fromLTRB(16, 12, 16, 0), child: Row(children: [Expanded(child: Text('پیام‌ها', style: Theme.of(context).textTheme.headlineSmall)), IconButton.filledTonal(key: const Key('new-direct'), onPressed: _newDirect, icon: const Icon(Icons.edit_outlined), tooltip: 'پیام خصوصی جدید'), IconButton(onPressed: () => ref.invalidate(conversationsProvider), icon: const Icon(Icons.refresh))]));
    if (!wide) return Column(children: [header, Expanded(child: list)]);
    return Row(children: [
      SizedBox(width: 340, child: Column(children: [header, Expanded(child: list)])),
      const VerticalDivider(width: 1),
      Expanded(child: _open == null ? const EmptyState('یک گفت‌وگو را انتخاب کنید.', icon: Icons.chat_outlined) : ChatView(key: ValueKey(_open), conversationId: _open!)),
    ]);
  }
}

class ChatPage extends StatelessWidget {
  const ChatPage({super.key, required this.id});
  final int id;
  @override
  Widget build(BuildContext context) => Scaffold(appBar: AppBar(title: const Text('گفت‌وگو'), leading: BackButton(onPressed: () => context.canPop() ? context.pop() : context.go('/'))), body: ChatView(conversationId: id));
}
