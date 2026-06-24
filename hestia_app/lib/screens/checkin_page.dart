import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../core/app_config.dart';
import '../models/client_profile.dart';
import '../models/reservation.dart';
import '../services/client_search_service.dart';
import '../widgets/client_autocomplete_field.dart';

class CheckInPage extends StatefulWidget {
  final Reservation reservation;
  final String userName;
  final String role;

  const CheckInPage({
    super.key,
    required this.reservation,
    required this.userName,
    this.role = 'receptionist',
  });

  @override
  State<CheckInPage> createState() => _CheckInPageState();
}

class _CheckInPageState extends State<CheckInPage> {
  final _formKey = GlobalKey<FormState>();
  final ClientSearchService _clientSearchService = ClientSearchService();

  final _firstNameController = TextEditingController();
  final _lastNameController = TextEditingController();
  final _contactController = TextEditingController();
  final _idNumberController = TextEditingController();

  DateTime? _dateOfBirth;
  DateTime? _passportValidFrom;
  DateTime? _passportValidUntil;
  String _sex = 'Homme';
  String _idType = 'CIN';
  bool _isLoading = false;
  ClientProfile? _selectedClient;

  final List<String> _sexes = ['Homme', 'Femme', 'Autre'];
  final List<String> _idTypes = ['CIN', 'Passeport', 'Permis'];

  @override
  void initState() {
    super.initState();
    final split = _splitName(widget.reservation.clientName);
    _firstNameController.text = split.$1;
    _lastNameController.text = split.$2;
    _contactController.text = widget.reservation.phone;
    _hydrateClient();
  }

  @override
  void dispose() {
    _firstNameController.dispose();
    _lastNameController.dispose();
    _contactController.dispose();
    _idNumberController.dispose();
    super.dispose();
  }

  Future<void> _hydrateClient() async {
    final query = widget.reservation.phone.isNotEmpty
        ? widget.reservation.phone
        : widget.reservation.clientName;
    if (query.trim().length < 2) return;

    final results = await _clientSearchService.search(query);
    if (!mounted || results.isEmpty) return;

    final match = _findExactClientMatch(
      results,
      name: widget.reservation.clientName,
      phone: widget.reservation.phone,
    );
    if (match == null) return;

    _applyClient(match);
  }

  Future<void> _prefillMissingClientData() async {
    final needsClientLookup =
        _dateOfBirth == null ||
        _idNumberController.text.trim().isEmpty ||
        (_idType == 'Passeport' &&
            (_passportValidFrom == null || _passportValidUntil == null));

    if (!needsClientLookup) return;

    final queries = <String>[
      _contactController.text.trim(),
      _idNumberController.text.trim(),
      _fullName,
      widget.reservation.phone.trim(),
      widget.reservation.clientName.trim(),
    ];

    final query = queries.firstWhere(
      (value) => value.length >= 2,
      orElse: () => '',
    );
    if (query.isEmpty) return;

    final results = await _clientSearchService.search(query);
    if (!mounted || results.isEmpty) return;

    final match = _findExactClientMatch(
      results,
      name: widget.reservation.clientName,
      phone: widget.reservation.phone,
      document: _idNumberController.text.trim(),
    );
    if (match == null) return;

    _applyClient(match);
  }

  ClientProfile? _findExactClientMatch(
    List<ClientProfile> clients, {
    String? name,
    String? phone,
    String? document,
  }) {
    final normalizedName = _normalizeLookupValue(name);
    final normalizedPhone = _normalizePhoneValue(phone);
    final normalizedDocument = _normalizeLookupValue(document);

    for (final client in clients) {
      final clientName = _normalizeLookupValue(client.displayName);
      final clientPhone = _normalizePhoneValue(client.phoneNumber);
      final clientDocument = _normalizeLookupValue(
        client.displayDocumentNumber,
      );

      final nameMatches =
          normalizedName.isNotEmpty && clientName == normalizedName;
      final phoneMatches =
          normalizedPhone.isNotEmpty && clientPhone == normalizedPhone;
      final documentMatches =
          normalizedDocument.isNotEmpty && clientDocument == normalizedDocument;

      if (nameMatches || phoneMatches || documentMatches) {
        return client;
      }
    }

    return null;
  }

