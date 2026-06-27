import 'dart:convert';

import '../models/organization_profile.dart';
import 'api_client.dart';

class OrganizationSearchService {
  OrganizationSearchService({ApiClient? apiClient})
    : _apiClient = apiClient ?? const ApiClient();

  final ApiClient _apiClient;

  Future<List<OrganizationProfile>> search(String query) async {
    final term = query.trim();
    if (term.length < 2) return const [];

    try {
      final response = await _apiClient.get('/api/organizations/search', {
        'q': term,
      });
      if (response.statusCode != 200 || response.body.isEmpty) {
        return const [];
      }

      final decoded = json.decode(response.body);
      final rawList = decoded is Map<String, dynamic>
          ? (decoded['data'] as List<dynamic>? ?? const [])
          : (decoded as List<dynamic>? ?? const []);

      final results = <OrganizationProfile>[];
      final seen = <int>{};
      for (final item in rawList.whereType<Map>()) {
        final organization = OrganizationProfile.fromJson(
          Map<String, dynamic>.from(item),
        );
        if (seen.contains(organization.id)) continue;
        seen.add(organization.id);
        results.add(organization);
      }
      return results;
    } catch (_) {
      return const [];
    }
  }
}
