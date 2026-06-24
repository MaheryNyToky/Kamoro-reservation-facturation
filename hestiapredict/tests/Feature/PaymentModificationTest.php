<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Reservation;
use App\Models\ReservationAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentModificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_overpayment_returns_change_and_updates_payment_payload(): void
    {
        [$reservation, $invoice] = $this->createReservationAndInvoice(110000);

        $response = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount_ariary' => 120000,
            'payment_method' => 'Espèces',
            'processed_by_name' => 'Reception Test',
            'processed_by_role' => 'receptionist',
        ]);

        $response->assertOk();
        $response->assertJsonPath('payment.amount_ariary', 110000);
        $response->assertJsonPath('payment.amount_received_ariary', 120000);
        $response->assertJsonPath('payment.change_given_ariary', 10000);
        $response->assertJsonPath('invoice.balance_amount_ariary', 0);

        $audit = ReservationAudit::query()
            ->where('reservation_id', $reservation->id)
            ->where('action', 'payment')
            ->firstOrFail();

        $this->assertSame(10000, (int) ($audit->details['change_given_ariary'] ?? 0));
    }

    public function test_receptionist_can_only_modify_one_payment_per_reservation(): void
    {
        [$reservation, $invoice] = $this->createReservationAndInvoice(110000);

        $paymentResponse = $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount_ariary' => 110000,
            'payment_method' => 'Espèces',
            'processed_by_name' => 'Reception Test',
            'processed_by_role' => 'receptionist',
        ]);
        $paymentResponse->assertOk();
        $paymentId = $paymentResponse->json('payment.id');

        $firstUpdate = $this->putJson("/api/invoices/{$invoice->id}/payments/{$paymentId}", [
            'amount_ariary' => 110000,
            'payment_method' => 'Mobile Money',
            'payment_operator' => 'mvola',
            'reference' => 'REF-1',
            'processed_by_name' => 'Reception Test',
            'processed_by_role' => 'receptionist',
        ]);

        $firstUpdate->assertOk();
        $firstUpdate->assertJsonPath('payment.payment_method', 'Mobile Money');
        $firstUpdate->assertJsonPath('invoice.payment_modification_count', 1);

        $secondUpdate = $this->putJson("/api/invoices/{$invoice->id}/payments/{$paymentId}", [
            'amount_ariary' => 110000,
            'payment_method' => 'Espèces',
            'reference' => 'REF-2',
            'processed_by_name' => 'Reception Test',
            'processed_by_role' => 'receptionist',
        ]);

        $secondUpdate->assertStatus(422);
        $secondUpdate->assertJsonValidationErrors('payment');
        $this->assertSame(
            1,
            ReservationAudit::query()
                ->where('reservation_id', $reservation->id)
                ->where('action', 'payment_modified')
                ->count(),
        );
    }

    private function createReservationAndInvoice(int $totalAmount): array
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-test-' . uniqid() . '@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Payment Test Client',
            'client_phone' => '0340000100',
            'customer_phone' => '0340000100',
            'customer_email' => 'payment-test@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-21',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'invoice_number' => 'FACT-' . uniqid(),
            'total_amount_ariary' => $totalAmount,
            'tax_amount_ariary' => 0,
            'discount_mode' => null,
            'discount_value' => null,
            'discount_amount_ariary' => 0,
            'deposit_amount_ariary' => 0,
            'pdf_path' => null,
            'finalized_at' => null,
            'status' => 'open',
            'document_type' => 'facture',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Séjour chambre',
            'type' => 'room',
            'amount_ariary' => $totalAmount,
            'quantity' => 1,
        ]);

        return [$reservation, $invoice];
    }
}