  String _normalizeLookupValue(String? value) {
    return (value ?? '').trim().toLowerCase().replaceAll(RegExp(r'\s+'), ' ');
  }

  String _normalizePhoneValue(String? value) {
    return (value ?? '').replaceAll(RegExp(r'\D+'), '');
  }

  void _applyClient(ClientProfile client) {
    final nameParts = _splitName(client.displayName);
    setState(() {
      _selectedClient = client;
      _firstNameController.text = client.firstName?.trim().isNotEmpty == true
          ? client.firstName!.trim()
          : nameParts.$1;
      _lastNameController.text = client.lastName?.trim().isNotEmpty == true
          ? client.lastName!.trim()
          : nameParts.$2;
      _contactController.text = client.phoneNumber?.trim() ?? '';
      _idNumberController.text = client.displayDocumentNumber;
      _dateOfBirth = client.dateOfBirth ?? _dateOfBirth;
      _passportValidFrom = client.passportValidFrom ?? _passportValidFrom;
      _passportValidUntil = client.passportValidUntil ?? _passportValidUntil;
      if (client.sex?.trim().isNotEmpty == true) {
        _sex = client.sex!.trim();
      }
    });
  }

  (String, String) _splitName(String rawName) {
    final normalized = rawName.trim().replaceAll(RegExp(r'\s+'), ' ');
    if (normalized.isEmpty) return ('', '');
    final parts = normalized.split(' ');
    if (parts.length == 1) return (parts.first, '');
    return (parts.first, parts.sublist(1).join(' '));
  }

