import 'package:dio/dio.dart';

import '../core/constants.dart';
import 'token_store.dart';

/// API hata sarmalayıcı.
class ApiException implements Exception {
  final String code;
  final String message;
  final int? status;
  final Map<String, dynamic>? extra;
  ApiException(this.code, this.message, {this.status, this.extra});
  @override
  String toString() => message;
}

/// Dio tabanlı API istemcisi. JWT ekler ve 401'de refresh dener.
class ApiClient {
  final Dio _dio;
  final TokenStore _tokens;

  ApiClient(this._tokens)
      : _dio = Dio(BaseOptions(
          baseUrl: AppConfig.apiBaseUrl,
          connectTimeout: const Duration(seconds: 15),
          receiveTimeout: const Duration(seconds: 120), // AI analizi uzun sürebilir
          headers: {'Content-Type': 'application/json'},
        )) {
    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await _tokens.access;
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(options);
      },
      onError: (e, handler) async {
        if (e.response?.statusCode == 401 && !_isAuthPath(e.requestOptions.path)) {
          final refreshed = await _tryRefresh();
          if (refreshed) {
            final token = await _tokens.access;
            final req = e.requestOptions;
            req.headers['Authorization'] = 'Bearer $token';
            try {
              final clone = await _dio.fetch(req);
              return handler.resolve(clone);
            } catch (_) {}
          }
        }
        handler.next(e);
      },
    ));
  }

  bool _isAuthPath(String p) => p.contains('/auth/');

  Future<bool> _tryRefresh() async {
    final refresh = await _tokens.refresh;
    if (refresh == null) return false;
    try {
      final res = await Dio(BaseOptions(baseUrl: AppConfig.apiBaseUrl))
          .post('/auth/refresh', data: {'refresh_token': refresh});
      final data = res.data['data']?['tokens'];
      if (data != null) {
        await _tokens.save(data['access_token'], data['refresh_token']);
        return true;
      }
    } catch (_) {}
    return false;
  }

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) =>
      _run(() => _dio.get(path, queryParameters: query));

  Future<dynamic> post(String path, {Object? data}) =>
      _run(() => _dio.post(path, data: data));

  Future<dynamic> delete(String path) => _run(() => _dio.delete(path));

  Future<dynamic> _run(Future<Response> Function() fn) async {
    try {
      final res = await fn();
      final body = res.data;
      if (body is Map && body['success'] == true) {
        return body['data'];
      }
      return body;
    } on DioException catch (e) {
      final r = e.response?.data;
      if (r is Map) {
        throw ApiException(
          r['error']?.toString() ?? 'error',
          r['message']?.toString() ?? 'Bir hata oluştu.',
          status: e.response?.statusCode,
          extra: r.cast<String, dynamic>(),
        );
      }
      throw ApiException(
        'network_error',
        'Bağlantı hatası. İnternetinizi kontrol edin.',
        status: e.response?.statusCode,
      );
    }
  }
}
