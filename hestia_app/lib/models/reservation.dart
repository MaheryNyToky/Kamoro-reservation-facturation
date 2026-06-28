class Reservation {
  const Reservation({
    required this.id,
    required this.clientName,
    required this.phone,
    required this.email,
    required this.checkIn,
    required this.checkOut,
    required this.roomIds,
    this.roomDetails = const [],
    this.bookingType = 'individual',
    this.billingMode = 'grouped',
    this.organization,
    this.extraBeds = 0,
    this.extraMattresses = 0,
  });

  final int id;
  final String clientName;
  final String phone;
  final String email;
  final DateTime checkIn;
  final DateTime checkOut;
  final List<int> roomIds;
  final List<Map<String, dynamic>> roomDetails;
  final String bookingType;
  final String billingMode;
  final Map<String, dynamic>? organization;
  final int extraBeds;
  final int extraMattresses;

  factory Reservation.fromJson(Map<String, dynamic> json) {
    return Reservation(
      id: _asInt(json['id']),
      clientName: (json['client_name'] ?? '').toString(),
      phone: json['phone'] == 'N/A' ? '' : (json['phone'] ?? '').toString(),
      email: json['email'] == 'N/A' ? '' : (json['email'] ?? '').toString(),
      checkIn: _parseDate(json['check_in']) ?? DateTime.now(),
      checkOut:
          _parseDate(json['check_out']) ??
          DateTime.now().add(const Duration(days: 1)),
      roomIds: _parseRoomIds(json['room_ids']),
      roomDetails: _parseRoomDetails(json['room_details']),
      bookingType: (json['booking_type'] ?? 'individual').toString(),
      billingMode: (json['billing_mode'] ?? 'grouped').toString(),
      organization: json['organization'] is Map<String, dynamic>
          ? Map<String, dynamic>.from(json['organization'] as Map)
          : null,
      extraBeds: _asInt(json['extra_beds'] ?? 0),
      extraMattresses: _asInt(json['extra_mattresses'] ?? 0),
    );
  }

  bool get isEditable {
    final today = DateTime.now();
    final todayOnly = DateTime(today.year, today.month, today.day);
    final checkOutOnly = DateTime(checkOut.year, checkOut.month, checkOut.day);
    return checkOutOnly.isAfter(todayOnly) ||
        checkOutOnly.isAtSameMomentAs(todayOnly);
  }

  Map<String, dynamic> toUpdateJson({
    required String clientName,
    required String phone,
    required String email,
    required DateTime checkIn,
    required DateTime checkOut,
    required List<int> roomIds,
    int extraBeds = 0,
    int extraMattresses = 0,
    List<Map<String, dynamic>> roomSegments = const [],
    String? modifiedByName,
    String? modifiedByRole,
  }) {
    final payload = {
      'client_name': clientName,
      'customer_phone': phone,
      'customer_email': email,
      'check_in': checkIn.toIso8601String().substring(0, 10),
      'check_out': checkOut.toIso8601String().substring(0, 10),
      'room_ids': roomIds,
      'extra_beds': extraBeds,
      'extra_mattresses': extraMattresses,
    };

    if (roomSegments.isNotEmpty) {
      payload['room_segments'] = roomSegments;
    }

    if (modifiedByName != null && modifiedByName.trim().isNotEmpty) {
      payload['modified_by_name'] = modifiedByName.trim();
    }
    if (modifiedByRole != null && modifiedByRole.trim().isNotEmpty) {
      payload['modified_by_role'] = modifiedByRole.trim();
    }

    return payload;
  }

  static int _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? 0;
  }

  static DateTime? _parseDate(dynamic value) {
    final text = value?.toString().trim();
    if (text == null || text.isEmpty || text == 'null') return null;
    return DateTime.tryParse(text);
  }

  static List<int> _parseRoomIds(dynamic value) {
    if (value is Iterable) {
      return value.map(_asInt).where((id) => id > 0).toList();
    }

    if (value is String) {
      return value
          .split(',')
          .map((item) => _asInt(item.trim()))
          .where((id) => id > 0)
          .toList();
    }

    return const [];
  }

  static List<Map<String, dynamic>> _parseRoomDetails(dynamic value) {
    if (value is Iterable) {
      return value.whereType<Map>().map((room) {
        final parsed = Map<String, dynamic>.from(room);
        final segmentStart = _parseDate(parsed['segment_start_date']);
        final segmentEnd = _parseDate(parsed['segment_end_date']);
        if (segmentStart != null) {
          parsed['segment_start_date'] = segmentStart
              .toIso8601String()
              .split('T')
              .first;
        }
        if (segmentEnd != null) {
          parsed['segment_end_date'] = segmentEnd
              .toIso8601String()
              .split('T')
              .first;
        }
        return parsed;
      }).toList();
    }

    return const [];
  }
}
