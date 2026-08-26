import 'package:flutter_test/flutter_test.dart';
import 'package:hestia_app/screens/payment_summary_page.dart';

void main() {
  group('filtre du récapitulatif par date de départ', () {
    final selectedDay = DateTime(2026, 8, 23);

    test('inclut tous les séjours qui finissent le jour sélectionné', () {
      expect(
        isCheckoutWithinSummaryRange(
          DateTime(2026, 8, 23),
          selectedDay,
          selectedDay,
        ),
        isTrue,
      );
    });

    test('exclut un séjour qui finit le lendemain', () {
      expect(
        isCheckoutWithinSummaryRange(
          DateTime(2026, 8, 24),
          selectedDay,
          selectedDay,
        ),
        isFalse,
      );
    });

    test('exclut un séjour qui finit la veille', () {
      expect(
        isCheckoutWithinSummaryRange(
          DateTime(2026, 8, 22),
          selectedDay,
          selectedDay,
        ),
        isFalse,
      );
    });

    test('accepte les dates de départ comprises dans une période', () {
      expect(
        isCheckoutWithinSummaryRange(
          DateTime(2026, 8, 24),
          DateTime(2026, 8, 23),
          DateTime(2026, 8, 25),
        ),
        isTrue,
      );
    });
  });
}
