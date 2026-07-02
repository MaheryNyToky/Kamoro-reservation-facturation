import 'dart:convert';

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

  final _nameController = TextEditingController();
  final _firstNameController = TextEditingController();
  final _lastNameController = TextEditingController();
  final _contactController = TextEditingController();
  final _idNumberController = TextEditingController();
  final _organizationContactNameController = TextEditingController();
  final _organizationContactPhoneController = TextEditingController();
  final _organizationContactEmailController = TextEditingController();
  final _organizationEmailController = TextEditingController();
  final _organizationBillingAddressController = TextEditingController();
  final _organizationNifController = TextEditingController();
  final _organizationStatController = TextEditingController();
  final List<_RoomOccupantDraft> _roomOccupants = [];

  DateTime? _dateOfBirth;
  DateTime? _passportValidFrom;
  DateTime? _passportValidUntil;
  String _sex = 'Homme';
  String _idType = 'CIN';
  bool _isLoading = false;
  ClientProfile? _selectedClient;

  final List<String> _sexes = ['Homme', 'Femme', 'Autre'];
  final List<String> _idTypes = [
    'CIN',
    'Passeport',
    'Carte de séjour',
    'Autre',
    'Permis',
  ];

  @override
  void initState() {
    super.initState();
    _nameController.text = widget.reservation.clientName;
    final split = _splitName(widget.reservation.clientName);
    _firstNameController.text = split.$1;
    _lastNameController.text = split.$2;
    _contactController.text = widget.reservation.phone;
    _hydrateOrganizationFields();
    _initRoomOccupants();
    _hydrateRoomOccupantsFallback();
    _hydrateClient();
  }

  @override
  void dispose() {
    for (final draft in _roomOccupants) {
      draft.dispose();
    }
    _nameController.dispose();
    _firstNameController.dispose();
    _lastNameController.dispose();
    _contactController.dispose();
    _idNumberController.dispose();
    _organizationContactNameController.dispose();
    _organizationContactPhoneController.dispose();
    _organizationContactEmailController.dispose();
    _organizationEmailController.dispose();
    _organizationBillingAddressController.dispose();
    _organizationNifController.dispose();
    _organizationStatController.dispose();
    super.dispose();
  }

  bool get _isOrganizationReservation =>
      widget.reservation.bookingType.trim().toLowerCase() == 'organization';

  bool get _needsRoomOccupants => _isOrganizationReservation;

  void _hydrateOrganizationFields() {
    final organization = widget.reservation.organization;
    if (organization == null) return;

    _nameControllerSafeAssign(organization['name']?.toString().trim() ?? '');
    _organizationContactNameController.text =
        organization['contact_name']?.toString().trim() ?? '';
    _organizationContactPhoneController.text =
        organization['phone']?.toString().trim() ?? '';
    _organizationContactEmailController.text =
        organization['contact_email']?.toString().trim() ?? '';
    _organizationEmailController.text =
        organization['email']?.toString().trim() ?? '';
    _organizationBillingAddressController.text =
        organization['billing_address']?.toString().trim() ?? '';
    final nif = organization['nif'] ?? organization['tax_id'];
    _organizationNifController.text = nif?.toString().trim() ?? '';
    _organizationStatController.text =
        organization['stat']?.toString().trim() ?? '';
  }

  void _nameControllerSafeAssign(String value) {
    if (value.isEmpty) return;
    _nameController.text = value;
  }

  void _initRoomOccupants() {
    _roomOccupants.clear();

    if (!_isOrganizationReservation) return;

    final roomDetails = widget.reservation.roomDetails.isNotEmpty
        ? widget.reservation.roomDetails
        : widget.reservation.roomIds
              .map(
                (roomId) => <String, dynamic>{
                  'room_id': roomId,
                  'room_number': roomId.toString(),
                  'type': '',
                  'model': '',
                },
              )
              .toList();

    for (var index = 0; index < roomDetails.length; index++) {
      final room = roomDetails[index];
      final roomLabel = _roomLabel(room);
      final occupant = _RoomOccupantDraft(
        roomId: _asInt(room['room_id'] ?? room['id']),
        roomLabel: roomLabel,
      );

      final defaultName = _isOrganizationReservation
          ? widget.reservation.clientName
          : (index == 0 ? _fullName : '');
      occupant.nameController.text = (room['occupant_name'] ?? defaultName)
          .toString()
          .trim();
      occupant.firstNameController.text =
          (room['occupant_first_name'] ?? '').toString().trim();
      occupant.idType = (room['occupant_id_type'] ?? 'CIN').toString();
      occupant.idNumberController.text =
          (room['occupant_id_number'] ?? _idNumberController.text)
              .toString()
              .trim();
      occupant.passportValidFrom = _parseIsoDate(
        room['occupant_passport_valid_from'],
      );
      occupant.passportValidUntil = _parseIsoDate(
        room['occupant_passport_valid_until'],
      );
      occupant.sex = (room['occupant_sex'] ?? _sex).toString();
      occupant.dateOfBirth =
          _parseIsoDate(room['occupant_date_of_birth']) ?? _dateOfBirth;

      _roomOccupants.add(occupant);
    }
  }

  Future<void> _hydrateRoomOccupantsFallback() async {
    if (!_isOrganizationReservation || _roomOccupants.isNotEmpty) return;

    try {
      final response = await http.get(
        Uri.parse(
          '${AppConfig.apiBaseUrl}/api/reservations/${widget.reservation.id}/folio',
        ),
        headers: {'Accept': 'application/json'},
      );

      if (!mounted || response.statusCode != 200) return;

      final decoded = json.decode(response.body);
      if (decoded is! Map) return;

      final roomBookings = (decoded['room_bookings'] as List<dynamic>? ?? [])
          .whereType<Map>()
          .map((room) => Map<String, dynamic>.from(room))
          .toList();

      if (roomBookings.isEmpty) return;

      final drafts = roomBookings
          .map(
            (room) =>
                _RoomOccupantDraft(
                    roomId: _asInt(room['room_id'] ?? room['id']),
                    roomLabel: _roomLabel(room),
                  )
                  ..nameController.text =
                      (room['occupant_name'] ?? widget.reservation.clientName)
                          .toString()
                          .trim()
                  ..firstNameController.text =
                      (room['occupant_first_name'] ?? '').toString().trim()
                  ..idType = (room['occupant_id_type'] ?? 'CIN').toString()
                  ..idNumberController.text = (room['occupant_id_number'] ?? '')
                      .toString()
                      .trim()
                  ..passportValidFrom = _parseIsoDate(
                    room['occupant_passport_valid_from'],
                  )
                  ..passportValidUntil = _parseIsoDate(
                    room['occupant_passport_valid_until'],
                  )
                  ..sex = (room['occupant_sex'] ?? _sex).toString()
                  ..dateOfBirth =
                      _parseIsoDate(room['occupant_date_of_birth']) ??
                      _dateOfBirth,
          )
          .toList();

      if (!mounted) return;
      setState(() {
        _roomOccupants
          ..clear()
          ..addAll(drafts);
      });
    } catch (_) {
      // On garde le comportement local si l'API de secours échoue.
    }
  }

  String _roomLabel(Map<String, dynamic> room) {
    final roomNumber = (room['room_number'] ?? room['number'] ?? room['id'])
        .toString()
        .trim();
    final type = (room['type'] ?? room['model'] ?? '').toString().trim();
    final segmentLabel = _segmentLabel(room);
    final baseLabel = roomNumber.isEmpty
        ? type
        : (type.isEmpty ? roomNumber : '$roomNumber - $type');
    if (segmentLabel.isEmpty) return baseLabel;
    if (baseLabel.isEmpty) return segmentLabel;
    return '$baseLabel • $segmentLabel';
  }

  String _segmentLabel(Map<String, dynamic> room) {
    final start = _parseIsoDate(room['segment_start_date']);
    final end = _parseIsoDate(room['segment_end_date']);
    final reservationStart = widget.reservation.checkIn;
    final reservationEnd = widget.reservation.checkOut;

    if (start == null && end == null) return '';
    final startText = _dateLabel(start ?? reservationStart);
    final endText = _dateLabel(end ?? reservationEnd);
    if (startText.isEmpty || endText.isEmpty) return '';
    if (startText == _dateLabel(reservationStart) &&
        endText == _dateLabel(reservationEnd)) {
      return '';
    }
    return '$startText -> $endText';
  }

  String _dateLabel(DateTime value) {
    final day = value.day.toString().padLeft(2, '0');
    final month = value.month.toString().padLeft(2, '0');
    final year = value.year.toString();
    return '$day/$month/$year';
  }

  DateTime? _parseIsoDate(dynamic value) {
    final text = value?.toString().trim();
    if (text == null || text.isEmpty || text == 'null') return null;
    return DateTime.tryParse(text);
  }

  int _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? 0;
  }

  List<Map<String, dynamic>> _roomCheckinsPayload() {
    return _roomOccupants.map((draft) {
      return {
        'room_id': draft.roomId,
        'occupant_name': draft.nameController.text.trim(),
        'occupant_first_name': draft.firstNameController.text.trim(),
        'occupant_date_of_birth': draft.dateOfBirth
            ?.toIso8601String()
            .split('T')
            .first,
        'occupant_sex': draft.sex,
        'occupant_id_type': draft.idType,
        'occupant_id_number': draft.idNumberController.text.trim(),
        'occupant_passport_valid_from': draft.passportValidFrom
            ?.toIso8601String()
            .split('T')
            .first,
        'occupant_passport_valid_until': draft.passportValidUntil
            ?.toIso8601String()
            .split('T')
            .first,
      };
    }).toList();
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

  bool get _requiresDocumentValidity =>
      !_isOrganizationReservation &&
      (_idType == 'Passeport' ||
          _idType == 'Carte de séjour' ||
          _idType == 'Autre');

  bool _requiresDocumentValidityForType(String type) {
    return type == 'Passeport' || type == 'Carte de séjour' || type == 'Autre';
  }

  String get _documentValidityLabel => _idType == 'Carte de séjour'
      ? 'carte de séjour'
      : (_idType == 'Autre' ? 'autre document' : 'passeport');

  String _documentValidityLabelForType(String type) {
    return type == 'Carte de séjour'
        ? 'carte de séjour'
        : (type == 'Autre' ? 'autre document' : 'passeport');
  }

  Future<void> _prefillMissingClientData() async {
    if (_isOrganizationReservation) return;

    final needsClientLookup =
        _dateOfBirth == null ||
        _idNumberController.text.trim().isEmpty ||
        (_requiresDocumentValidity &&
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
    if (!mounted) return;

    if (!_formKey.currentState!.validate()) return;
    if (!_isOrganizationReservation && _dateOfBirth == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Veuillez sélectionner la date de naissance ou choisir un client existant',
          ),
        ),
      );
      return;
    }

    if (_requiresDocumentValidity &&
        (_passportValidFrom == null || _passportValidUntil == null)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Veuillez sélectionner la validité du document'),
        ),
      );
      return;
    }

    if (_requiresDocumentValidity &&
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

    if (_isOrganizationReservation && _roomOccupants.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Veuillez renseigner les occupants des chambres'),
        ),
      );
      return;
    }

    final mainOccupant = _isOrganizationReservation && _roomOccupants.isNotEmpty
        ? _roomOccupants.first
        : null;
    final fullName = _isOrganizationReservation
        ? (mainOccupant?.nameController.text.trim() ??
              widget.reservation.clientName.trim())
        : _fullName;
    if (fullName.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Veuillez renseigner le nom de l’occupant principal'),
        ),
      );
      return;
    }

    final dateOfBirth = _isOrganizationReservation
        ? mainOccupant?.dateOfBirth
        : _dateOfBirth;
    if (_isOrganizationReservation && dateOfBirth == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Veuillez renseigner la date de naissance de l’occupant principal',
          ),
        ),
      );
      return;
    }

    final idNumber = _isOrganizationReservation
        ? mainOccupant?.idNumberController.text.trim() ?? ''
        : _idNumberController.text.trim();
    if (_isOrganizationReservation && idNumber.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Veuillez renseigner la pièce de l’occupant principal'),
        ),
      );
      return;
    }

    final contactPhone = _isOrganizationReservation
        ? _contactController.text.trim()
        : _contactController.text.trim();
    final contactEmail = _isOrganizationReservation
        ? _organizationContactEmailController.text.trim()
        : '';
    final sex = _isOrganizationReservation ? mainOccupant?.sex ?? _sex : _sex;
    final idType = _isOrganizationReservation
        ? mainOccupant?.idType ?? _idType
        : _idType;

    if (_isOrganizationReservation) {
      for (final draft in _roomOccupants) {
        if (_requiresDocumentValidityForType(draft.idType) &&
            (draft.passportValidFrom == null ||
                draft.passportValidUntil == null)) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                'Veuillez sélectionner la validité du document pour ${draft.roomLabel}',
              ),
            ),
          );
          return;
        }
        if (_requiresDocumentValidityForType(draft.idType) &&
            draft.passportValidFrom != null &&
            draft.passportValidUntil != null &&
            draft.passportValidFrom!.isAfter(draft.passportValidUntil!)) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                'La date de début doit être antérieure à la date de fin pour ${draft.roomLabel}',
              ),
            ),
          );
          return;
        }
      }
    }

    setState(() => _isLoading = true);

    try {
      final uri = Uri.parse(
        '${AppConfig.apiBaseUrl}/api/reservations/${widget.reservation.id}/checkin',
      );

      final request = http.MultipartRequest('POST', uri);
      request.headers['Accept'] = 'application/json';

      request.fields['full_name'] = fullName;
      request.fields['first_name'] = _isOrganizationReservation
          ? fullName
          : _firstNameController.text.trim();
      request.fields['last_name'] = _isOrganizationReservation
          ? ''
          : _lastNameController.text.trim();
      request.fields['customer_phone'] = contactPhone;
      request.fields['phone_number'] = contactPhone;
      if (_isOrganizationReservation) {
        request.fields['organization_name'] = _nameController.text.trim();
        request.fields['organization_phone'] =
            _organizationContactPhoneController.text.trim();
        request.fields['organization_contact_name'] =
            _organizationContactNameController.text.trim();
        request.fields['organization_contact_phone'] =
            _organizationContactPhoneController.text.trim();
        request.fields['organization_contact_email'] =
            _organizationContactEmailController.text.trim();
        request.fields['organization_email'] = _organizationEmailController.text
            .trim();
        request.fields['organization_billing_address'] =
            _organizationBillingAddressController.text.trim();
        request.fields['organization_nif'] = _organizationNifController.text
            .trim();
        request.fields['organization_stat'] = _organizationStatController.text
            .trim();
      }
      if (_isOrganizationReservation && contactEmail.isNotEmpty) {
        request.fields['customer_email'] = contactEmail;
      }
      if (_selectedClient != null) {
        request.fields['loyalty_count'] = _selectedClient!.loyaltyCount
            .toString();
      }
      request.fields['date_of_birth'] = dateOfBirth!.toIso8601String().split(
        'T',
      )[0];
      request.fields['sex'] = sex;
      if (_requiresDocumentValidity &&
          _passportValidFrom != null &&
          _passportValidUntil != null) {
        request.fields['passport_valid_from'] = _passportValidFrom!
            .toIso8601String()
            .split('T')[0];
        request.fields['passport_valid_until'] = _passportValidUntil!
            .toIso8601String()
            .split('T')[0];
      }
      request.fields['id_type'] = idType;
      request.fields['id_number'] = idNumber;
      request.fields['id_document_number'] = idNumber;
      request.fields['checked_in_by_name'] = widget.userName;
      request.fields['checked_in_by_role'] = widget.role;
      if (_needsRoomOccupants) {
        request.fields['room_checkins'] = jsonEncode(_roomCheckinsPayload());
      }

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

  Widget _buildRoomOccupantCard(_RoomOccupantDraft draft, int index) {
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Chambre ${index + 1} - ${draft.roomLabel}',
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: draft.nameController,
              decoration: const InputDecoration(
                labelText: 'Nom de l’occupant',
                prefixIcon: Icon(Icons.person_outline),
              ),
              keyboardType: TextInputType.name,
              textInputAction: TextInputAction.next,
              validator: (value) {
                if (!_needsRoomOccupants) return null;
                return value == null || value.trim().isEmpty
                    ? 'L’occupant de cette chambre est requis'
                    : null;
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: draft.firstNameController,
              decoration: const InputDecoration(
                labelText: 'Prénom de l’occupant',
                prefixIcon: Icon(Icons.badge_outlined),
              ),
              keyboardType: TextInputType.name,
              textInputAction: TextInputAction.next,
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: draft.sex,
              decoration: const InputDecoration(
                labelText: 'Sexe',
                border: OutlineInputBorder(),
              ),
              items: _sexes
                  .map((sex) => DropdownMenuItem(value: sex, child: Text(sex)))
                  .toList(),
              onChanged: (value) {
                if (value != null) {
                  setState(() => draft.sex = value);
                }
              },
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: draft.idType,
              decoration: const InputDecoration(
                labelText: 'Type de pièce d\'identité',
                border: OutlineInputBorder(),
              ),
              items: _idTypes
                  .map(
                    (type) => DropdownMenuItem(value: type, child: Text(type)),
                  )
                  .toList(),
              onChanged: (value) {
                if (value != null) {
                  setState(() => draft.idType = value);
                }
              },
            ),
            const SizedBox(height: 12),
            if (_requiresDocumentValidityForType(draft.idType)) ...[
              ListTile(
                title: Text(
                  draft.passportValidFrom == null
                      ? 'Sélectionner la date de début de la ${_documentValidityLabelForType(draft.idType)}'
                      : 'Document valable du : ${draft.passportValidFrom!.toIso8601String().split('T')[0]}',
                ),
                trailing: const Icon(Icons.badge_outlined),
                shape: RoundedRectangleBorder(
                  side: const BorderSide(color: Colors.grey),
                  borderRadius: BorderRadius.circular(4),
                ),
                onTap: () async {
                  final initial =
                      draft.passportValidFrom ??
                      draft.passportValidUntil ??
                      DateTime.now();
                  final picked = await showDatePicker(
                    context: context,
                    initialDate: initial,
                    firstDate: DateTime(1900),
                    lastDate: DateTime(2050),
                  );
                  if (picked == null) return;
                  setState(() {
                    draft.passportValidFrom = picked;
                    if (draft.passportValidUntil != null &&
                        draft.passportValidUntil!.isBefore(picked)) {
                      draft.passportValidUntil = picked;
                    }
                  });
                },
              ),
              const SizedBox(height: 12),
              ListTile(
                title: Text(
                  draft.passportValidUntil == null
                      ? 'Sélectionner la date de fin de la ${_documentValidityLabelForType(draft.idType)}'
                      : 'Document valable au : ${draft.passportValidUntil!.toIso8601String().split('T')[0]}',
                ),
                trailing: const Icon(Icons.event_available_outlined),
                shape: RoundedRectangleBorder(
                  side: const BorderSide(color: Colors.grey),
                  borderRadius: BorderRadius.circular(4),
                ),
                onTap: () async {
                  final initial =
                      draft.passportValidUntil ??
                      draft.passportValidFrom ??
                      DateTime.now().add(const Duration(days: 365));
                  final firstDate = draft.passportValidFrom ?? DateTime.now();
                  final picked = await showDatePicker(
                    context: context,
                    initialDate: initial,
                    firstDate: firstDate,
                    lastDate: DateTime(2050),
                  );
                  if (picked == null) return;
                  setState(() => draft.passportValidUntil = picked);
                },
              ),
              const SizedBox(height: 12),
            ],
            TextFormField(
              controller: draft.idNumberController,
              decoration: const InputDecoration(
                labelText: 'Numéro de pièce',
                prefixIcon: Icon(Icons.badge_outlined),
              ),
              validator: (value) {
                if (!_needsRoomOccupants) return null;
                return value == null || value.trim().isEmpty
                    ? 'Le numéro de pièce est requis'
                    : null;
              },
            ),
            const SizedBox(height: 12),
            ListTile(
              title: Text(
                draft.dateOfBirth == null
                    ? 'Sélectionner la date de naissance'
                    : 'Date de naissance : ${draft.dateOfBirth!.toIso8601String().split('T')[0]}',
              ),
              trailing: const Icon(Icons.calendar_month),
              onTap: () async {
                final picked = await showDatePicker(
                  context: context,
                  initialDate: draft.dateOfBirth ?? DateTime(2000),
                  firstDate: DateTime(1900),
                  lastDate: DateTime.now(),
                );
                if (picked != null) {
                  setState(() => draft.dateOfBirth = picked);
                }
              },
            ),
          ],
        ),
      ),
    );
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
              if (_isOrganizationReservation) ...[
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF8FAFC),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFE2E8F0)),
                  ),
                  child: const Text(
                    'Informations de l’organisme à compléter avant le check-in.',
                    style: TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _nameController,
                  decoration: const InputDecoration(
                    labelText: 'Nom de l’organisme',
                    prefixIcon: Icon(Icons.apartment_outlined),
                    border: OutlineInputBorder(),
                  ),
                  keyboardType: TextInputType.name,
                  textInputAction: TextInputAction.next,
                  validator: (val) => val == null || val.trim().isEmpty
                      ? 'Le nom de l’organisme est requis'
                      : null,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _organizationContactNameController,
                  decoration: const InputDecoration(
                    labelText: 'Contact organisme',
                    prefixIcon: Icon(Icons.badge_outlined),
                    border: OutlineInputBorder(),
                  ),
                  textInputAction: TextInputAction.next,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _organizationContactPhoneController,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'Téléphone du siège',
                    prefixIcon: Icon(Icons.business_outlined),
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _organizationContactEmailController,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(
                    labelText: 'Email contact',
                    prefixIcon: Icon(Icons.contact_mail_outlined),
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _organizationEmailController,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(
                    labelText: 'Email de l’organisme',
                    prefixIcon: Icon(Icons.email_outlined),
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _organizationBillingAddressController,
                  decoration: const InputDecoration(
                    labelText: 'Adresse de facturation',
                    prefixIcon: Icon(Icons.location_on_outlined),
                    border: OutlineInputBorder(),
                  ),
                  keyboardType: TextInputType.streetAddress,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _organizationNifController,
                  decoration: const InputDecoration(
                    labelText: 'NIF',
                    prefixIcon: Icon(Icons.receipt_long_outlined),
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _organizationStatController,
                  decoration: const InputDecoration(
                    labelText: 'STAT',
                    prefixIcon: Icon(Icons.account_balance_outlined),
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 16),
              ],
              if (!_isOrganizationReservation) ...[
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
                      if (!_requiresDocumentValidity) {
                        _passportValidFrom = null;
                        _passportValidUntil = null;
                      } else if (_selectedClient?.passportValidFrom != null ||
                          _selectedClient?.passportValidUntil != null) {
                        _passportValidFrom = _selectedClient!.passportValidFrom;
                        _passportValidUntil =
                            _selectedClient!.passportValidUntil;
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
                if (_requiresDocumentValidity) ...[
                  ListTile(
                    title: Text(
                      _passportValidFrom == null
                          ? 'Sélectionner la date de début de la $_documentValidityLabel'
                          : 'Document valable du : ${_passportValidFrom!.toIso8601String().split('T')[0]}',
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
                          ? 'Sélectionner la date de fin de la $_documentValidityLabel'
                          : 'Document valable au : ${_passportValidUntil!.toIso8601String().split('T')[0]}',
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
              ] else ...[
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF8FAFC),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFE2E8F0)),
                  ),
                  child: const Text(
                    'Renseigne les occupants dans chaque chambre. L’identité principale sera prise sur la première chambre.',
                    style: TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
                const SizedBox(height: 16),
              ],
              if (_needsRoomOccupants) ...[
                const SizedBox(height: 8),
                Text(
                  'Occupants par chambre',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 12),
                ..._roomOccupants.asMap().entries.map(
                  (entry) => _buildRoomOccupantCard(entry.value, entry.key),
                ),
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

class _RoomOccupantDraft {
  _RoomOccupantDraft({required this.roomId, required this.roomLabel});

  final int roomId;
  final String roomLabel;
  final TextEditingController nameController = TextEditingController();
  final TextEditingController firstNameController = TextEditingController();
  final TextEditingController idNumberController = TextEditingController();
  DateTime? dateOfBirth;
  DateTime? passportValidFrom;
  DateTime? passportValidUntil;
  String sex = 'Homme';
  String idType = 'CIN';

  void dispose() {
    nameController.dispose();
    firstNameController.dispose();
    idNumberController.dispose();
  }
}
