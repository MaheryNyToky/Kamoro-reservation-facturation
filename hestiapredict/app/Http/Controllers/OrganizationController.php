<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:120',
        ]);

        $term = trim($validated['q']);
        $normalized = Str::lower(Str::ascii($term));
        $searchDigits = preg_replace('/\D+/', '', $term);

        $organizations = Organization::query()
            ->where(function ($query) use ($normalized, $searchDigits) {
                $nameLike = '%' . $normalized . '%';
                $prefixLike = $searchDigits !== '' ? $searchDigits . '%' : null;

                $query->whereRaw('LOWER(name) LIKE ?', [$nameLike])
                    ->orWhereRaw('LOWER(contact_name) LIKE ?', [$nameLike])
                    ->orWhereRaw('LOWER(contact_email) LIKE ?', [$nameLike])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$nameLike]);

                if ($prefixLike !== null) {
                    $query->orWhereRaw('LOWER(phone) LIKE ?', [$prefixLike])
                        ->orWhereRaw('LOWER(contact_phone) LIKE ?', [$prefixLike])
                        ->orWhereRaw('LOWER(nif) LIKE ?', [$prefixLike])
                        ->orWhereRaw('LOWER(tax_id) LIKE ?', [$prefixLike])
                        ->orWhereRaw('LOWER(stat) LIKE ?', [$prefixLike]);
                }
            })
            ->limit(100)
            ->get()
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'phone' => $organization->phone,
                'contact_name' => $organization->contact_name,
                'contact_phone' => $organization->contact_phone,
                'contact_email' => $organization->contact_email,
                'email' => $organization->email,
                'billing_address' => $organization->billing_address,
                'nif' => $organization->nif ?? $organization->tax_id,
                'stat' => $organization->stat,
                'tax_id' => $organization->tax_id,
            ])
            ->sortByDesc(fn (array $organization) => $this->searchScore($organization, $normalized, $searchDigits))
            ->take(20)
            ->values();

        return response()->json(['data' => $organizations]);
    }

    private function searchScore(array $organization, string $normalized, string $searchDigits): int
    {
        $score = 0;
        $name = Str::lower(Str::ascii(trim((string) ($organization['name'] ?? ''))));
        $contactName = Str::lower(Str::ascii(trim((string) ($organization['contact_name'] ?? ''))));
        $contactEmail = Str::lower(Str::ascii(trim((string) ($organization['contact_email'] ?? ''))));
        $email = Str::lower(Str::ascii(trim((string) ($organization['email'] ?? ''))));
        $phone = preg_replace('/\D+/', '', (string) ($organization['phone'] ?? ''));
        $contactPhone = preg_replace('/\D+/', '', (string) ($organization['contact_phone'] ?? ''));
        $nif = Str::lower(Str::ascii(trim((string) ($organization['nif'] ?? ''))));
        $taxId = Str::lower(Str::ascii(trim((string) ($organization['tax_id'] ?? ''))));
        $stat = Str::lower(Str::ascii(trim((string) ($organization['stat'] ?? ''))));

        foreach ([$name, $contactName] as $field) {
            if ($field === '') {
                continue;
            }

            if ($field === $normalized) {
                $score = max($score, 100);
                continue;
            }

            if ($normalized !== '' && Str::startsWith($field, $normalized)) {
                $score = max($score, 90);
                continue;
            }

            if ($normalized !== '' && Str::contains($field, $normalized)) {
                $score = max($score, 70);
            }
        }

        foreach ([$contactEmail, $email, $nif, $taxId, $stat] as $field) {
            if ($field !== '' && $normalized !== '' && Str::contains($field, $normalized)) {
                $score = max($score, 60);
            }
        }

        if ($searchDigits !== '') {
            foreach ([$phone, $contactPhone, $nif, $taxId, $stat] as $field) {
                if ($field !== '' && Str::startsWith($field, $searchDigits)) {
                    $score = max($score, 95);
                }
            }
        }

        return $score;
    }
}
