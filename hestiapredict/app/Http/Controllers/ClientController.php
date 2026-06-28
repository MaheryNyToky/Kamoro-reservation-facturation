<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Support\PhoneNumber;
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

        $clients = Guest::query()
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
            ])
            ->sort(function (array $left, array $right) use ($normalizedTerm, $searchPhone) {
                $leftScore = $this->searchScore($left, $normalizedTerm, $searchPhone);
                $rightScore = $this->searchScore($right, $normalizedTerm, $searchPhone);

                if ($leftScore !== $rightScore) {
                    return $rightScore <=> $leftScore;
                }

                $leftLoyalty = (int) ($left['loyalty_count'] ?? 0);
                $rightLoyalty = (int) ($right['loyalty_count'] ?? 0);
                if ($leftLoyalty !== $rightLoyalty) {
                    return $rightLoyalty <=> $leftLoyalty;
                }

                return strcmp(
                    (string) ($right['updated_at'] ?? ''),
                    (string) ($left['updated_at'] ?? '')
                );
            })
            ->unique(fn (array $client) => $this->clientKey($client))
            ->take(20)
            ->values();

        return response()->json([
            'data' => $clients,
        ]);
    }

    private function clientKey(array $client): string
    {
        $fullName = Str::lower(Str::ascii(trim((string) ($client['full_name'] ?? ''))));
        $documentNumber = Str::lower(Str::ascii(trim((string) ($client['id_document_number'] ?? $client['id_number'] ?? ''))));
        $phoneNumber = PhoneNumber::normalize($client['phone_number'] ?? null) ?? '';

        if ($fullName !== '' && $phoneNumber !== '') {
            return 'name-phone:' . $fullName . '|' . $phoneNumber;
        }

        if ($documentNumber !== '') {
            return 'doc:' . $documentNumber;
        }

        if ($fullName !== '') {
            return 'name:' . $fullName;
        }

        return 'id:' . (string) ($client['id'] ?? '');
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
