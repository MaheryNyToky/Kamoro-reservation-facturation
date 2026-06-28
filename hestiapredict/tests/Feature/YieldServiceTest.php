<?php

namespace Tests\Feature;

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

    private function invokePrivate(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }
}
