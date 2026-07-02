<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreCheckinDepositTest extends TestCase
{
    use RefreshDatabase;

    public function test_receptionist_can_record_deposit_before_checkin_and_tracks_validator_role(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-test@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '101',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Paul Razaf',
            'client_phone' => '0340000003',
            'customer_phone' => '0340000003',
            'customer_email' => 'paul@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-21',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 50000,
        ]);

        $paymentResponse = $this->postJson("/api/reservations/{$reservation->id}/deposit", [
            'amount_ariary' => 20000,
            'payment_method' => 'Espèces',
            'processed_by_name' => 'Réception Test',
            'processed_by_role' => 'receptionist',
            'reference' => 'ACPT-001',
        ]);

        $paymentResponse->assertOk();
        $paymentResponse->assertJsonPath('payment.amount_ariary', 20000);
        $paymentResponse->assertJsonPath('payment.payment_method', 'Espèces');
        $paymentResponse->assertJsonPath('payment.payment_context', 'deposit');
        $paymentResponse->assertJsonPath('payment.processed_by_name', 'Réception Test');
        $paymentResponse->assertJsonPath('payment.processed_by_role', 'receptionist');
        $paymentResponse->assertJsonPath('invoice.status', 'partial');
        $paymentResponse->assertJsonPath('invoice.id', fn ($id) => !empty($id));
        $paymentResponse->assertJsonPath('invoice.deposit_amount_ariary', 20000);
        $paymentResponse->assertJsonPath('invoice.balance_amount_ariary', 30000);
    }

    public function test_receptionist_can_record_deposit_before_checkin(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-forbidden-deposit@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '102',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Deposit Refused',
            'client_phone' => '0340000005',
            'customer_phone' => '0340000005',
            'customer_email' => 'deposit-refused@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-21',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 50000,
        ]);

        $paymentResponse = $this->postJson("/api/reservations/{$reservation->id}/deposit", [
            'amount_ariary' => 20000,
            'payment_method' => 'Espèces',
            'processed_by_name' => 'Réception Test',
            'processed_by_role' => 'receptionist',
            'reference' => 'ACPT-REFUSED',
        ]);

        $paymentResponse->assertOk();
        $paymentResponse->assertJsonPath('payment.amount_ariary', 20000);
        $paymentResponse->assertJsonPath('payment.payment_context', 'deposit');
        $paymentResponse->assertJsonPath('payment.processed_by_role', 'receptionist');
        $paymentResponse->assertJsonPath('invoice.status', 'partial');
    }

    public function test_receptionist_cannot_record_invoice_payment_before_checkin(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-forbidden-payment@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '103',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Payment Refused',
            'client_phone' => '0340000006',
            'customer_phone' => '0340000006',
            'customer_email' => 'payment-refused@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-21',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 50000,
        ]);

        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'invoice_number' => null,
            'total_amount_ariary' => 50000,
            'tax_amount_ariary' => 0,
            'discount_mode' => null,
            'discount_value' => null,
            'discount_amount_ariary' => 0,
            'deposit_amount_ariary' => 0,
            'paid_amount_ariary' => 0,
            'balance_amount_ariary' => 50000,
            'pdf_path' => null,
            'finalized_at' => null,
            'status' => 'open',
            'document_type' => 'proforma',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Séjour chambre',
            'type' => 'room',
            'amount_ariary' => 50000,
            'quantity' => 1,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount_ariary' => 20000,
            'payment_method' => 'Espèces',
            'processed_by_name' => 'Réception Test',
            'processed_by_role' => 'receptionist',
            'reference' => 'PAY-REFUSED',
        ])->assertStatus(422)->assertJsonValidationErrors('payment');
    }
}
