<?php

namespace App\Http\Controllers;

use App\Models\Guest;
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

        $clients = Guest::query()
            ->with('reservation')
            ->where(function ($query) use ($normalizedTerm) {
                $like = '%' . $normalizedTerm . '%';

                $query->whereRaw('LOWER(full_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(phone_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(id_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(id_document_number) LIKE ?', [$like])
                    ->orWhereHas('reservation', function ($reservationQuery) use ($like) {
                        $reservationQuery
                            ->whereRaw('LOWER(client_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(client_phone) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(customer_phone) LIKE ?', [$like]);
                    });
            })
            ->orderByDesc('loyalty_count')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (Guest $guest) => [
                'id' => $guest->id,
                'reservation_id' => $guest->reservation_id,
                'full_name' => $guest->full_name,
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'phone_number' => $guest->phone_number,
                'date_of_birth' => optional($guest->date_of_birth)->toDateString(),
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
            ->values();

        return response()->json([
            'data' => $clients,
        ]);
    }
}
