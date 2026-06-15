import 'dart:io';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart';
import 'package:image_picker/image_picker.dart';

import '../core/app_config.dart';
import '../models/client_profile.dart';
import '../models/reservation.dart';
import '../services/client_search_service.dart';
import '../widgets/client_autocomplete_field.dart';

class CheckInPage extends StatefulWidget {
  final Reservation reservation;

  const CheckInPage({super.key, required this.reservation});

  @override
  State<CheckInPage> createState() => _CheckInPageState();
}

class _CheckInPageState extends State<CheckInPage> {
  final _formKey = GlobalKey<FormState>();
  final ImagePicker _picker = ImagePicker();
  final ClientSearchService _clientSearchService = ClientSearchService();

  final _firstNameController = TextEditingController();
  final _lastNameController = TextEditingController();
  final _contactController = TextEditingController();
  final _idNumberController = TextEditingController();

  DateTime? _dateOfBirth;
  String _idType = 'CIN';
  File? _idPhoto;
  bool _isLoading = false;
  ClientProfile? _selectedClient;

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

    ClientProfile? match;
    for (final client in results) {
      final fullName = client.displayName.toLowerCase();
      final phone = (client.phoneNumber ?? '').toLowerCase();
      if (fullName == widget.reservation.clientName.toLowerCase() ||
          phone == widget.reservation.phone.toLowerCase()) {
        match = client;
        break;
      }
    }

    match ??= results.first;
    _applyClient(match);
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
    });
  }

  (String, String) _splitName(String rawName) {
    final normalized = rawName.trim().replaceAll(RegExp(r'\s+'), ' ');
    if (normalized.isEmpty) return ('', '');
    final parts = normalized.split(' ');
    if (parts.length == 1) return (parts.first, '');
    return (parts.first, parts.sublist(1).join(' '));
  }

  Future<void> _pickImage(ImageSource source) async {
    final XFile? pickedFile = await _picker.pickImage(source: source);
    if (pickedFile != null) {
      setState(() {
        _idPhoto = File(pickedFile.path);
      });
    }
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

  String get _fullName {
    final first = _firstNameController.text.trim();
    final last = _lastNameController.text.trim();
    return [first, last].where((part) => part.isNotEmpty).join(' ').trim();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_dateOfBirth == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Veuillez sélectionner la date de naissance'),
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
      request.fields['id_type'] = _idType;
      request.fields['id_number'] = _idNumberController.text.trim();
      request.fields['id_document_number'] = _idNumberController.text.trim();

      if (_idPhoto != null) {
        request.files.add(
          await http.MultipartFile.fromPath(
            'id_photo',
            _idPhoto!.path,
            contentType: MediaType('image', 'jpeg'),
          ),
        );
      }

      final streamedResponse = await request.send();
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
              ),
              const SizedBox(height: 16),
              ClientAutocompleteField(
                controller: _idNumberController,
                labelText: 'Pièce d\'identité',
                prefixIcon: Icons.badge,
                keyboardType: TextInputType.text,
                textInputAction: TextInputAction.next,
                validator: (val) => val == null || val.trim().isEmpty
                    ? 'Le numéro est requis'
                    : null,
                valueBuilder: (client) => client.displayDocumentNumber,
                onSelected: _applyClient,
              ),
              const SizedBox(height: 16),
              _loyaltyBanner(),
              const SizedBox(height: 16),
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
                onChanged: (val) => setState(() => _idType = val!),
              ),
              const SizedBox(height: 24),
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
              Row(
                children: [
                  Expanded(
                    child: ElevatedButton.icon(
                      onPressed: () => _pickImage(ImageSource.camera),
                      icon: const Icon(Icons.camera_alt),
                      label: const Text('Photo'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => _pickImage(ImageSource.gallery),
                      icon: const Icon(Icons.upload_file),
                      label: const Text('Importer'),
                    ),
                  ),
                ],
              ),
              if (_idPhoto != null) ...[
                const SizedBox(height: 8),
                Image.file(_idPhoto!, height: 200, fit: BoxFit.cover),
              ],
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
