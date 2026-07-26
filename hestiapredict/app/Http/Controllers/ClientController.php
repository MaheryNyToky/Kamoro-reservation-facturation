<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        $term = trim($validated['q']);
        $normalizedTerm = Str::lower(Str::ascii($term));
        $normalizedPhone = PhoneNumber::normalize($term);
        $searchPhone = $normalizedPhone ?? preg_replace('/\D+/', '', $term);

        $guestClients = Guest::query()
            ->with('reservation')
            ->where(function ($query) use ($normalizedTerm, $searchPhone) {
                $nameLike = '%' . $normalizedTerm . '%';
                $phoneLike = $searchPhone !== '' ? $searchPhone . '%' : null;
                $documentLike = $normalizedTerm !== '' ? $normalizedTerm . '%' : null;

                $query->whereRaw('LOWER(full_name) LIKE ?', [$nameLike])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', [$nameLike])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$nameLike]);

                if ($phoneLike !== null) {
                    $query->orWhereRaw('LOWER(phone_number) LIKE ?', [$phoneLike])
                        ->orWhereRaw('LOWER(id_number) LIKE ?', [$documentLike ?? $phoneLike])
                        ->orWhereRaw('LOWER(id_document_number) LIKE ?', [$documentLike ?? $phoneLike]);
                } elseif ($documentLike !== null) {
                    $query->orWhereRaw('LOWER(id_number) LIKE ?', [$documentLike])
                        ->orWhereRaw('LOWER(id_document_number) LIKE ?', [$documentLike]);
                }
            })
            ->limit(100)
            ->get()
            ->map(fn (Guest $guest) => [
                'id' => $guest->id,
                'reservation_id' => $guest->reservation_id,
                'full_name' => $guest->full_name,
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'phone_number' => $guest->phone_number,
                'sex' => $guest->sex,
                'date_of_birth' => optional($guest->date_of_birth)->toDateString(),
                'passport_valid_from' => optional($guest->passport_valid_from)->toDateString(),
                'passport_valid_until' => optional($guest->passport_valid_until)->toDateString(),
                'id_type' => $guest->id_type,
                'id_number' => $guest->id_number,
                'id_document_number' => $guest->id_document_number,
                'id_photo_path' => $guest->id_photo_path,
                'loyalty_count' => (int) $guest->loyalty_count,
                'created_at' => optional($guest->created_at)->toDateTimeString(),
                'updated_at' => optional($guest->updated_at)->toDateTimeString(),
                'reservation' => $guest->reservation ? [
                    'id' => $guest->reservation->id,
                    'booking_reference' => $guest->reservation->booking_reference,
                    'client_name' => $guest->reservation->client_name,
                    'client_phone' => $guest->reservation->client_phone,
                    'customer_phone' => $guest->reservation->customer_phone,
                    'customer_email' => $guest->reservation->customer_email,
                    'status' => $guest->reservation->status,
                    'payment_status' => $guest->reservation->payment_status,
                    'check_in_date' => optional($guest->reservation->check_in_date)->toDateString(),
                    'check_out_date' => optional($guest->reservation->check_out_date)->toDateString(),
                    'source' => $guest->reservation->source,
                ] : null,
            ]);

        $occupantClients = DB::table('booking_room')
            ->join('reservations', 'reservations.id', '=', 'booking_room.reservation_id')
            ->whereNotNull('booking_room.occupant_name')
            ->whereRaw("TRIM(booking_room.occupant_name) <> ''")
            ->where(function ($query) use ($normalizedTerm, $searchPhone) {
                $nameLike = '%' . $normalizedTerm . '%';
                $phoneLike = $searchPhone !== '' ? $searchPhone . '%' : null;
                $documentLike = $normalizedTerm !== '' ? $normalizedTerm . '%' : null;

                $query->whereRaw('LOWER(booking_room.occupant_name) LIKE ?', [$nameLike])
                    ->orWhereRaw('LOWER(booking_room.occupant_first_name) LIKE ?', [$nameLike]);

                if ($phoneLike !== null) {
                    $query->orWhereRaw('LOWER(booking_room.occupant_phone) LIKE ?', [$phoneLike])
                        ->orWhereRaw('LOWER(booking_room.occupant_id_number) LIKE ?', [$documentLike ?? $phoneLike]);
                } elseif ($documentLike !== null) {
                    $query->orWhereRaw('LOWER(booking_room.occupant_id_number) LIKE ?', [$documentLike]);
                }
            })
            ->select([
                'booking_room.id',
                'booking_room.reservation_id',
                'booking_room.occupant_name',
                'booking_room.occupant_first_name',
                'booking_room.occupant_phone',
                'booking_room.occupant_date_of_birth',
                'booking_room.occupant_sex',
                'booking_room.occupant_id_type',
                'booking_room.occupant_id_number',
                'booking_room.occupant_passport_valid_from',
                'booking_room.occupant_passport_valid_until',
                'booking_room.checked_in_at',
                'booking_room.updated_at',
                'reservations.booking_reference',
                'reservations.client_name',
                'reservations.client_phone',
                'reservations.customer_phone',
                'reservations.customer_email',
                'reservations.status',
                'reservations.payment_status',
                'reservations.check_in_date',
                'reservations.check_out_date',
                'reservations.source',
            ])
            ->limit(100)
            ->get()
            ->map(function (object $occupant) {
                $lastName = trim((string) $occupant->occupant_name);
                $firstName = trim((string) ($occupant->occupant_first_name ?? ''));

                return [
                    'id' => -1 * (int) $occupant->id,
                    'reservation_id' => (int) $occupant->reservation_id,
                    'full_name' => trim($lastName . ' ' . $firstName),
                    'first_name' => $firstName !== '' ? $firstName : null,
                    'last_name' => $lastName,
                    'phone_number' => $occupant->occupant_phone,
                    'sex' => $occupant->occupant_sex,
                    'date_of_birth' => $occupant->occupant_date_of_birth,
                    'passport_valid_from' => $occupant->occupant_passport_valid_from,
                    'passport_valid_until' => $occupant->occupant_passport_valid_until,
                    'id_type' => $occupant->occupant_id_type,
                    'id_number' => $occupant->occupant_id_number,
                    'id_document_number' => $occupant->occupant_id_number,
                    'id_photo_path' => null,
                    'loyalty_count' => 0,
                    'created_at' => $occupant->checked_in_at,
                    'updated_at' => $occupant->updated_at ?? $occupant->checked_in_at,
                    'reservation' => [
                        'id' => (int) $occupant->reservation_id,
                        'booking_reference' => $occupant->booking_reference,
                        'client_name' => $occupant->client_name,
                        'client_phone' => $occupant->client_phone,
                        'customer_phone' => $occupant->customer_phone,
                        'customer_email' => $occupant->customer_email,
                        'status' => $occupant->status,
                        'payment_status' => $occupant->payment_status,
                        'check_in_date' => $occupant->check_in_date,
                        'check_out_date' => $occupant->check_out_date,
                        'source' => $occupant->source,
                    ],
                ];
            });

        $seenClientKeys = [];

        $clients = $guestClients
            ->concat($occupantClients)
            ->sort(function (array $left, array $right) use ($normalizedTerm, $searchPhone) {
                $leftScore = $this->searchScore($left, $normalizedTerm, $searchPhone);
                $rightScore = $this->searchScore($right, $normalizedTerm, $searchPhone);

                if ($leftScore !== $rightScore) {
                    return $rightScore <=> $leftScore;
                }

                $updatedComparison = strcmp(
                    (string) ($right['updated_at'] ?? ''),
                    (string) ($left['updated_at'] ?? '')
                );
                if ($updatedComparison !== 0) {
                    return $updatedComparison;
                }

                $leftLoyalty = (int) ($left['loyalty_count'] ?? 0);
                $rightLoyalty = (int) ($right['loyalty_count'] ?? 0);
                if ($leftLoyalty !== $rightLoyalty) {
                    return $rightLoyalty <=> $leftLoyalty;
                }

                return 0;
            })
            ->filter(function (array $client) use (&$seenClientKeys) {
                $keys = $this->clientKeys($client);

                if (collect($keys)->contains(
                    fn (string $key) => isset($seenClientKeys[$key])
                )) {
                    return false;
                }

                foreach ($keys as $key) {
                    $seenClientKeys[$key] = true;
                }

                return true;
            })
            ->take(20)
            ->values();

        return response()->json([
            'data' => $clients,
        ]);
    }

    /**
     * @return list<string>
     */
    private function clientKeys(array $client): array
    {
        $fullName = Str::lower(Str::ascii(trim((string) ($client['full_name'] ?? ''))));
        $documentNumber = $this->normalizeDocumentNumber(
            $client['id_document_number'] ?? null,
            $client['id_number'] ?? null,
        );
        $phoneNumber = PhoneNumber::normalize($client['phone_number'] ?? null) ?? '';
        $keys = [];

        if ($documentNumber !== '') {
            $keys[] = 'doc:' . $documentNumber;
        }

        if ($fullName !== '' && $phoneNumber !== '') {
            $keys[] = 'name-phone:' . $fullName . '|' . $phoneNumber;
        }

        if ($keys === [] && $fullName !== '') {
            $keys[] = 'name:' . $fullName;
        }

        if ($keys === []) {
            $keys[] = 'id:' . (string) ($client['id'] ?? '');
        }

        return $keys;
    }

    private function normalizeDocumentNumber(mixed ...$values): string
    {
        foreach ($values as $value) {
            $normalized = Str::lower(Str::ascii(trim((string) $value)));
            $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? '';

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function searchScore(array $client, string $normalizedTerm, string $searchPhone): int
    {
        $score = 0;
        $name = Str::lower(Str::ascii(trim((string) ($client['full_name'] ?? ''))));
        $firstName = Str::lower(Str::ascii(trim((string) ($client['first_name'] ?? ''))));
        $lastName = Str::lower(Str::ascii(trim((string) ($client['last_name'] ?? ''))));
        $document = Str::lower(Str::ascii(trim((string) ($client['id_document_number'] ?? $client['id_number'] ?? ''))));
        $phone = PhoneNumber::normalize($client['phone_number'] ?? null) ?? '';

        foreach ([$name, $firstName, $lastName] as $field) {
            if ($field === '') {
                continue;
            }

            if ($field === $normalizedTerm) {
                $score = max($score, 100);
                continue;
            }

            if ($normalizedTerm !== '' && Str::startsWith($field, $normalizedTerm)) {
                $score = max($score, 90);
                continue;
            }

            if ($normalizedTerm !== '' && Str::contains($field, $normalizedTerm)) {
                $score = max($score, 70);
            }
        }

        if ($searchPhone !== '') {
            if ($phone !== '' && Str::startsWith($phone, $searchPhone)) {
                $score = max($score, 95);
            }
        }

        if ($normalizedTerm !== '' && $document !== '' && Str::startsWith($document, $normalizedTerm)) {
            $score = max($score, 85);
        }

        return $score;
    }
}
