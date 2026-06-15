<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Reservation;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PMSController extends Controller
{
    private const TOURIST_TAX_PER_ROOM_NIGHT = 2000;
    private const EXTRA_BED_PRICE_ARIARY = 50000;
    private const EXTRA_MATTRESS_PRICE_ARIARY = 30000;

    public function checkIn(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'phone_number' => 'nullable|string|max:50',
            'date_of_birth' => 'required|date',
            'id_type' => 'required|in:CIN,Passeport,Permis',
            'id_number' => 'required|string|max:100',
            'id_document_number' => 'nullable|string|max:100',
            'loyalty_count' => 'nullable|integer|min:0',
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'id_photo' => 'nullable|image|max:5120',
        ]);

        $reservation = Reservation::with(['rooms', 'guest', 'invoice.items', 'invoice.payments'])->findOrFail($id);

        $photoPath = $reservation->guest?->id_photo_path;
        if ($request->hasFile('id_photo')) {
            if ($photoPath) {
                Storage::disk('local')->delete($photoPath);
            }
            $photoPath = $request->file('id_photo')->store('id_photos', 'local');
        }

        $result = DB::transaction(function () use ($reservation, $validated, $photoPath) {
            $reservation->update([
                'client_name' => $validated['full_name'],
                'customer_phone' => $validated['customer_phone'] ?? $reservation->customer_phone,
                'client_phone' => $validated['customer_phone'] ?? $reservation->client_phone,
                'status' => 'arrive',
            ]);

            $guest = Guest::updateOrCreate(
                ['reservation_id' => $reservation->id],
                [
                    'first_name' => $validated['first_name'] ?? null,
                    'last_name' => $validated['last_name'] ?? null,
                    'phone_number' => $validated['phone_number']
                        ?? $validated['customer_phone']
                        ?? $reservation->customer_phone
                        ?? $reservation->client_phone,
                    'id_document_number' => $validated['id_document_number'] ?? $validated['id_number'],
                    'loyalty_count' => (int) ($validated['loyalty_count'] ?? $reservation->guest?->loyalty_count ?? 0),
                    'full_name' => $validated['full_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'id_type' => $validated['id_type'],
                    'id_number' => $validated['id_number'],
                    'id_photo_path' => $photoPath,
                ],
            );

            return [
                'guest' => $guest,
                'reservation' => $reservation->refresh()->load('rooms', 'guest'),
            ];
        });

        return response()->json([
            'message' => 'Check-in réussi',
            ...$result,
        ]);
    }

    public function getFolio(int $id): JsonResponse
    {
        $reservation = Reservation::with(['rooms', 'guest', 'invoice.items', 'invoice.payments'])->findOrFail($id);
        $invoice = $reservation->invoice ?: $this->ensureOpenFolio($reservation);

        return response()->json($this->folioPayload($invoice));
    }

    public function addInvoiceItem(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'type' => 'required|in:room,extra,deposit',
            'amount_ariary' => 'required|integer|min:0',
            'quantity' => 'required|integer|min:1',
        ]);

        $invoice = Invoice::findOrFail($id);
        if ($invoice->status === 'finalized') {
            return response()->json(['message' => 'Facture finalisée, ajout impossible.'], 400);
        }

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => $validated['description'],
            'type' => $validated['type'],
            'amount_ariary' => $validated['amount_ariary'],
            'quantity' => $validated['quantity'],
        ]);

        $this->recalculateInvoice($invoice);

        return response()->json([
            'message' => 'Ligne ajoutée avec succès',
            'invoice' => $this->folioPayload($invoice->refresh()),
        ]);
    }

    public function addPayment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'amount_ariary' => 'required|integer|min:1',
            'payment_method' => 'required|string|in:Espèces,Carte Bancaire,Mobile Money,Chèque,Virement,Acompte',
            'reference' => 'nullable|string|max:120',
            'processed_by_name' => 'nullable|string|max:120',
        ]);

        $result = DB::transaction(function () use ($validated, $id) {
            $invoice = Invoice::with(['payments', 'reservation.guest'])->lockForUpdate()->findOrFail($id);

            if ($invoice->status === 'finalized') {
                throw ValidationException::withMessages([
                    'invoice' => 'Facture finalisée, paiement impossible.',
                ]);
            }

            $remainingAmount = max(0, (int) $invoice->balance_amount_ariary);
            if ($remainingAmount <= 0) {
                throw ValidationException::withMessages([
                    'amount_ariary' => 'Cette facture est déjà soldée.',
                ]);
            }

            if ((int) $validated['amount_ariary'] > $remainingAmount) {
                throw ValidationException::withMessages([
                    'amount_ariary' => 'Le paiement dépasse le reste à payer.',
                ]);
            }

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'amount_ariary' => $validated['amount_ariary'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'processed_by_name' => $validated['processed_by_name'] ?? null,
            ]);

            $guest = $invoice->reservation?->guest;
            if ($guest) {
                $guest->increment('loyalty_count');
            }

            $this->updatePaymentStatus($invoice->refresh());

            return [
                'payment' => $payment,
                'invoice' => $this->folioPayload($invoice->refresh()),
            ];
        });

        return response()->json([
            'message' => 'Paiement enregistré',
            ...$result,
        ]);
    }

    public function generatePdf(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'pricing_mode' => 'nullable|in:fixed,ai',
            'discount_mode' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
        ]);

        $invoice = Invoice::with(['items', 'payments', 'reservation.guest', 'reservation.rooms'])->findOrFail($id);

        DB::transaction(function () use ($invoice, $validated) {
            $invoice->refresh();
            if (!$invoice->invoice_number) {
                $invoice->invoice_number = $this->nextInvoiceNumber();
                $invoice->save();
            }

            $this->applyInvoiceDiscount($invoice, $validated['discount_mode'] ?? null, $validated['discount_value'] ?? null);
            $pdf = Pdf::loadHTML($this->invoiceHtml($invoice->refresh()->load(['items', 'payments', 'reservation.guest', 'reservation.rooms'])));
            $path = "invoices/{$invoice->invoice_number}.pdf";
            Storage::disk('local')->put($path, $pdf->output());

            $invoice->update([
                'pdf_path' => $path,
            ]);
        });

        return response()->json([
            'message' => 'Facture générée avec succès',
            'invoice' => $this->folioPayload($invoice->refresh()),
            'pdf_url' => url("/api/invoices/{$invoice->id}/pdf"),
        ]);
    }

    public function downloadPdf(int $id): StreamedResponse
    {
        $invoice = Invoice::findOrFail($id);
        abort_unless($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path), 404);

        return Storage::disk('local')->download(
            $invoice->pdf_path,
            ($invoice->invoice_number ?? 'facture') . '.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function sendInvoiceEmail(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:190',
        ]);

        $invoice = Invoice::with('reservation.guest')->findOrFail($id);
        abort_unless($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path), 404);

        Mail::raw(
            "Bonjour,\n\nVeuillez trouver ci-joint votre facture {$invoice->invoice_number}.\n\nCordialement,\nKamoro Hotel",
            function ($message) use ($validated, $invoice) {
                $message
                    ->to($validated['email'])
                    ->subject("Facture {$invoice->invoice_number}")
                    ->attachData(
                        Storage::disk('local')->get($invoice->pdf_path),
                        "{$invoice->invoice_number}.pdf",
                        ['mime' => 'application/pdf'],
                    );
            },
        );

        return response()->json(['message' => 'Facture envoyée par email']);
    }

    private function ensureOpenFolio(Reservation $reservation): Invoice
    {
        $invoice = Invoice::firstOrCreate(
            ['reservation_id' => $reservation->id],
            ['status' => 'open'],
        );

        if ($invoice->items()->where('type', 'room')->doesntExist()) {
            $this->seedRoomItems($invoice, $reservation);
        }

        if ($invoice->status !== 'finalized') {
            $this->syncReservationExtras($invoice, $reservation);
        }

        $this->recalculateInvoice($invoice);

        return $invoice->refresh()->load(['items', 'payments', 'reservation.guest']);
    }

    private function seedRoomItems(Invoice $invoice, Reservation $reservation): void
    {
        $checkIn = Carbon::parse($reservation->check_in_date);
        $checkOut = Carbon::parse($reservation->check_out_date);
        $nights = max(1, $checkIn->diffInDays($checkOut));

        foreach ($reservation->rooms as $room) {
            $pricePerNight = (int) ($room->pivot->price_snapshot_ariary ?? $room->base_price_ariary);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => "Chambre {$room->room_number} ({$room->type}) - {$nights} nuit(s)",
                'type' => 'room',
                'amount_ariary' => $pricePerNight,
                'quantity' => $nights,
            ]);
        }
    }

    private function syncReservationExtras(Invoice $invoice, Reservation $reservation): void
    {
        $invoice->items()
            ->whereIn('description', ['Lit supplémentaire', 'Matelas supplémentaire'])
            ->delete();

        if ((int) $reservation->extra_beds > 0) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Lit supplémentaire',
                'type' => 'extra',
                'amount_ariary' => self::EXTRA_BED_PRICE_ARIARY,
                'quantity' => (int) $reservation->extra_beds,
            ]);
        }

        if ((int) $reservation->extra_mattresses > 0) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Matelas supplémentaire',
                'type' => 'extra',
                'amount_ariary' => self::EXTRA_MATTRESS_PRICE_ARIARY,
                'quantity' => (int) $reservation->extra_mattresses,
            ]);
        }
    }

    private function recalculateInvoice(Invoice $invoice): void
    {
        $items = $invoice->items()->get();
        $subtotal = (int) $items->sum(fn (InvoiceItem $item) => $item->amount_ariary * $item->quantity);
        $discountAmount = (int) $invoice->discount_amount_ariary;
        $total = max(0, $subtotal - $discountAmount);

        $invoice->update([
            'tax_amount_ariary' => 0,
            'total_amount_ariary' => $total,
        ]);

        $this->updatePaymentStatus($invoice->refresh());
    }

    private function updatePaymentStatus(Invoice $invoice): void
    {
        if ($invoice->status === 'finalized') {
            return;
        }

        $paid = (int) $invoice->payments()->sum('amount_ariary');
        $status = match (true) {
            $paid <= 0 => 'open',
            $paid < (int) $invoice->total_amount_ariary => 'partial',
            default => 'paid',
        };

        $invoice->update(['status' => $status]);
    }

    private function nextInvoiceNumber(): string
    {
        $year = now()->year;
        $count = Invoice::query()
            ->whereNotNull('invoice_number')
            ->where('invoice_number', 'like', "FACT-{$year}-%")
            ->lockForUpdate()
            ->count();

        return sprintf('FACT-%d-%04d', $year, $count + 1);
    }

    private function folioPayload(Invoice $invoice): array
    {
        $invoice->load(['items', 'payments', 'reservation.guest']);

        return [
            'id' => $invoice->id,
            'reservation_id' => $invoice->reservation_id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'total_amount_ariary' => (int) $invoice->total_amount_ariary,
            'discount_mode' => $invoice->discount_mode,
            'discount_value' => $invoice->discount_value,
            'discount_amount_ariary' => (int) $invoice->discount_amount_ariary,
            'paid_amount_ariary' => $invoice->paid_amount_ariary,
            'balance_amount_ariary' => $invoice->balance_amount_ariary,
            'pdf_url' => $invoice->pdf_path ? url("/api/invoices/{$invoice->id}/pdf") : null,
            'finalized_at' => optional($invoice->finalized_at)->toDateTimeString(),
            'guest' => $invoice->reservation?->guest,
            'items' => $invoice->items
                ->where('type', '!=', 'tax')
                ->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'type' => $item->type,
                'amount_ariary' => (int) $item->amount_ariary,
                'quantity' => (int) $item->quantity,
                'line_total_ariary' => (int) $item->amount_ariary * (int) $item->quantity,
            ])->values(),
            'payments' => $invoice->payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount_ariary' => (int) $payment->amount_ariary,
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference,
                'processed_by_name' => $payment->processed_by_name,
                'created_at' => optional($payment->created_at)->toDateTimeString(),
            ])->values(),
        ];
    }

    private function invoiceHtml(Invoice $invoice): string
    {
        $guestName = e($invoice->reservation->guest->full_name ?? $invoice->reservation->client_name);
        $invoiceNumber = e($invoice->invoice_number);
        $checkIn = $invoice->reservation->check_in_date->format('d/m/Y');
        $checkOut = $invoice->reservation->check_out_date->format('d/m/Y');
        $paidAmount = (int) $invoice->paid_amount_ariary;
        $balanceAmount = (int) $invoice->balance_amount_ariary;
        $visibleItems = $invoice->items->where('type', '!=', 'tax');
        $subtotal = (int) $visibleItems->sum(fn (InvoiceItem $item) => $item->amount_ariary * $item->quantity);
        $discountAmount = (int) $invoice->discount_amount_ariary;
        $paymentStatus = $balanceAmount > 0 ? 'Non soldée' : 'Payée';
        $paymentNotice = $balanceAmount > 0
            ? 'Facture pas encore payée intégralement'
            : 'Facture réglée intégralement';
        $rows = '';
        $paymentRows = '';

        foreach ($visibleItems as $item) {
            $lineTotal = $item->quantity * $item->amount_ariary;
            $rows .= '<tr>'
                . '<td>' . e($item->description) . '</td>'
                . '<td>' . $item->quantity . '</td>'
                . '<td>' . number_format($item->amount_ariary, 0, ',', ' ') . '</td>'
                . '<td>' . number_format($lineTotal, 0, ',', ' ') . '</td>'
                . '</tr>';
        }

        foreach ($invoice->payments as $payment) {
            $reference = $payment->reference ? e($payment->reference) : '-';
            $processedBy = $payment->processed_by_name ? e($payment->processed_by_name) : '-';
            $paymentRows .= '<tr>'
                . '<td>' . optional($payment->created_at)->format('d/m/Y H:i') . '</td>'
                . '<td>' . e($payment->payment_method) . '</td>'
                . '<td>' . $processedBy . '</td>'
                . '<td>' . $reference . '</td>'
                . '<td>' . number_format($payment->amount_ariary, 0, ',', ' ') . '</td>'
                . '</tr>';
        }

        if ($paymentRows === '') {
            $paymentRows = '<tr><td colspan="5">Aucun paiement enregistré</td></tr>';
        }

        return "
            <html>
            <head>
                <meta charset='utf-8'>
                <style>
                    body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 12px; margin: 28px; }
                    .topbar { display: table; width: 100%; border-bottom: 2px solid #0f766e; padding-bottom: 14px; margin-bottom: 18px; }
                    .brand { display: table-cell; width: 62%; vertical-align: top; }
                    .brand h1 { margin: 0; color: #134e4a; font-size: 22px; letter-spacing: 0.4px; }
                    .brand .subtitle { color: #64748b; font-size: 11px; margin-top: 4px; }
                    .meta { display: table-cell; width: 38%; vertical-align: top; text-align: right; }
                    .pill { display: inline-block; padding: 6px 10px; border-radius: 999px; background: #dcfce7; color: #166534; font-weight: bold; font-size: 11px; }
                    .pill.unpaid { background: #fef3c7; color: #92400e; }
                    .info-grid { width: 100%; margin-bottom: 18px; }
                    .info-grid td { vertical-align: top; padding: 0; border: 0; }
                    .box { border: 1px solid #dbe4ea; border-radius: 8px; padding: 12px 14px; }
                    .box-title { color: #64748b; font-size: 10px; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 6px; }
                    table.lines { width: 100%; border-collapse: collapse; margin-top: 4px; }
                    table.lines th, table.lines td { border-bottom: 1px solid #dbe4ea; padding: 9px 8px; text-align: left; }
                    table.lines th { background-color: #f8fafc; color: #0f172a; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
                    table.lines td.num, table.lines th.num { text-align: right; }
                    .section-title { margin: 18px 0 8px; color: #134e4a; font-size: 13px; font-weight: bold; }
                    .summary-wrap { width: 100%; margin-top: 16px; }
                    .summary { width: 42%; margin-left: auto; border: 1px solid #dbe4ea; border-radius: 10px; padding: 12px 14px; }
                    .summary-row { display: table; width: 100%; margin-bottom: 6px; }
                    .summary-row .label { display: table-cell; color: #475569; }
                    .summary-row .value { display: table-cell; text-align: right; }
                    .summary-row.total { margin-top: 8px; padding-top: 8px; border-top: 1px solid #cbd5e1; font-weight: bold; font-size: 13px; color: #0f172a; }
                    .summary-row.discount .value { color: #b91c1c; }
                    .notice { margin: 12px 0 18px; padding: 10px 12px; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 8px; }
                </style>
            </head>
            <body>
                <div class='topbar'>
                    <div class='brand'>
                        <h1>KAMORO HOTEL</h1>
                        <div class='subtitle'>Facture de séjour</div>
                    </div>
                    <div class='meta'>
                        <div style='margin-bottom: 8px; font-weight: bold; color: #0f172a;'>Facture n° {$invoiceNumber}</div>
                        <span class='pill " . ($balanceAmount > 0 ? 'unpaid' : '') . "'>{$paymentStatus}</span>
                    </div>
                </div>
                <div class='notice'>{$paymentNotice}</div>
                <table class='info-grid'>
                    <tr>
                        <td style='width: 56%; padding-right: 10px;'>
                            <div class='box'>
                                <div class='box-title'>Client</div>
                                <strong>{$guestName}</strong><br>
                                Séjour du {$checkIn} au {$checkOut}
                            </div>
                        </td>
                        <td>
                            <div class='box'>
                                <div class='box-title'>Récapitulatif</div>
                                Total prestations: " . number_format($subtotal, 0, ',', ' ') . " Ar<br>
                                Remise: " . number_format($discountAmount, 0, ',', ' ') . " Ar
                            </div>
                        </td>
                    </tr>
                </table>
                <table class='lines'>
                    <thead>
                        <tr><th>Description</th><th class='num'>Qté</th><th class='num'>PU (Ar)</th><th class='num'>Total (Ar)</th></tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
                <div class='section-title'>Paiements</div>
                <table class='lines'>
                    <thead>
                        <tr><th>Date</th><th>Méthode</th><th>Traité par</th><th>Référence</th><th class='num'>Montant (Ar)</th></tr>
                    </thead>
                    <tbody>{$paymentRows}</tbody>
                </table>
                <div class='summary-wrap'>
                    <div class='summary'>
                        <div class='summary-row'>
                            <div class='label'>Sous-total</div>
                            <div class='value'>" . number_format($subtotal, 0, ',', ' ') . " Ar</div>
                        </div>
                        <div class='summary-row discount'>
                            <div class='label'>Remise</div>
                            <div class='value'>- " . number_format($discountAmount, 0, ',', ' ') . " Ar</div>
                        </div>
                        <div class='summary-row total'>
                            <div class='label'>Total facture</div>
                            <div class='value'>" . number_format($invoice->total_amount_ariary, 0, ',', ' ') . " Ar</div>
                        </div>
                        <div class='summary-row'>
                            <div class='label'>Total payé</div>
                            <div class='value'>" . number_format($paidAmount, 0, ',', ' ') . " Ar</div>
                        </div>
                        <div class='summary-row total'>
                            <div class='label'>Reste à payer</div>
                            <div class='value'>" . number_format($balanceAmount, 0, ',', ' ') . " Ar</div>
                        </div>
                    </div>
                </div>
            </body>
            </html>
        ";
    }

    private function applyInvoiceDiscount(Invoice $invoice, ?string $mode, mixed $value): void
    {
        $mode = in_array($mode, ['percent', 'amount'], true) ? $mode : null;
        $numericValue = is_numeric($value) ? (float) $value : null;
        $subtotal = (int) $invoice->items()->get()->sum(fn (InvoiceItem $item) => $item->amount_ariary * $item->quantity);

        $discountAmount = 0;
        if ($mode && $numericValue !== null && $numericValue > 0) {
            $discountAmount = $mode === 'percent'
                ? (int) round($subtotal * ($numericValue / 100))
                : (int) round($numericValue);
        }

        $discountAmount = min(max(0, $discountAmount), max(0, $subtotal));

        if (
            Schema::hasColumn('invoices', 'discount_mode')
            && Schema::hasColumn('invoices', 'discount_value')
            && Schema::hasColumn('invoices', 'discount_amount_ariary')
        ) {
            $invoice->update([
                'discount_mode' => $discountAmount > 0 ? $mode : null,
                'discount_value' => $discountAmount > 0 ? $numericValue : null,
                'discount_amount_ariary' => $discountAmount,
            ]);
        }

        $this->recalculateInvoice($invoice);
    }
}
