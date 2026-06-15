<?php

namespace Database\Seeders;

use App\Models\Guest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoClientsSeeder extends Seeder
{
    public function run(): void
    {
        $receptionist = User::firstOrCreate(
            ['email' => 'reception.demo@kamoro.local'],
            [
                'name' => 'Réception Démo',
                'password' => Hash::make('demo12345'),
                'role' => 'receptionist',
            ]
        );

        $room = Room::query()->first();
        if (! $room) {
            $room = Room::create([
                'room_number' => '900',
                'type' => 'Chambre Double',
                'model' => 'Standard',
                'base_price_ariary' => 110000,
                'is_fixed_price' => false,
            ]);
        }

        $clients = [
            [
                'key' => 'miora-rakotonirina',
                'full_name' => 'Miora Rakotonirina',
                'first_name' => 'Miora',
                'last_name' => 'Rakotonirina',
                'phone_number' => '+261340111111',
                'id_type' => 'CIN',
                'id_number' => 'CIN-482913-01',
                'id_document_number' => 'CIN-482913-01',
                'loyalty_count' => 2,
                'check_in_date' => '2026-05-21',
                'check_out_date' => '2026-05-22',
                'invoice_total' => 110000,
                'tax_amount' => 2000,
            ],
            [
                'key' => 'hery-ramanandraibe',
                'full_name' => 'Hery Ramanandraibe',
                'first_name' => 'Hery',
                'last_name' => 'Ramanandraibe',
                'phone_number' => '+261340222222',
                'id_type' => 'Passeport',
                'id_number' => 'PP-MG-774290',
                'id_document_number' => 'PP-MG-774290',
                'loyalty_count' => 8,
                'check_in_date' => '2026-05-18',
                'check_out_date' => '2026-05-20',
                'invoice_total' => 220000,
                'tax_amount' => 4000,
            ],
            [
                'key' => 'tiana-andrianarisoa',
                'full_name' => 'Tiana Andrianarisoa',
                'first_name' => 'Tiana',
                'last_name' => 'Andrianarisoa',
                'phone_number' => '+261340333333',
                'id_type' => 'Permis',
                'id_number' => 'PERMIS-MG-901274',
                'id_document_number' => 'PERMIS-MG-901274',
                'loyalty_count' => 10,
                'check_in_date' => '2026-05-12',
                'check_out_date' => '2026-05-13',
                'invoice_total' => 165000,
                'tax_amount' => 2000,
            ],
        ];

        foreach ($clients as $client) {
            $bookingReference = 'DEMO-' . strtoupper($client['key']);
            if (Reservation::where('booking_reference', $bookingReference)->exists()) {
                continue;
            }

            DB::transaction(function () use ($client, $bookingReference, $receptionist, $room): void {
                $reservation = Reservation::create([
                    'client_name' => $client['full_name'],
                    'client_phone' => $client['phone_number'],
                    'customer_phone' => $client['phone_number'],
                    'customer_email' => strtolower(str_replace(' ', '.', $client['full_name'])) . '@demo.local',
                    'booking_reference' => $bookingReference,
                    'is_booking_com' => false,
                    'source' => 'Appel',
                    'check_in_date' => $client['check_in_date'],
                    'check_out_date' => $client['check_out_date'],
                    'status' => 'arrive',
                    'payment_status' => 'paid',
                    'user_id' => $receptionist->id,
                    'extra_beds' => 0,
                    'extra_mattresses' => 0,
                ]);

                $reservation->rooms()->attach($room->id, [
                    'price_snapshot_ariary' => $client['invoice_total'],
                ]);

                Guest::create([
                    'reservation_id' => $reservation->id,
                    'first_name' => $client['first_name'],
                    'last_name' => $client['last_name'],
                    'phone_number' => $client['phone_number'],
                    'id_document_number' => $client['id_document_number'],
                    'loyalty_count' => $client['loyalty_count'],
                    'full_name' => $client['full_name'],
                    'date_of_birth' => Carbon::parse('1990-01-01')->addYears($client['loyalty_count'] % 7),
                    'id_type' => $client['id_type'],
                    'id_number' => $client['id_number'],
                    'id_photo_path' => null,
                ]);

                $invoice = Invoice::create([
                    'reservation_id' => $reservation->id,
                    'invoice_number' => 'FACT-DEMO-' . strtoupper($client['key']),
                    'total_amount_ariary' => $client['invoice_total'],
                    'tax_amount_ariary' => $client['tax_amount'],
                    'discount_mode' => null,
                    'discount_value' => null,
                    'discount_amount_ariary' => 0,
                    'deposit_amount_ariary' => 0,
                    'pdf_path' => null,
                    'finalized_at' => now(),
                    'status' => 'paid',
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Séjour chambre',
                    'type' => 'room',
                    'amount_ariary' => $client['invoice_total'],
                    'quantity' => 1,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Taxe de séjour',
                    'type' => 'tax',
                    'amount_ariary' => $client['tax_amount'],
                    'quantity' => 1,
                ]);

                Payment::create([
                    'invoice_id' => $invoice->id,
                    'amount_ariary' => $client['invoice_total'],
                    'payment_method' => 'Espèces',
                    'reference' => 'PAY-' . strtoupper($client['key']),
                    'processed_by_name' => $receptionist->name,
                ]);
            });
        }
    }
}
