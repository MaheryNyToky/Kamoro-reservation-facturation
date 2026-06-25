import 'dart:convert';

import 'package:http/http.dart' as http;

import '../core/app_config.dart';

class ApiClient {
  const ApiClient();
  static const Duration _defaultTimeout = Duration(seconds: 8);

  Uri uri(String path, [Map<String, String>? queryParameters]) {
    return Uri.parse(
      '${AppConfig.apiBaseUrl}$path',
    ).replace(queryParameters: queryParameters);
  }

  Future<http.Response> get(
    String path, [
    Map<String, String>? queryParameters,
    Duration? timeout,
  ]) {
    return http
        .get(uri(path, queryParameters))
        .timeout(timeout ?? _defaultTimeout);
  }

  Future<http.Response> postJson(
    String path,
    Map<String, dynamic> body, [
    Duration? timeout,
  ]) {
    return http
        .post(
          uri(path),
          headers: {'Content-Type': 'application/json'},
          body: json.encode(body),
        )
        .timeout(timeout ?? _defaultTimeout);
  }

  Future<http.Response> putJson(String path, Map<String, dynamic> body) {
    return http
        .put(
          uri(path),
          headers: {'Content-Type': 'application/json'},
          body: json.encode(body),
        )
        .timeout(_defaultTimeout);
  }

  Future<http.Response> updateReservation(int id, Map<String, dynamic> body) {
    return putJson('/api/reservations/$id', body);
  }

  Future<http.Response> delete(String path) {
    return http.delete(uri(path)).timeout(_defaultTimeout);
  }
}
