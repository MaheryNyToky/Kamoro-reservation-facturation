<?php

namespace Database\Seeders;

use App\Models\Guest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationAudit;
use App\Models\Room;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ClientTestDatasetSeeder extends Seeder
{
    public function run(): void
    {
        $this->wipeGuestAndReservationData();

        $receptionist = User::firstOrCreate(
            ['email' => 'reception.test@kamoro.local'],
            [
                'name' => 'Réception Test',
                'password' => Hash::make('demo12345'),
                'role' => 'receptionist',
            ]
        );

        $rooms = $this->ensureRooms();
        $today = Carbon::today();

        $clients = [
            [
                'full_name' => 'Sitraka Rasoamaromaka',
                'first_name' => 'Sitraka',
                'last_name' => 'Rasoamaromaka',
                'sex' => 'Femme',
                'id_type' => 'CIN',
                'id_number' => 'CIN 135 409 782',
                'id_document_number' => 'CIN 135 409 782',
                'phone_number' => '034 13 409 78',
                'loyalty_count' => 4,
                'reservation' => [
                    'reference' => 'TEST-SITRAKA-001',
                    'status' => 'arrive',
                    'payment_status' => 'paid',
                    'check_in' => $today->copy()->subDays(8)->toDateString(),
                    'check_out' => $today->copy()->subDays(6)->toDateString(),
                    'rooms' => [0],
                    'invoice_total' => 210000,
                    'tax_amount' => 3000,
                    'payment_method' => 'Mobile Money',
                    'payment_operator' => 'mvola',
                    'payment_amount' => 210000,
                    'payment_context' => 'payment',
                    'payment_reference' => 'PMT-MIORA-001',
                ],
                'history_reservations' => [
                    [
                        'reference' => 'TEST-SITRAKA-H01',
                        'status' => 'arrive',
                        'payment_status' => 'paid',
                        'check_in' => $today->copy()->subMonths(4)->subDays(3)->toDateString(),
                        'check_out' => $today->copy()->subMonths(4)->subDays(1)->toDateString(),
                        'rooms' => [1],
                        'invoice_total' => 180000,
                        'tax_amount' => 2000,
                        'payment_method' => 'Espèces',
                        'payment_operator' => null,
                        'payment_amount' => 180000,
                        'payment_context' => 'payment',
                        'payment_reference' => 'PMT-SITRAKA-H01',
                    ],
                ],
            ],
            [
                'full_name' => 'Hanta Randriamihaja',
                'first_name' => 'Hanta',
                'last_name' => 'Randriamihaja',
                'sex' => 'Homme',
                'id_type' => 'Passeport',
                'id_number' => 'PP-MG 624801',
                'id_document_number' => 'PP-MG 624801',
                'passport_valid_from' => $today->copy()->addMonths(2)->toDateString(),
                'passport_valid_until' => $today->copy()->addMonths(26)->toDateString(),
                'phone_number' => '034 22 480 16',
                'loyalty_count' => 3,
                'reservation' => [
                    'reference' => 'TEST-HANTA-002',
                    'status' => 'arrive',
                    'payment_status' => 'partial',
                    'check_in' => $today->copy()->subDays(1)->toDateString(),
                    'check_out' => $today->copy()->addDays(1)->toDateString(),
                    'rooms' => [1, 2],
                    'invoice_total' => 335000,
                    'tax_amount' => 3000,
                    'payment_method' => 'Mobile Money',
                    'payment_operator' => 'orange money',
                    'payment_amount' => 120000,
                    'payment_context' => 'deposit',
                    'payment_reference' => 'DEP-ANDRY-002',
                ],
                'history_reservations' => [
                    [
                        'reference' => 'TEST-HANTA-H01',
                        'status' => 'arrive',
                        'payment_status' => 'paid',
                        'check_in' => $today->copy()->subMonths(6)->subDays(5)->toDateString(),
                        'check_out' => $today->copy()->subMonths(6)->subDays(3)->toDateString(),
                        'rooms' => [2],
                        'invoice_total' => 240000,
                        'tax_amount' => 2000,
                        'payment_method' => 'Mobile Money',
                        'payment_operator' => 'airtel money',
                        'payment_amount' => 240000,
                        'payment_context' => 'payment',
                        'payment_reference' => 'PMT-HANTA-H01',
                    ],
                    [
                        'reference' => 'TEST-HANTA-H02',
                        'status' => 'annule',
                        'payment_status' => 'unbilled',
                        'check_in' => $today->copy()->subMonths(2)->subDays(9)->toDateString(),
                        'check_out' => $today->copy()->subMonths(2)->subDays(7)->toDateString(),
                        'rooms' => [3],
                        'invoice_total' => 175000,
                        'tax_amount' => 2000,
                        'payment_method' => null,
                        'payment_operator' => null,
                        'payment_amount' => 0,
                        'payment_context' => null,
                        'payment_reference' => null,
                    ],
                ],
            ],
            [
                'full_name' => 'Mahery Rakotovao',
                'first_name' => 'Mahery',
                'last_name' => 'Rakotovao',
                'sex' => 'Homme',
                'id_type' => 'CIN',
                'id_number' => 'CIN 287 654 319',
                'id_document_number' => 'CIN 287 654 319',
                'phone_number' => '034 33 654 31',
                'loyalty_count' => 2,
                'reservation' => [
                    'reference' => 'TEST-MAHERY-003',
                    'status' => 'arrive',
                    'payment_status' => 'unpaid',
                    'check_in' => $today->copy()->subDays(3)->toDateString(),
                    'check_out' => $today->copy()->addDay()->toDateString(),
                    'rooms' => [2],
                    'invoice_total' => 125000,
                    'tax_amount' => 2000,
                    'payment_method' => null,
                    'payment_operator' => null,
                    'payment_amount' => 0,
                    'payment_context' => null,
                    'payment_reference' => null,
                ],
            ],
            [
                'full_name' => 'Anita Rakotonirina',
                'first_name' => 'Anita',
                'last_name' => 'Rakotonirina',
                'sex' => 'Femme',
                'id_type' => 'Passeport',
                'id_number' => 'PP-MG 748205',
                'id_document_number' => 'PP-MG 748205',
                'passport_valid_from' => $today->copy()->addMonths(4)->toDateString(),
                'passport_valid_until' => $today->copy()->addMonths(28)->toDateString(),
                'phone_number' => '034 44 820 15',
                'loyalty_count' => 5,
                'reservation' => [
                    'reference' => 'TEST-ANITA-004',
                    'status' => 'en_attente',
                    'payment_status' => 'unbilled',
                    'check_in' => $today->copy()->addDays(5)->toDateString(),
                    'check_out' => $today->copy()->addDays(7)->toDateString(),
                    'rooms' => [3, 4],
                    'invoice_total' => 278000,
                    'tax_amount' => 3000,
                    'payment_method' => null,
                    'payment_operator' => null,
                    'payment_amount' => 0,
                    'payment_context' => null,
                    'payment_reference' => null,
                ],
            ],
            [
                'full_name' => 'Lova Randrianarisoa',
                'first_name' => 'Lova',
                'last_name' => 'Randrianarisoa',
                'sex' => 'Femme',
                'id_type' => 'CIN',
                'id_number' => 'CIN 352 701 466',
                'id_document_number' => 'CIN 352 701 466',
                'phone_number' => '034 55 701 46',
                'loyalty_count' => 2,
                'reservation' => [
                    'reference' => 'TEST-LOVA-005',
                    'status' => 'annule',
                    'payment_status' => 'unbilled',
                    'check_in' => $today->copy()->subDays(12)->toDateString(),
                    'check_out' => $today->copy()->subDays(10)->toDateString(),
                    'rooms' => [4],
                    'invoice_total' => 99000,
                    'tax_amount' => 2000,
                    'payment_method' => null,
                    'payment_operator' => null,
                    'payment_amount' => 0,
                    'payment_context' => null,
                    'payment_reference' => null,
                ],
            ],
            [
                'full_name' => 'Fenitra Rabe',
                'first_name' => 'Fenitra',
                'last_name' => 'Rabe',
                'sex' => 'Homme',
                'id_type' => 'Passeport',
                'id_number' => 'PP-MG 591378',
                'id_document_number' => 'PP-MG 591378',
                'passport_valid_from' => $today->copy()->addMonths(3)->toDateString(),
                'passport_valid_until' => $today->copy()->addMonths(27)->toDateString(),
                'phone_number' => '034 66 378 19',
                'loyalty_count' => 6,
                'reservation' => [
                    'reference' => 'TEST-FENITRA-006',
                    'status' => 'arrive',
                    'payment_status' => 'paid',
                    'check_in' => $today->copy()->toDateString(),
                    'check_out' => $today->copy()->addDays(2)->toDateString(),
                    'rooms' => [5],
                    'invoice_total' => 182000,
                    'tax_amount' => 4000,
                    'payment_method' => 'Espèces',
                    'payment_operator' => null,
                    'payment_amount' => 182000,
                    'payment_context' => 'payment',
                    'payment_reference' => 'PMT-HERY-006',
                ],
            ],
            [
                'full_name' => 'Mialy Rakotomanga',
                'first_name' => 'Mialy',
                'last_name' => 'Rakotomanga',
                'sex' => 'Femme',
                'id_type' => 'CIN',
                'id_number' => 'CIN 473 118 650',
                'id_document_number' => 'CIN 473 118 650',
                'phone_number' => '034 77 118 65',
                'loyalty_count' => 3,
                'reservation' => [
                    'reference' => 'TEST-MIALY-007',
                    'status' => 'arrive',
                    'payment_status' => 'partial',
                    'check_in' => $today->copy()->subDay()->toDateString(),
                    'check_out' => $today->copy()->addDays(3)->toDateString(),
                    'rooms' => [6, 7],
                    'invoice_total' => 318000,
                    'tax_amount' => 4000,
                    'payment_method' => 'Carte Bancaire',
                    'payment_operator' => null,
                    'payment_amount' => 110000,
                    'payment_context' => 'deposit',
                    'payment_reference' => 'DEP-LALAINA-007',
                ],
            ],
            [
                'full_name' => 'Tojo Randriamampionona',
                'first_name' => 'Tojo',
                'last_name' => 'Randriamampionona',
                'sex' => 'Homme',
                'id_type' => 'CIN',
                'id_number' => 'CIN 580 944 217',
                'id_document_number' => 'CIN 580 944 217',
                'phone_number' => '034 88 944 21',
                'loyalty_count' => 7,
                'reservation' => [
                    'reference' => 'TEST-TOJO-008',
                    'status' => 'arrive',
                    'payment_status' => 'paid',
                    'check_in' => $today->copy()->subDays(16)->toDateString(),
                    'check_out' => $today->copy()->subDays(14)->toDateString(),
                    'rooms' => [7],
                    'invoice_total' => 148000,
                    'tax_amount' => 2000,
                    'payment_method' => 'Mobile Money',
                    'payment_operator' => 'airtel money',
                    'payment_amount' => 148000,
                    'payment_context' => 'payment',
                    'payment_reference' => 'PMT-MAMY-008',
                ],
            ],
            [
                'full_name' => 'Noely Razafindrakoto',
                'first_name' => 'Noely',
                'last_name' => 'Razafindrakoto',
                'sex' => 'Femme',
                'id_type' => 'Passeport',
                'id_number' => 'PP-MG 912604',
                'id_document_number' => 'PP-MG 912604',
                'passport_valid_from' => $today->copy()->addMonths(5)->toDateString(),
                'passport_valid_until' => $today->copy()->addMonths(29)->toDateString(),
                'phone_number' => '034 99 260 41',
                'loyalty_count' => 2,
                'reservation' => [
                    'reference' => 'TEST-NOELY-009',
                    'status' => 'en_attente',
                    'payment_status' => 'unbilled',
                    'check_in' => $today->copy()->addDays(10)->toDateString(),
                    'check_out' => $today->copy()->addDays(12)->toDateString(),
                    'rooms' => [8],
                    'invoice_total' => 172000,
                    'tax_amount' => 2000,
                    'payment_method' => null,
                    'payment_operator' => null,
                    'payment_amount' => 0,
                    'payment_context' => null,
                    'payment_reference' => null,
                ],
            ],
            [
                'full_name' => 'Rado Ramiandrisoa',
                'first_name' => 'Rado',
                'last_name' => 'Ramiandrisoa',
                'sex' => 'Homme',
                'id_type' => 'CIN',
                'id_number' => 'CIN 645 210 988',
                'id_document_number' => 'CIN 645 210 988',
                'phone_number' => '034 12 210 98',
                'loyalty_count' => 8,
                'reservation' => [
                    'reference' => 'TEST-RADO-010',
                    'status' => 'arrive',
                    'payment_status' => 'partial',
                    'check_in' => $today->copy()->subDays(6)->toDateString(),
                    'check_out' => $today->copy()->addDay()->toDateString(),
                    'rooms' => [9],
                    'invoice_total' => 129000,
                    'tax_amount' => 2000,
                    'payment_method' => 'Mobile Money',
                    'payment_operator' => 'mvola',
                    'payment_amount' => 70000,
                    'payment_context' => 'deposit',
                    'payment_reference' => 'DEP-RADO-010',
                ],
            ],
        ];

        $index = 0;
        foreach ($clients as $client) {
            $historyReservations = $client['history_reservations'] ?? [];
            foreach ($historyReservations as $historyReservation) {
                $historyClient = $client;
                unset($historyClient['history_reservations']);
                $historyClient['reservation'] = $historyReservation;
                $this->createClientReservation($historyClient, $rooms, $receptionist, $index);
                $index++;
            }

            unset($client['history_reservations']);
            $this->createClientReservation($client, $rooms, $receptionist, $index);
            $index++;
        }
    }

    private function wipeGuestAndReservationData(): void
    {
        DB::transaction(function (): void {
            DB::table('booking_room')->delete();
            ReservationAudit::query()->delete();
            Payment::query()->delete();
            InvoiceItem::query()->delete();
            Invoice::query()->delete();
            Guest::query()->delete();
            Reservation::query()->delete();
        });
    }

    /**
     * @return Collection<int, Room>
     */
    private function ensureRooms(): Collection
    {
        $rooms = Room::query()->orderBy('room_number')->get();
        if ($rooms->count() >= 10) {
            return $rooms;
        }

        $needed = 10 - $rooms->count();
        for ($i = 0; $i < $needed; $i++) {
            Room::create([
                'room_number' => (string) (910 + $i),
                'type' => $i % 2 === 0 ? 'Chambre Double' : 'Chambre Simple',
                'model' => $i % 3 === 0 ? 'Standard' : 'Supérieure',
                'base_price_ariary' => $i % 2 === 0 ? 110000 : 85000,
                'is_fixed_price' => $i % 4 === 0,
            ]);
        }

        return Room::query()->orderBy('room_number')->get();
    }

    /**
     * @param array<string, mixed> $client
     * @param Collection<int, Room> $rooms
     */
    private function createClientReservation(array $client, Collection $rooms, User $receptionist, int $index): void
    {
        $reservation = $client['reservation'];
        $selectedRooms = $this->pickRooms($rooms, $reservation['rooms'] ?? [0]);
        $phone = PhoneNumber::normalize($client['phone_number']);
        $invoiceTotal = (int) $reservation['invoice_total'];
        $taxAmount = (int) $reservation['tax_amount'];

        DB::transaction(function () use (
            $client,
            $receptionist,
            $phone,
            $reservation,
            $selectedRooms,
            $invoiceTotal,
            $taxAmount,
            $index
        ): void {
            $reservationRecord = Reservation::create([
                'client_name' => $client['full_name'],
                'client_phone' => $phone,
                'customer_phone' => $phone,
                'customer_email' => $this->makeEmail($client['full_name'], $index),
                'booking_reference' => $reservation['reference'],
                'source' => 'Appel',
                'is_booking_com' => false,
                'check_in_date' => $reservation['check_in'],
                'check_out_date' => $reservation['check_out'],
                'status' => $reservation['status'],
                'payment_status' => $reservation['payment_status'],
                'user_id' => $receptionist->id,
                'extra_beds' => $index % 3,
                'extra_mattresses' => $index % 2,
            ]);

            foreach ($selectedRooms as $room) {
                $snapshot = (int) $room->base_price_ariary;
                if ($room->is_fixed_price === false && $index % 2 === 1) {
                    $snapshot += 10000 * ($index % 2);
                }

                $reservationRecord->rooms()->attach($room->id, [
                    'price_snapshot_ariary' => $snapshot,
                ]);
            }

            Guest::create([
                'reservation_id' => $reservationRecord->id,
                'first_name' => $client['first_name'],
                'last_name' => $client['last_name'],
                'phone_number' => $phone,
                'sex' => $client['sex'],
                'passport_valid_from' => $client['passport_valid_from'] ?? null,
                'passport_valid_until' => $client['passport_valid_until'] ?? null,
                'id_document_number' => $client['id_document_number'],
                'loyalty_count' => (int) $client['loyalty_count'],
                'full_name' => $client['full_name'],
                'date_of_birth' => Carbon::parse('1990-01-01')->addYears($index % 9),
                'id_type' => $client['id_type'],
                'id_number' => $client['id_number'],
                'id_photo_path' => null,
            ]);

            $invoice = Invoice::create([
                'reservation_id' => $reservationRecord->id,
                'invoice_number' => 'FACT-TEST-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'total_amount_ariary' => $invoiceTotal,
                'tax_amount_ariary' => $taxAmount,
                'discount_mode' => null,
                'discount_value' => null,
                'discount_amount_ariary' => 0,
                'deposit_amount_ariary' => 0,
                'pdf_path' => null,
                'finalized_at' => in_array($reservation['payment_status'], ['paid', 'partial'], true) ? now() : null,
                'status' => in_array($reservation['payment_status'], ['paid', 'partial'], true)
                    ? $reservation['payment_status']
                    : 'open',
            ]);

            foreach ($selectedRooms as $room) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Chambre ' . $room->room_number . ' (' . $room->type . ')',
                    'type' => 'room',
                    'amount_ariary' => (int) $room->base_price_ariary,
                    'quantity' => 1,
                ]);
            }

            if (!empty($reservation['payment_amount'])) {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'amount_ariary' => (int) $reservation['payment_amount'],
                    'payment_method' => $reservation['payment_method'] ?? 'Espèces',
                    'payment_operator' => $reservation['payment_operator'] ?? null,
                    'payment_context' => $reservation['payment_context'] ?? 'payment',
                    'reference' => $reservation['payment_reference'] ?? null,
                    'processed_by_name' => $receptionist->name,
                    'processed_by_role' => $receptionist->role,
                ]);
            }

            if ($reservation['payment_status'] === 'partial') {
                $invoice->update([
                    'deposit_amount_ariary' => (int) $reservation['payment_amount'],
                ]);
            }

            ReservationAudit::create([
                'reservation_id' => $reservationRecord->id,
                'action' => 'booked',
                'actor_name' => $receptionist->name,
                'actor_role' => $receptionist->role,
                'details' => [
                    'room_ids' => $selectedRooms->pluck('id')->values()->all(),
                    'status' => $reservation['status'],
                    'payment_status' => $reservation['payment_status'],
                    'passage_count' => (int) $client['loyalty_count'],
                ],
            ]);

            if (in_array($reservation['status'], ['arrive', 'arrive_paid', 'arrive_unpaid'], true)) {
                ReservationAudit::create([
                    'reservation_id' => $reservationRecord->id,
                    'action' => 'check_in',
                    'actor_name' => $receptionist->name,
                    'actor_role' => $receptionist->role,
                    'details' => [
                        'status' => $reservation['status'],
                    ],
                ]);
            }

            if ($reservation['status'] === 'annule') {
                ReservationAudit::create([
                    'reservation_id' => $reservationRecord->id,
                    'action' => 'cancelled',
                    'actor_name' => $receptionist->name,
                    'actor_role' => $receptionist->role,
                    'details' => [
                        'status' => 'annule',
                        'reason' => 'Client a reporté son séjour',
                    ],
                ]);
            }
        });
    }

    /**
     * @param Collection<int, Room> $rooms
     * @param array<int, int> $roomIndexes
     * @return Collection<int, Room>
     */
    private function pickRooms(Collection $rooms, array $roomIndexes): Collection
    {
        $selected = collect();
        foreach ($roomIndexes as $roomIndex) {
            $room = $rooms->values()->get($roomIndex);
            if ($room) {
                $selected->push($room);
            }
        }

        if ($selected->isEmpty()) {
            $selected->push($rooms->first());
        }

        return $selected->unique('id')->values();
    }

    private function makeEmail(string $fullName, int $index): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '.', $fullName) ?? 'client', '.'));

        return $slug . '.' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '@demo.local';
    }
}
