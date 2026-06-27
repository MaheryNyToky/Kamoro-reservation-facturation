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
        $like = '%' . $normalized . '%';

        $organizations = Organization::query()
            ->where(function ($query) use ($like) {
                $query->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(contact_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(contact_phone) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(contact_email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(billing_address) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(nif) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(tax_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(stat) LIKE ?', [$like]);
            })
            ->orderBy('name')
            ->limit(20)
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
            ]);

        return response()->json(['data' => $organizations]);
    }
}
