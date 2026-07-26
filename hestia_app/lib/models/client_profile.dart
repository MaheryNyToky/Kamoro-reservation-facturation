class ClientProfile {
  const ClientProfile({
    required this.id,
    required this.fullName,
    required this.idNumber,
    required this.loyaltyCount,
    this.reservationId,
    this.firstName,
    this.lastName,
    this.phoneNumber,
    this.sex,
    this.dateOfBirth,
    this.passportValidFrom,
    this.passportValidUntil,
    this.idDocumentNumber,
    this.idType,
  });

  final int id;
  final int? reservationId;
  final String fullName;
  final String? firstName;
  final String? lastName;
  final String? phoneNumber;
  final String? sex;
  final DateTime? dateOfBirth;
  final DateTime? passportValidFrom;
  final DateTime? passportValidUntil;
  final String? idType;
  final String idNumber;
  final String? idDocumentNumber;
  final int loyaltyCount;

  String get displayName {
    final name = fullName.trim();
    if (name.isNotEmpty) return name;
    return [firstName, lastName]
        .whereType<String>()
        .map((part) => part.trim())
        .where((part) => part.isNotEmpty)
        .join(' ');
  }

  String get displayDocumentNumber {
    final document = idDocumentNumber?.trim();
    if (document != null && document.isNotEmpty) return document;
    return idNumber.trim();
  }

  String get searchLabel => '$displayName - ID: $displayDocumentNumber';

  List<String> get dedupKeys {
    final keys = <String>[];
    final document =
        _normalizeDocument(idDocumentNumber) ?? _normalizeDocument(idNumber);
    if (document != null) {
      keys.add('doc:$document');
    }

    final name =
        _normalize(fullName) ??
        [
          _normalize(firstName),
          _normalize(lastName),
        ].whereType<String>().join(' ').trim();
    final phone = _normalizePhone(phoneNumber);

    if (name.isNotEmpty && phone != null && phone.isNotEmpty) {
      keys.add('name-phone:$name|$phone');
    }

    if (keys.isEmpty && name.isNotEmpty) {
      keys.add('name:$name');
    }

    return keys;
  }

  factory ClientProfile.fromJson(Map<String, dynamic> json) {
    return ClientProfile(
      id: _asInt(json['id']),
      reservationId: json['reservation_id'] == null
          ? null
          : _asInt(json['reservation_id']),
      fullName: (json['full_name'] ?? '').toString(),
      firstName: json['first_name']?.toString(),
      lastName: json['last_name']?.toString(),
      phoneNumber: json['phone_number']?.toString(),
      sex: json['sex']?.toString(),
      dateOfBirth: DateTime.tryParse((json['date_of_birth'] ?? '').toString()),
      passportValidFrom: DateTime.tryParse(
        (json['passport_valid_from'] ?? '').toString(),
      ),
      passportValidUntil: DateTime.tryParse(
        (json['passport_valid_until'] ?? '').toString(),
      ),
      idType: json['id_type']?.toString(),
      idNumber: (json['id_number'] ?? '').toString(),
      idDocumentNumber: json['id_document_number']?.toString(),
      loyaltyCount: _asInt(json['loyalty_count']),
    );
  }

  static int _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? 0;
  }

  static String? _normalize(String? value) {
    final normalized = value?.trim().toLowerCase();
    if (normalized == null || normalized.isEmpty) {
      return null;
    }
    return normalized;
  }

  static String? _normalizeDocument(String? value) {
    final normalized = _normalize(value)?.replaceAll(RegExp(r'[^a-z0-9]+'), '');
    if (normalized == null || normalized.isEmpty) return null;
    return normalized;
  }

  static String? _normalizePhone(String? value) {
    final digits = value == null ? '' : value.replaceAll(RegExp(r'\D+'), '');
    if (digits.isEmpty) return null;
    if (digits.startsWith('261')) return '0${digits.substring(3)}';
    return digits;
  }
}
