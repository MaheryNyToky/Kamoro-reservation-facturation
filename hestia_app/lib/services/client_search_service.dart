import 'dart:convert';

import '../models/client_profile.dart';
import 'api_client.dart';

class ClientSearchService {
  ClientSearchService({ApiClient? apiClient})
    : _apiClient = apiClient ?? const ApiClient();

  final ApiClient _apiClient;

  Future<List<ClientProfile>> search(String query) async {
    final term = query.trim();
    if (term.length < 2) return const [];

    try {
      final response = await _apiClient.get('/api/clients/search', {'q': term});
      if (response.statusCode != 200 || response.body.isEmpty) {
        return const [];
      }

      final decoded = json.decode(response.body);
      final rawList = decoded is Map<String, dynamic>
          ? (decoded['data'] as List<dynamic>? ?? const [])
          : (decoded as List<dynamic>? ?? const []);

      final results = <ClientProfile>[];
      final seenKeys = <String>{};

      for (final item in rawList.whereType<Map>()) {
        final client = ClientProfile.fromJson(Map<String, dynamic>.from(item));
        final keys = client.dedupKeys;
        if (keys.isEmpty || keys.any(seenKeys.contains)) {
          continue;
        }
        seenKeys.addAll(keys);
        results.add(client);
      }

      return results;
    } catch (_) {
      return const [];
    }
  }
}
