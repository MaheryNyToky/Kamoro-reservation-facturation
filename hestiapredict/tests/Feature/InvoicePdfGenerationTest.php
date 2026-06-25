<?php

namespace Tests\Feature;

use App\Http\Controllers\PMSController;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoicePdfGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_pdf_persists_pdf_and_document_type(): void
    {
        $user = User::create([
            'name' => 'Admin Test',
            'email' => 'admin-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '201',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Pdf Client',
            'client_phone' => '0340000004',
            'customer_phone' => '0340000004',
            'customer_email' => 'pdf@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'direct',
            'check_in_date' => '2026-06-16',
            'check_out_date' => '2026-06-17',
            'status' => 'arrive',
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
            'pdf_path' => null,
            'finalized_at' => null,
            'status' => 'open',
            'document_type' => 'facture',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Séjour chambre',
            'type' => 'room',
            'amount_ariary' => 50000,
            'quantity' => 1,
        ]);

        $response = $this->postJson("/api/invoices/{$invoice->id}/generate-pdf", [
            'pricing_mode' => 'fixed',
            'document_type' => 'proforma',
            'actor_role' => 'admin',
        ]);

        $response->assertOk();
        $response->assertJsonPath('invoice.document_type', 'proforma');

        $refreshed = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame('proforma', $refreshed->document_type);
        $this->assertNotNull($refreshed->invoice_number);
        $this->assertNotNull($refreshed->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($refreshed->pdf_path));
    }

    public function test_euro_pdf_mode_is_rejected_for_non_booking_reservation(): void
    {
        $user = User::create([
            'name' => 'Admin Euro Test',
            'email' => 'admin-euro-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Euro Refused Client',
            'client_phone' => '0340000051',
            'customer_phone' => '0340000051',
            'customer_email' => 'euro-refused@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-16',
            'check_out_date' => '2026-06-17',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
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
            'pdf_path' => null,
            'finalized_at' => null,
            'status' => 'open',
            'document_type' => 'facture',
        ]);

        $response = $this->postJson("/api/invoices/{$invoice->id}/generate-pdf", [
            'document_type' => 'facture',
            'currency_mode' => 'euro',
            'actor_role' => 'admin',
        ]);

        $response->assertStatus(422);
    }

    public function test_euro_pdf_mode_is_allowed_for_booking_reservation(): void
    {
        $user = User::create([
            'name' => 'Admin Booking Euro Test',
            'email' => 'admin-booking-euro-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '701',
            'type' => 'Chambre Double',
            'model' => 'Supérieure',
            'base_price_ariary' => 125000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Booking Euro Client',
            'client_phone' => '0340000052',
            'customer_phone' => '0340000052',
            'customer_email' => 'booking-euro@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Booking',
            'check_in_date' => '2026-06-16',
            'check_out_date' => '2026-06-17',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 1,
            'extra_mattresses' => 1,
        ]);
        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 162500,
        ]);

        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'invoice_number' => null,
            'total_amount_ariary' => 242500,
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
            'description' => 'Chambre 701 (Chambre Double) - 1 nuit(s)',
            'type' => 'room',
            'amount_ariary' => 162500,
            'quantity' => 1,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Lit supplémentaire',
            'type' => 'extra',
            'amount_ariary' => 50000,
            'quantity' => 1,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Matelas supplémentaire',
            'type' => 'extra',
            'amount_ariary' => 30000,
            'quantity' => 1,
        ]);

        $response = $this->postJson("/api/invoices/{$invoice->id}/generate-pdf", [
            'document_type' => 'facture',
            'currency_mode' => 'euro',
            'actor_role' => 'admin',
        ]);

        $response->assertOk();
        $refreshed = Invoice::query()->findOrFail($invoice->id);
        $this->assertNotNull($refreshed->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($refreshed->pdf_path));
    }

    public function test_extras_are_billed_per_night_in_generated_pdf(): void
    {
        $user = User::create([
            'name' => 'Admin Nightly Extras Test',
            'email' => 'admin-nightly-extras-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '702',
            'type' => 'Chambre Double',
            'model' => 'Supérieure',
            'base_price_ariary' => 125000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Nightly Extras Client',
            'client_phone' => '0340000053',
            'customer_phone' => '0340000053',
            'customer_email' => 'nightly-extras@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Booking',
            'check_in_date' => '2026-06-16',
            'check_out_date' => '2026-06-19',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 1,
            'extra_mattresses' => 1,
        ]);
        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 162500,
        ]);

        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'invoice_number' => null,
            'total_amount_ariary' => 727500,
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
            'description' => 'Chambre 702 (Chambre Double) - 3 nuit(s)',
            'type' => 'room',
            'amount_ariary' => 162500,
            'quantity' => 3,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Lit supplémentaire',
            'type' => 'extra',
            'amount_ariary' => 50000,
            'quantity' => 1,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Matelas supplémentaire',
            'type' => 'extra',
            'amount_ariary' => 30000,
            'quantity' => 1,
        ]);

        $response = $this->postJson("/api/invoices/{$invoice->id}/generate-pdf", [
            'document_type' => 'facture',
            'actor_role' => 'admin',
        ]);

        $response->assertOk();
        $response->assertJsonPath('invoice.total_amount_ariary', 727500);

        $refreshed = Invoice::with('items')->findOrFail($invoice->id);
        $this->assertSame(727500, (int) $refreshed->total_amount_ariary);
        $this->assertSame(3, (int) $refreshed->items->firstWhere('description', 'Lit supplémentaire')?->quantity);
        $this->assertSame(3, (int) $refreshed->items->firstWhere('description', 'Matelas supplémentaire')?->quantity);
        $this->assertNotNull($refreshed->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($refreshed->pdf_path));
    }

    public function test_standard_invoice_pdf_stays_on_one_page(): void
    {
        $user = User::create([
            'name' => 'Admin Compact Test',
            'email' => 'admin-compact-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '801',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Compact Invoice Client',
            'client_phone' => '0340000090',
            'customer_phone' => '0340000090',
            'customer_email' => 'compact@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-21',
            'status' => 'arrive',
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
            'total_amount_ariary' => 70000,
            'tax_amount_ariary' => 0,
            'discount_mode' => null,
            'discount_value' => null,
            'discount_amount_ariary' => 0,
            'deposit_amount_ariary' => 20000,
            'pdf_path' => null,
            'finalized_at' => null,
            'status' => 'open',
            'document_type' => 'facture',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Séjour chambre',
            'type' => 'room',
            'amount_ariary' => 50000,
            'quantity' => 1,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Petit-déjeuner',
            'type' => 'extra',
            'amount_ariary' => 20000,
            'quantity' => 1,
        ]);

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount_ariary' => 20000,
            'amount_received_ariary' => 20000,
            'change_given_ariary' => 0,
            'payment_method' => 'Espèces',
            'payment_context' => 'deposit',
            'reference' => 'ACPT-001',
            'processed_by_name' => 'Réception Test',
            'processed_by_role' => 'receptionist',
        ]);

        $html = $this->invoiceHtmlForTest($invoice, 'facture', 'ariary');
        $pdf = Pdf::loadHTML($html);
        $pdf->render();

        $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    private function invoiceHtmlForTest(Invoice $invoice, string $documentType, string $currencyMode): string
    {
        $controller = app(PMSController::class);
        $method = new \ReflectionMethod($controller, 'invoiceHtml');
        $method->setAccessible(true);

        return $method->invoke($controller, $invoice->fresh(['items', 'payments', 'reservation.guest', 'reservation.rooms']), $documentType, $currencyMode);
    }
}
