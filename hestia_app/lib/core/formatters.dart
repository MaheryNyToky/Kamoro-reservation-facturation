import 'package:flutter/services.dart';

String formatPrice(dynamic price) {
  final value = parseAriaryAmount(price);
  final formatted = value.abs().toString().replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ' ',
  );
  return value < 0 ? '-$formatted' : formatted;
}

int parseAriaryAmount(dynamic value) {
  if (value is int) return value;
  if (value is num) return value.toInt();

  final raw = value?.toString().trim() ?? '';
  if (raw.isEmpty) return 0;

  final digitsOnly = raw.replaceAll(RegExp(r'[^0-9-]'), '');
  return int.tryParse(digitsOnly) ?? 0;
}

class AriaryInputFormatter extends TextInputFormatter {
  const AriaryInputFormatter();

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final raw = newValue.text.replaceAll(RegExp(r'[^0-9]'), '');
    if (raw.isEmpty) {
      return const TextEditingValue(
        text: '',
        selection: TextSelection.collapsed(offset: 0),
      );
    }

    final formatted = formatPrice(int.tryParse(raw) ?? 0);
    return TextEditingValue(
      text: formatted,
      selection: TextSelection.collapsed(offset: formatted.length),
    );
  }
}
