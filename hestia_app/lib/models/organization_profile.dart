class OrganizationProfile {
  const OrganizationProfile({
    required this.id,
    required this.name,
    this.phone,
    this.contactName,
    this.contactPhone,
    this.contactEmail,
    this.email,
    this.billingAddress,
    this.nif,
    this.stat,
  });

  final int id;
  final String name;
  final String? phone;
  final String? contactName;
  final String? contactPhone;
  final String? contactEmail;
  final String? email;
  final String? billingAddress;
  final String? nif;
  final String? stat;

  String get displayLabel {
    final parts = <String>[name];
    final docs = <String>[
      if ((nif ?? '').trim().isNotEmpty) 'NIF: ${nif!.trim()}',
      if ((stat ?? '').trim().isNotEmpty) 'STAT: ${stat!.trim()}',
    ];

    if (docs.isNotEmpty) {
      parts.add(docs.join(' • '));
    }

    return parts.join(' - ');
  }

  factory OrganizationProfile.fromJson(Map<String, dynamic> json) {
    return OrganizationProfile(
      id: _asInt(json['id']),
      name: (json['name'] ?? '').toString(),
      phone: json['phone']?.toString(),
      contactName: json['contact_name']?.toString(),
      contactPhone: json['contact_phone']?.toString(),
      contactEmail: json['contact_email']?.toString(),
      email: json['email']?.toString(),
      billingAddress: json['billing_address']?.toString(),
      nif: json['nif']?.toString() ?? json['tax_id']?.toString(),
      stat: json['stat']?.toString(),
    );
  }

  static int _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? 0;
  }
}
