<?php

namespace Tests\Feature;

use App\Http\Controllers\PMSController;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
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

    public function test_receptionist_can_generate_pdf_without_discount(): void
    {
        $user = User::create([
            'name' => 'Reception PDF Test',
            'email' => 'reception-pdf-test@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '208',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Reception PDF Client',
            'client_phone' => '0340000092',
            'customer_phone' => '0340000092',
            'customer_email' => 'reception-pdf@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-24',
            'check_out_date' => '2026-06-25',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);
        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 110000,
        ]);

        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'invoice_number' => null,
            'total_amount_ariary' => 110000,
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
            'amount_ariary' => 110000,
            'quantity' => 1,
        ]);

        $response = $this->postJson("/api/invoices/{$invoice->id}/generate-pdf", [
            'document_type' => 'facture',
            'actor_role' => 'receptionist',
        ]);

        $response->assertOk();
        $this->assertSame('facture', $response->json('invoice.document_type'));

        $refreshed = Invoice::query()->findOrFail($invoice->id);
        $this->assertNotNull($refreshed->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($refreshed->pdf_path));
    }

    public function test_receptionist_can_only_generate_proforma_before_checkin(): void
    {
        $user = User::create([
            'name' => 'Reception Precheckin PDF',
            'email' => 'reception-precheckin-pdf@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '210',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Precheckin Proforma Client',
            'client_phone' => '0340000094',
            'customer_phone' => '0340000094',
            'customer_email' => 'precheckin-proforma@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-28',
            'check_out_date' => '2026-06-29',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);
        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 110000,
        ]);

        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'invoice_number' => null,
            'total_amount_ariary' => 110000,
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
            'amount_ariary' => 110000,
            'quantity' => 1,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/generate-pdf", [
            'document_type' => 'facture',
            'actor_role' => 'receptionist',
        ])->assertForbidden();

        $response = $this->postJson("/api/invoices/{$invoice->id}/generate-pdf", [
            'document_type' => 'proforma',
            'actor_role' => 'receptionist',
        ]);

        $response->assertOk();
        $response->assertJsonPath('invoice.document_type', 'proforma');

        $refreshed = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame('proforma', $refreshed->document_type);
        $this->assertNotNull($refreshed->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($refreshed->pdf_path));
    }

    public function test_admin_can_generate_pdf_with_discount(): void
    {
        $user = User::create([
            'name' => 'Admin PDF Discount Test',
            'email' => 'admin-pdf-discount@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '209',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Admin PDF Client',
            'client_phone' => '0340000093',
            'customer_phone' => '0340000093',
            'customer_email' => 'admin-pdf@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-26',
            'check_out_date' => '2026-06-27',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);
        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 110000,
        ]);

        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'invoice_number' => null,
            'total_amount_ariary' => 110000,
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
            'amount_ariary' => 110000,
            'quantity' => 1,
        ]);

        $response = $this->postJson("/api/invoices/{$invoice->id}/generate-pdf", [
            'document_type' => 'facture',
            'discount_mode' => 'amount',
            'discount_value' => 10000,
            'actor_role' => 'admin',
        ]);

        $response->assertOk();
        $this->assertSame('facture', $response->json('invoice.document_type'));
        $this->assertSame(10000, (int) $response->json('invoice.discount_amount_ariary'));

        $refreshed = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame(10000, (int) $refreshed->discount_amount_ariary);
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
            'amount_ariary' => 35000,
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
            'total_amount_ariary' => 742500,
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
            'amount_ariary' => 35000,
            'quantity' => 1,
        ]);

        $response = $this->postJson("/api/invoices/{$invoice->id}/generate-pdf", [
            'document_type' => 'facture',
            'actor_role' => 'admin',
        ]);

        $response->assertOk();
        $response->assertJsonPath('invoice.total_amount_ariary', 742500);

        $refreshed = Invoice::with('items')->findOrFail($invoice->id);
        $this->assertSame(742500, (int) $refreshed->total_amount_ariary);
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

    public function test_invoice_pdf_footer_includes_signatures_and_legal_block(): void
    {
        $user = User::create([
            'name' => 'Admin Footer Test',
            'email' => 'admin-footer-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '802',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Footer Invoice Client',
            'client_phone' => '0340000091',
            'customer_phone' => '0340000091',
            'customer_email' => 'footer@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-22',
            'check_out_date' => '2026-06-23',
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

        $html = $this->invoiceHtmlForTest($invoice, 'facture', 'ariary');

        $this->assertStringContainsString('Arrêtée la présente facture à la somme de', $html);
        $this->assertStringContainsString('Fait à Ambondromamy le ', $html);
        $this->assertStringContainsString("class='legal-block'", $html);
        $this->assertStringContainsString("class='legal-line'", $html);
        $this->assertStringNotContainsString('<span class="legal-label">NIF:</span>', $html);
        $this->assertStringContainsString("<span class='legal-label'>NIF:</span> 2000683017", $html);
        $this->assertStringContainsString("<span class='legal-label'>STAT:</span>4601 11 2011 0 10939", $html);
        $this->assertStringContainsString("<span class='legal-label'>Siège social :</span> PK 2 Route de Mampikony, 403 AMBONDROMAMY", $html);
        $this->assertLessThan(
            strpos($html, 'Fait à Ambondromamy le '),
            strpos($html, 'Arrêtée la présente facture à la somme de'),
        );
        $this->assertLessThan(
            strpos($html, "class='signature-wrap'"),
            strpos($html, 'Arrêtée la présente facture à la somme de'),
        );
        $this->assertLessThan(
            strpos($html, "class='legal-block'"),
            strpos($html, 'Fait à Ambondromamy le '),
        );
        $this->assertStringContainsString("class='signature-title'>Client</div>", $html);
        $this->assertStringContainsString("class='signature-title'>Responsable</div>", $html);
        $this->assertStringNotContainsString("class='responsible-signature'", $html);
    }

    public function test_invoice_html_does_not_show_organization_billing_identifiers_when_missing(): void
    {
        $user = User::create([
            'name' => 'Admin No Billing Info Test',
            'email' => 'admin-no-billing-info-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '804',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'No Billing Info Client',
            'client_phone' => '0340000095',
            'customer_phone' => '0340000095',
            'customer_email' => 'nobilling@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-22',
            'check_out_date' => '2026-06-23',
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

        $html = $this->invoiceHtmlForTest($invoice, 'facture', 'ariary');

        $this->assertStringNotContainsString('<span class="legal-label">NIF:</span>', $html);
        $this->assertStringNotContainsString('<span class="legal-label">STAT:</span>', $html);
        $this->assertStringContainsString("<span class='legal-label'>NIF:</span> 2000683017", $html);
        $this->assertStringContainsString("<span class='legal-label'>STAT:</span>4601 11 2011 0 10939", $html);
    }

    public function test_proforma_pdf_header_and_signature_are_proforma_specific(): void
    {
        $user = User::create([
            'name' => 'Admin Proforma Test',
            'email' => 'admin-proforma-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '803',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Proforma Invoice Client',
            'client_phone' => '0340000092',
            'customer_phone' => '0340000092',
            'customer_email' => 'proforma@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-22',
            'check_out_date' => '2026-06-23',
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

        $html = $this->invoiceHtmlForTest($invoice, 'proforma', 'ariary');

        $this->assertStringContainsString('FACTURE PROFORMA', $html);
        $this->assertStringNotContainsString('DOCUMENT PROFORMA', $html);
        $this->assertStringNotContainsString("<div class='subtitle'>Facture proforma</div>", $html);
        $this->assertStringNotContainsString('Compte BMOI', $html);
        $this->assertStringNotContainsString('00004 00017 039579201 02 62', $html);
        $this->assertStringNotContainsString('Kamoro hotel', $html);
        $this->assertStringContainsString("class='signature-title'>Client</div>", $html);
        $this->assertStringContainsString("class='signature-title'>Responsable</div>", $html);
        $this->assertStringContainsString("class='responsible-signature'", $html);

        $pdf = Pdf::loadHTML($html);
        $pdf->render();
        $this->assertGreaterThan(0, $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    public function test_organization_proforma_pdf_includes_bank_details_and_signature(): void
    {
        $user = User::create([
            'name' => 'Admin Org Proforma Test',
            'email' => 'admin-org-proforma-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $organization = Organization::create([
            'name' => 'Organisme Test',
            'contact_name' => 'Contact Organisme',
            'contact_phone' => '0340000093',
            'contact_email' => 'org@example.com',
            'billing_address' => 'Antananarivo',
            'nif' => 'NIF-ORG-900',
            'stat' => 'STAT-ORG-900',
        ]);

        $room = Room::create([
            'room_number' => '804',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'client_name' => 'Proforma Organization Client',
            'client_phone' => '0340000093',
            'customer_phone' => '0340000093',
            'customer_email' => 'org-proforma@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'booking_type' => 'organization',
            'source' => 'Appel',
            'check_in_date' => '2026-06-22',
            'check_out_date' => '2026-06-23',
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

        $html = $this->invoiceHtmlForTest($invoice, 'proforma', 'ariary');

        $this->assertStringContainsString('FACTURE PROFORMA', $html);
        $this->assertStringContainsString('Compte BMOI', $html);
        $this->assertStringContainsString('00004 00017 039579201 02 62', $html);
        $this->assertStringContainsString('Kamoro hotel', $html);
        $this->assertStringContainsString('<span class="legal-label">NIF:</span> NIF-ORG-900', $html);
        $this->assertStringContainsString('<span class="legal-label">STAT:</span> STAT-ORG-900', $html);
        $this->assertStringContainsString("class='signature-title'>Client</div>", $html);
        $this->assertStringContainsString("class='signature-title'>Responsable</div>", $html);
        $this->assertStringContainsString("class='responsible-signature'", $html);
    }

    public function test_organization_invoice_hides_bank_details_when_balance_is_zero(): void
    {
        $user = User::create([
            'name' => 'Admin Org Paid Test',
            'email' => 'admin-org-paid-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $organization = Organization::create([
            'name' => 'Organisme Soldé',
        ]);

        $room = Room::create([
            'room_number' => '806',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'client_name' => 'Organisme Soldé',
            'client_phone' => '0340000096',
            'customer_phone' => '0340000096',
            'customer_email' => 'org-paid@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'booking_type' => 'organization',
            'source' => 'Appel',
            'check_in_date' => '2026-06-22',
            'check_out_date' => '2026-06-23',
            'status' => 'arrive',
            'payment_status' => 'paid',
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
            'status' => 'paid',
            'document_type' => 'facture',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Séjour chambre',
            'type' => 'room',
            'amount_ariary' => 50000,
            'quantity' => 1,
        ]);

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount_ariary' => 50000,
            'amount_received_ariary' => 50000,
            'change_given_ariary' => 0,
            'payment_method' => 'Espèces',
            'payment_context' => 'payment',
            'processed_by_name' => 'Réception Test',
            'processed_by_role' => 'receptionist',
        ]);

        $html = $this->invoiceHtmlForTest($invoice, 'facture', 'ariary');

        $this->assertStringContainsString('Facture réglée intégralement', $html);
        $this->assertStringNotContainsString('Compte BMOI', $html);
        $this->assertStringNotContainsString('00004 00017 039579201 02 62', $html);
    }

    public function test_organization_room_invoice_description_includes_first_name_when_present(): void
    {
        $user = User::create([
            'name' => 'Admin Room Name Test',
            'email' => 'admin-room-name-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '805',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Organisme Libellé',
            'client_phone' => '0340000094',
            'customer_phone' => '0340000094',
            'customer_email' => 'libelle@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'booking_type' => 'organization',
            'source' => 'Appel',
            'check_in_date' => '2026-06-22',
            'check_out_date' => '2026-06-24',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 50000,
            'occupant_name' => 'Rakoto',
            'occupant_first_name' => 'Jean',
        ]);
        $reservation->refresh()->load('rooms');
        $bookingRoomId = $reservation->rooms->first()->pivot->id;

        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'invoice_number' => null,
            'total_amount_ariary' => 100000,
            'tax_amount_ariary' => 0,
            'discount_mode' => null,
            'discount_value' => null,
            'discount_amount_ariary' => 0,
            'deposit_amount_ariary' => 0,
            'pdf_path' => null,
            'finalized_at' => null,
            'status' => 'open',
            'document_type' => 'facture',
            'booking_room_id' => $bookingRoomId,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'booking_room_id' => $bookingRoomId,
            'description' => 'Séjour chambre',
            'type' => 'room',
            'amount_ariary' => 100000,
            'quantity' => 2,
        ]);

        $html = $this->invoiceHtmlForTest($invoice, 'facture', 'ariary');

        $this->assertStringContainsString('Chambre 805', $html);
        $this->assertStringContainsString('Rakoto Jean', $html);
        $this->assertStringContainsString('2 nuits', $html);
    }

    public function test_organization_invoice_counts_room_lines_by_nights_even_if_item_quantity_is_stale(): void
    {
        $organization = Organization::create([
            'name' => 'Organisme 7 nuits',
        ]);

        $user = User::create([
            'name' => 'Admin Org Nights Test',
            'email' => 'admin-org-nights-test@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '807',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'client_name' => 'Organisme 7 nuits',
            'client_phone' => '0340000097',
            'customer_phone' => '0340000097',
            'customer_email' => 'organisme7@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'booking_type' => 'organization',
            'source' => 'Appel',
            'check_in_date' => '2026-04-21',
            'check_out_date' => '2026-04-28',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 110000,
            'occupant_name' => 'RAZAFIARISON',
            'occupant_first_name' => 'ANGELOT',
        ]);

        $reservation->refresh()->load('rooms', 'organization', 'invoice.items', 'invoice.payments');
        $bookingRoomId = $reservation->rooms->first()->pivot->id;

        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'organization_id' => $organization->id,
            'invoice_number' => null,
            'total_amount_ariary' => 110000,
            'tax_amount_ariary' => 0,
            'discount_mode' => null,
            'discount_value' => null,
            'discount_amount_ariary' => 0,
            'deposit_amount_ariary' => 0,
            'pdf_path' => null,
            'finalized_at' => null,
            'status' => 'open',
            'document_type' => 'facture',
            'billing_mode' => 'grouped',
            'invoice_kind' => 'master',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'booking_room_id' => $bookingRoomId,
            'description' => 'Séjour chambre',
            'type' => 'room',
            'amount_ariary' => 110000,
            'quantity' => 1,
        ]);

        $reservations = collect([$reservation->fresh(['rooms', 'organization', 'invoice.items', 'invoice.payments'])]);
        [$totalAmount, $paidAmount] = $this->organizationInvoiceTotalsForTest($reservations);

        $this->assertSame(770000, $totalAmount);
        $this->assertSame(0, $paidAmount);

        $html = $this->organizationInvoiceHtmlForTest($organization, $reservations, 'facture', $invoice->fresh(['items', 'payments', 'reservation.rooms', 'reservation.organization']));

        $this->assertStringContainsString('7 nuits', $html);
        $this->assertStringContainsString('770 000 Ar', $html);
    }

    private function invoiceHtmlForTest(Invoice $invoice, string $documentType, string $currencyMode): string
    {
        $controller = app(PMSController::class);
        $method = new \ReflectionMethod($controller, 'invoiceHtml');
        $method->setAccessible(true);

        return $method->invoke($controller, $invoice->fresh(['items', 'payments', 'reservation.guest', 'reservation.organization', 'reservation.rooms']), $documentType, $currencyMode);
    }

    private function organizationInvoiceHtmlForTest(Organization $organization, Collection $reservations, string $documentType, ?Invoice $invoice = null): string
    {
        $controller = app(PMSController::class);
        $method = new \ReflectionMethod($controller, 'organizationInvoiceHtml');
        $method->setAccessible(true);

        return $method->invoke($controller, $organization, $reservations, $documentType, $invoice);
    }

    private function organizationInvoiceTotalsForTest(Collection $reservations): array
    {
        $controller = app(PMSController::class);
        $method = new \ReflectionMethod($controller, 'organizationInvoiceTotals');
        $method->setAccessible(true);

        return $method->invoke($controller, $reservations);
    }
}
