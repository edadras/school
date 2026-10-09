import 'dart:async';
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'config.dart';

/// A server-reported failure with a stable machine code (e.g. `media_unconfigured`, `ai_unconfigured`).
class ApiException implements Exception {
  ApiException(this.message, {this.status, this.code, this.errors = const {}});
  final String message;
  final int? status;
  final String? code;
  final Map<String, List<String>> errors;

  bool get isUnauthorized => status == 401;
  bool get isOffline => status == null;

  /// First validation message, else the main message.
  String get readable => errors.values.expand((e) => e).firstOrNull ?? message;
  @override
  String toString() => readable;
}

/// Thin typed wrapper over Dio. Auth token and school selection come from [SessionStore] callbacks
/// so the same client works across logins without being recreated.
class Api {
  Api({required this.token, required this.schoolId, required this.onUnauthorized, Dio? dio})
      : _dio = dio ?? Dio(BaseOptions(baseUrl: AppConfig.apiBase, connectTimeout: const Duration(seconds: 15), receiveTimeout: const Duration(seconds: 45))) {
    _dio.interceptors.add(InterceptorsWrapper(onRequest: (o, h) {
      final t = token();
      if (t != null) o.headers['Authorization'] = 'Bearer $t';
      final s = schoolId();
      if (s != null) o.headers['X-School-Id'] = '$s';
      o.headers['Accept'] = 'application/json';
      h.next(o);
    }));
  }

  final Dio _dio;
  final String? Function() token;
  final int? Function() schoolId;
  final void Function() onUnauthorized;

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) => _send(() => _dio.get(path, queryParameters: _clean(query)));
  Future<dynamic> post(String path, {Object? data}) => _send(() => _dio.post(path, data: data));
  Future<dynamic> put(String path, {Object? data}) => _send(() => _dio.put(path, data: data));
  Future<dynamic> patch(String path, {Object? data}) => _send(() => _dio.patch(path, data: data));
  Future<dynamic> delete(String path, {Object? data}) => _send(() => _dio.delete(path, data: data));

  /// Multipart upload to private storage; returns the stored file record.
  Future<Map<String, dynamic>> upload(PlatformFile f, {String path = '/files'}) async {
    final form = FormData.fromMap({
      'file': f.bytes != null ? MultipartFile.fromBytes(f.bytes!, filename: f.name) : await MultipartFile.fromFile(f.path!, filename: f.name),
    });
    final r = await _send(() => _dio.post(path, data: form));
    return Map<String, dynamic>.from(r['data'] as Map);
  }

  Future<dynamic> postForm(String path, FormData form) => _send(() => _dio.post(path, data: form));

  /// Raw bytes (PDF of a report card). Sent with auth headers; never exposed as a public URL.
  Future<List<int>> bytes(String path) async {
    try {
      final r = await _dio.get<List<int>>(path, options: Options(responseType: ResponseType.bytes));
      return r.data ?? const [];
    } on DioException catch (e) {
      throw _toException(e);
    }
  }

  Map<String, dynamic>? _clean(Map<String, dynamic>? q) => q == null ? null : (Map.of(q)..removeWhere((k, v) => v == null || v == ''));

  Future<dynamic> _send(Future<Response<dynamic>> Function() call) async {
    try {
      final r = await call();
      return r.data;
    } on DioException catch (e) {
      final ex = _toException(e);
      if (ex.isUnauthorized) onUnauthorized();
      throw ex;
    }
  }

  ApiException _toException(DioException e) {
    final res = e.response;
    if (res == null) return ApiException('اتصال به سرور برقرار نشد. اینترنت خود را بررسی کنید.');
    final d = res.data;
    var errors = <String, List<String>>{};
    String msg = 'خطای ناشناخته (${res.statusCode})';
    String? code;
    if (d is Map) {
      msg = (d['message'] ?? msg).toString();
      code = d['code']?.toString();
      final er = d['errors'];
      if (er is Map) errors = er.map((k, v) => MapEntry(k.toString(), (v as List).map((x) => x.toString()).toList()));
    }
    if (res.statusCode == 429) msg = 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.';
    return ApiException(msg, status: res.statusCode, code: code, errors: errors);
  }
}