  Future<void> _selectDate() async {
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: DateTime(2000),
      firstDate: DateTime(1900),
      lastDate: DateTime.now(),
    );
    if (picked != null && picked != _dateOfBirth) {
      setState(() {
        _dateOfBirth = picked;
      });
    }
  }

  Future<void> _selectPassportValidity() async {
    final DateTime initial =
        _passportValidUntil ??
        _passportValidFrom ??
        DateTime.now().add(const Duration(days: 365));
    final DateTime firstDate = _passportValidFrom ?? DateTime.now();
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: firstDate,
      lastDate: DateTime(2050),
    );
    if (picked != null && picked != _passportValidUntil) {
      setState(() {
        _passportValidUntil = picked;
      });
    }
  }

  String get _fullName {
    final first = _firstNameController.text.trim();
    final last = _lastNameController.text.trim();
    return [first, last].where((part) => part.isNotEmpty).join(' ').trim();
  }

  Future<void> _submit() async {
    await _prefillMissingClientData();

    if (!_formKey.currentState!.validate()) return;
    if (_dateOfBirth == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Veuillez sélectionner la date de naissance ou choisir un client existant',
          ),
        ),
      );
      return;
    }

    if (_idType == 'Passeport' &&
        (_passportValidFrom == null || _passportValidUntil == null)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Veuillez sélectionner la validité du passeport'),
        ),
      );
      return;
    }

    if (_idType == 'Passeport' &&
        _passportValidFrom != null &&
        _passportValidUntil != null &&
        _passportValidFrom!.isAfter(_passportValidUntil!)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'La date de début doit être antérieure à la date de fin',
          ),
        ),
      );
      return;
    }

    final fullName = _fullName;
    if (fullName.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Veuillez renseigner le prénom et le nom'),
        ),
      );
      return;
    }

    setState(() => _isLoading = true);

    try {
      final uri = Uri.parse(
        '${AppConfig.apiBaseUrl}/api/reservations/${widget.reservation.id}/checkin',
      );

      final request = http.MultipartRequest('POST', uri);
      request.headers['Accept'] = 'application/json';

      request.fields['full_name'] = fullName;
      request.fields['first_name'] = _firstNameController.text.trim();
      request.fields['last_name'] = _lastNameController.text.trim();
      request.fields['customer_phone'] = _contactController.text.trim();
      request.fields['phone_number'] = _contactController.text.trim();
      if (_selectedClient != null) {
        request.fields['loyalty_count'] = _selectedClient!.loyaltyCount
            .toString();
      }
      request.fields['date_of_birth'] = _dateOfBirth!.toIso8601String().split(
        'T',
      )[0];
      request.fields['sex'] = _sex;
      if (_idType == 'Passeport') {
        request.fields['passport_valid_from'] = _passportValidFrom!
            .toIso8601String()
            .split('T')[0];
        request.fields['passport_valid_until'] = _passportValidUntil!
            .toIso8601String()
            .split('T')[0];
      }
      request.fields['id_type'] = _idType;
      request.fields['id_number'] = _idNumberController.text.trim();
      request.fields['id_document_number'] = _idNumberController.text.trim();
      request.fields['checked_in_by_name'] = widget.userName;
      request.fields['checked_in_by_role'] = widget.role;

      final streamedResponse = await request.send().timeout(
        const Duration(seconds: 8),
      );
      final response = await http.Response.fromStream(streamedResponse);

      if (response.statusCode == 200 || response.statusCode == 201) {
        if (!mounted) return;
        Navigator.of(context).pop(true);
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('Check-in réussi')));
      } else {
        throw Exception('Erreur ${response.statusCode}: ${response.body}');
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('Erreur: $e')));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Widget _loyaltyBanner() {
    final client = _selectedClient;
    if (client == null) return const SizedBox.shrink();

    final visits = client.loyaltyCount;

    return AnimatedContainer(
      duration: const Duration(milliseconds: 220),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFE6FFFB),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFF0F766E)),
      ),
      child: Row(
        children: [
          const Icon(Icons.workspace_premium, color: Color(0xFF0F766E)),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              'Client régulier : $visits visite${visits > 1 ? 's' : ''}',
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Check-in (Fiche de Police)')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'Réservation #${widget.reservation.id}',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 16),
              ClientAutocompleteField(
                controller: _firstNameController,
                labelText: 'Prénom',
                prefixIcon: Icons.person_outline,
                keyboardType: TextInputType.name,
                textInputAction: TextInputAction.next,
                validator: (val) => val == null || val.trim().isEmpty
                    ? 'Le prénom est requis'
                    : null,
                valueBuilder: (client) {
                  final split = _splitName(client.displayName);
                  return client.firstName?.trim().isNotEmpty == true
                      ? client.firstName!.trim()
                      : split.$1;
                },
                onSelected: _applyClient,
                showLoyalty: widget.role != 'receptionist',
              ),
              const SizedBox(height: 16),
              ClientAutocompleteField(
                controller: _lastNameController,
                labelText: 'Nom',
                prefixIcon: Icons.badge_outlined,
                keyboardType: TextInputType.name,
                textInputAction: TextInputAction.next,
                validator: (val) => val == null || val.trim().isEmpty
                    ? 'Le nom est requis'
                    : null,
                valueBuilder: (client) {
                  final split = _splitName(client.displayName);
                  return client.lastName?.trim().isNotEmpty == true
                      ? client.lastName!.trim()
                      : split.$2;
                },
                onSelected: _applyClient,
                showLoyalty: widget.role != 'receptionist',
              ),
              const SizedBox(height: 16),
              ClientAutocompleteField(
                controller: _contactController,
                labelText: 'Téléphone',
                prefixIcon: Icons.phone,
                keyboardType: TextInputType.phone,
                textInputAction: TextInputAction.next,
                valueBuilder: (client) => client.phoneNumber?.trim() ?? '',
                onSelected: _applyClient,
                showLoyalty: widget.role != 'receptionist',
              ),
              const SizedBox(height: 16),
              DropdownButtonFormField<String>(
                initialValue: _sex,
                decoration: const InputDecoration(
                  labelText: 'Sexe',
                  border: OutlineInputBorder(),
                ),
                items: _sexes
                    .map(
                      (sex) => DropdownMenuItem(value: sex, child: Text(sex)),
                    )
                    .toList(),
                validator: (val) => val == null || val.trim().isEmpty
                    ? 'Le sexe est requis'
                    : null,
                onChanged: (val) {
                  if (val != null) {
                    setState(() => _sex = val);
                  }
                },
              ),
              const SizedBox(height: 16),
              DropdownButtonFormField<String>(
                initialValue: _idType,
                decoration: const InputDecoration(
                  labelText: 'Type de Pièce d\'Identité',
                  border: OutlineInputBorder(),
                ),
                items: _idTypes
                    .map(
                      (type) =>
                          DropdownMenuItem(value: type, child: Text(type)),
                    )
                    .toList(),
                onChanged: (val) {
                  if (val == null) return;
                  setState(() {
                    _idType = val;
                    if (_idType != 'Passeport') {
                      _passportValidFrom = null;
                      _passportValidUntil = null;
                    } else if (_selectedClient?.passportValidFrom != null ||
                        _selectedClient?.passportValidUntil != null) {
                      _passportValidFrom = _selectedClient!.passportValidFrom;
                      _passportValidUntil = _selectedClient!.passportValidUntil;
                    }
                  });
                },
              ),
              const SizedBox(height: 24),
              ClientAutocompleteField(
                controller: _idNumberController,
                labelText: 'Numéro de pièce d\'identité',
                prefixIcon: Icons.badge,
                keyboardType: TextInputType.text,
                textInputAction: TextInputAction.next,
                validator: (val) => val == null || val.trim().isEmpty
                    ? 'Le numéro est requis'
                    : null,
                valueBuilder: (client) => client.displayDocumentNumber,
                onSelected: _applyClient,
                showLoyalty: widget.role != 'receptionist',
              ),
              const SizedBox(height: 16),
              if (widget.role != 'receptionist') ...[
                _loyaltyBanner(),
                const SizedBox(height: 16),
              ],
              ListTile(
                title: Text(
                  _dateOfBirth == null
                      ? 'Sélectionner la date de naissance'
                      : 'Date de naissance : ${_dateOfBirth!.toIso8601String().split('T')[0]}',
                ),
                trailing: const Icon(Icons.calendar_today),
                shape: RoundedRectangleBorder(
                  side: const BorderSide(color: Colors.grey),
                  borderRadius: BorderRadius.circular(4),
                ),
                onTap: _selectDate,
              ),
              const SizedBox(height: 16),
              if (_idType == 'Passeport') ...[
                ListTile(
                  title: Text(
                    _passportValidFrom == null
                        ? 'Sélectionner la date de début du passeport'
                        : 'Passeport valable du : ${_passportValidFrom!.toIso8601String().split('T')[0]}',
                  ),
                  trailing: const Icon(Icons.badge_outlined),
                  shape: RoundedRectangleBorder(
                    side: const BorderSide(color: Colors.grey),
                    borderRadius: BorderRadius.circular(4),
                  ),
                  onTap: () async {
                    final initial =
                        _passportValidFrom ??
                        _passportValidUntil ??
                        DateTime.now();
                    final picked = await showDatePicker(
                      context: context,
                      initialDate: initial,
                      firstDate: DateTime(1900),
                      lastDate: DateTime(2050),
                    );
                    if (picked == null) return;
                    setState(() {
                      _passportValidFrom = picked;
                      if (_passportValidUntil != null &&
                          _passportValidUntil!.isBefore(picked)) {
                        _passportValidUntil = picked;
                      }
                    });
                  },
                ),
                const SizedBox(height: 16),
                ListTile(
                  title: Text(
                    _passportValidUntil == null
                        ? 'Sélectionner la date de fin du passeport'
                        : 'Passeport valable au : ${_passportValidUntil!.toIso8601String().split('T')[0]}',
                  ),
                  trailing: const Icon(Icons.event_available_outlined),
                  shape: RoundedRectangleBorder(
                    side: const BorderSide(color: Colors.grey),
                    borderRadius: BorderRadius.circular(4),
                  ),
                  onTap: _selectPassportValidity,
                ),
                const SizedBox(height: 16),
              ],
              if (_dateOfBirth != null)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF8FAFC),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFE2E8F0)),
                  ),
                  child: Text(
                    'Date de naissance auto-remplie : ${_dateOfBirth!.toIso8601String().split('T')[0]}',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
              if (_dateOfBirth != null) const SizedBox(height: 12),
              const SizedBox(height: 32),
              ElevatedButton(
                onPressed: _isLoading ? null : _submit,
                style: ElevatedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 16),
                ),
                child: _isLoading
                    ? const CircularProgressIndicator()
                    : const Text('Valider le Check-in'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
