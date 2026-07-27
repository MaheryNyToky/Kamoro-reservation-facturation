<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\AvailabilityService;
use App\Services\YieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class YieldServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Room::query()->delete();
    }

    public function test_fallback_predictions_keep_prices_at_base_price(): void
    {
        $dynamicRoom = Room::query()->create([
            'room_number' => '101',
            'type' => 'Chambre Double',
            'model' => 'Superieure',
            'base_price_ariary' => 120000,
            'is_fixed_price' => false,
        ]);

        Room::query()->create([
            'room_number' => '102',
            'type' => 'Chambre Double',
            'model' => 'Superieure',
            'base_price_ariary' => 120000,
            'is_fixed_price' => false,
        ]);

        Room::query()->create([
            'room_number' => '25',
            'type' => 'Chambre Double',
            'model' => 'Standard degrade',
            'base_price_ariary' => 95000,
            'is_fixed_price' => true,
        ]);

        $reservation = Reservation::query()->create([
            'client_name' => 'Client Test',
            'client_phone' => '0340000000',
            'check_in_date' => '2026-07-01',
            'check_out_date' => '2026-07-03',
            'status' => 'en_attente',
            'source' => 'direct',
        ]);
        $reservation->rooms()->attach($dynamicRoom->id, [
            'price_snapshot_ariary' => 120000,
        ]);

        $result = $this->invokePrivate(
            new YieldService(new AvailabilityService()),
            'fallbackPredictions',
            2,
            '2026-07-01',
        );

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['is_fallback']);

        $dynamicPredictions = $result['results']['Chambre Double - Superieure'];
        $this->assertCount(2, $dynamicPredictions);
        $this->assertSame(1, $dynamicPredictions[0]['predicted_occupancy']);
        $this->assertSame(120000, $dynamicPredictions[0]['fixed_price_ariary']);
        $this->assertSame(120000, $dynamicPredictions[0]['adjusted_price_ariary']);
        $this->assertSame(120000, $dynamicPredictions[0]['suggested_price_ariary']);
        $this->assertFalse($dynamicPredictions[0]['is_fixed_price']);

        $fixedPrediction = $result['results']['Chambre Double - Standard degrade'][0];
        $this->assertSame(95000, $fixedPrediction['fixed_price_ariary']);
        $this->assertSame(95000, $fixedPrediction['adjusted_price_ariary']);
        $this->assertSame(95000, $fixedPrediction['suggested_price_ariary']);
        $this->assertTrue($fixedPrediction['is_fixed_price']);
    }

    public function test_ai_price_alignment_never_goes_below_base_and_keeps_fixed_rooms_fixed(): void
    {
        $service = new YieldService(new AvailabilityService());

        $data = [
            'status' => 'success',
            'results' => [
                'Chambre Double - Superieure' => [
                    [
                        'date' => '2026-07-01',
                        'predicted_occupancy' => 1,
                        'suggested_price_ariary' => 90000,
                        'base_price' => 120000,
                    ],
                ],
                'Chambre Double - Standard degrade' => [
                    [
                        'date' => '2026-07-01',
                        'predicted_occupancy' => 1,
                        'suggested_price_ariary' => 200000,
                        'base_price' => 95000,
                    ],
                ],
            ],
        ];

        $aligned = $this->invokePrivate(
            $service,
            'alignAiPrices',
            $data,
            [
                'Chambre Double - Superieure' => 120000,
                'Chambre Double - Standard degrade' => 95000,
            ],
            [
                'Chambre Double - Superieure' => false,
                'Chambre Double - Standard degrade' => true,
            ],
            [],
            1,
            '2026-07-01',
        );

        $dynamicPrediction = $aligned['results']['Chambre Double - Superieure'][0];
        $this->assertSame(120000, $dynamicPrediction['fixed_price_ariary']);
        $this->assertSame(120000, $dynamicPrediction['adjusted_price_ariary']);
        $this->assertSame(120000, $dynamicPrediction['suggested_price_ariary']);
        $this->assertFalse($dynamicPrediction['is_fixed_price']);

        $fixedPrediction = $aligned['results']['Chambre Double - Standard degrade'][0];
        $this->assertSame(95000, $fixedPrediction['fixed_price_ariary']);
        $this->assertSame(95000, $fixedPrediction['adjusted_price_ariary']);
        $this->assertSame(95000, $fixedPrediction['suggested_price_ariary']);
        $this->assertTrue($fixedPrediction['is_fixed_price']);
    }

    public function test_history_data_spans_each_occupied_night_instead_of_only_check_in_day(): void
    {
        $room = Room::query()->create([
            'room_number' => '103',
            'type' => 'Chambre Double',
            'model' => 'Supérieure',
            'base_price_ariary' => 125000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::query()->create([
            'client_name' => 'Client Long Séjour',
            'client_phone' => '0340000001',
            'check_in_date' => '2026-07-01',
            'check_out_date' => '2026-07-04',
            'status' => 'arrive',
            'source' => 'direct',
        ]);
        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 125000,
        ]);

        $history = $this->invokePrivate(
            new YieldService(new AvailabilityService()),
            'historyData',
        );

        $rows = array_values(array_filter($history, fn (array $row) => $row['room_type'] === 'Chambre Double - Supérieure'));

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['2026-07-01', '2026-07-02', '2026-07-03'],
            array_column($rows, 'date'),
        );
    }

    public function test_history_data_uses_segment_dates_when_present(): void
    {
        $room = Room::query()->create([
            'room_number' => '104',
            'type' => 'Chambre Double',
            'model' => 'Supérieure',
            'base_price_ariary' => 125000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::query()->create([
            'client_name' => 'Client Segmenté',
            'client_phone' => '0340000002',
            'check_in_date' => '2026-07-01',
            'check_out_date' => '2026-07-05',
            'status' => 'arrive',
            'source' => 'direct',
        ]);
        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 125000,
            'segment_start_date' => '2026-07-02',
            'segment_end_date' => '2026-07-04',
        ]);

        $history = $this->invokePrivate(
            new YieldService(new AvailabilityService()),
            'historyData',
        );

        $rows = array_values(array_filter($history, fn (array $row) => $row['room_type'] === 'Chambre Double - Supérieure'));

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['2026-07-02', '2026-07-03'],
            array_column($rows, 'date'),
        );
    }

    public function test_audit_date_returns_collected_and_pending_revenue_from_start_of_selected_year(): void
    {
        $room = Room::query()->create([
            'room_number' => 'CA-YTD-01',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 100000,
            'is_fixed_price' => false,
        ]);

        $stays = [
            ['2025-12-30', '2026-01-02', 'check_out_manuel'],
            ['2026-01-10', '2026-01-13', 'check_out_manuel'],
            ['2026-07-26', '2026-07-29', 'arrive'],
            ['2026-02-01', '2026-02-03', 'en_attente'],
            ['2026-03-01', '2026-03-03', 'annule'],
            ['2026-07-28', '2026-07-30', 'arrive'],
        ];

        $createdReservations = [];
        foreach ($stays as $index => [$checkIn, $checkOut, $status]) {
            $reservation = Reservation::query()->create([
                'client_name' => "Client CA {$index}",
                'client_phone' => '03400003' . str_pad(
                    (string) $index,
                    2,
                    '0',
                    STR_PAD_LEFT,
                ),
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'status' => $status,
                'source' => 'direct',
            ]);
            $reservation->rooms()->attach($room->id, [
                'price_snapshot_ariary' => 100000,
                'segment_start_date' => $checkIn,
                'segment_end_date' => $checkOut,
            ]);
            $createdReservations[$index] = $reservation;
        }

        $createInvoice = function (
            Reservation $reservation,
            int $total,
            ?int $payment = null,
            ?string $paymentDate = null,
        ): Invoice {
            $invoice = Invoice::query()->create([
                'reservation_id' => $reservation->id,
                'invoice_number' => 'FACT-CA-' . $reservation->id,
                'total_amount_ariary' => $total,
                'tax_amount_ariary' => 0,
                'discount_amount_ariary' => 0,
                'deposit_amount_ariary' => 0,
                'status' => $payment !== null && $payment >= $total ? 'paid' : 'partial',
                'document_type' => 'facture',
                'invoice_kind' => 'master',
            ]);

            if ($payment !== null) {
                $record = Payment::query()->create([
                    'invoice_id' => $invoice->id,
                    'amount_ariary' => $payment,
                    'amount_received_ariary' => $payment,
                    'change_given_ariary' => 0,
                    'payment_method' => 'cash',
                ]);
                $record->forceFill([
                    'created_at' => $paymentDate,
                    'updated_at' => $paymentDate,
                ])->saveQuietly();
            }

            return $invoice;
        };

        $createInvoice($createdReservations[0], 200000, 50000, '2026-01-02 10:00:00');
        $createInvoice($createdReservations[1], 300000, 100000, '2026-02-10 10:00:00');
        $createInvoice($createdReservations[2], 400000, 100000, '2025-12-31 10:00:00');
        $createInvoice($createdReservations[3], 500000);
        $createInvoice($createdReservations[5], 600000, 25000, '2026-07-20 10:00:00');

        $result = (new YieldService(new AvailabilityService()))
            ->auditDate('2026-07-27');

        $this->assertSame(100000, $result['daily_ca_official']);
        $this->assertSame(175000, $result['collected_ca']);
        $this->assertSame(1150000, $result['pending_payment_ca']);
        $this->assertSame(1325000, $result['collected_plus_pending_ca']);
        $this->assertSame(1325000, $result['total_ca']);
        $this->assertSame(
            'Du 01/01/2026 au 27/07/2026',
            $result['period'],
        );
    }

    private function invokePrivate(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }
}
