import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/session.dart';
import '../../design/forms.dart';

/// (section, subject) pairs the caller teaches.
final teachingProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final r = await ref.read(apiProvider).get('/me/teaching');
  return [for (final x in r['data'] as List) Map<String, dynamic>.from(x as Map)];
});

/// Dropdown option "section — subject" encoded as the assignment id; callers map back to ids.
List<Option> teachingOptions(List<Map<String, dynamic>> t) => [for (final x in t) Option(x['id'] as int, x['label'] as String)];

Map<String, dynamic>? pairById(List<Map<String, dynamic>> t, Object? id) => t.where((x) => x['id'] == id).firstOrNull;

/// Terms of the current school (for grade entry).
final termsListProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final r = await ref.read(apiProvider).get('/academics/terms');
  return [for (final x in r['data'] as List) Map<String, dynamic>.from(x as Map)];
});
