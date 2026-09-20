<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class LeadsController extends Controller
{
    /**
     * Queue a sales lead from a product quote form. Same email on the
     * same product within 30 days refreshes the open lead instead of
     * opening a duplicate.
     */
    public function __invoke(StoreLeadRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $product = Product::where('slug', $validated['product_slug'])->first();

        if ($product === null || ! $product->isActive()) {
            activity('leads')->withProperties([
                'reason' => $product === null ? 'unknown_product' : 'product_inactive',
                'product_slug' => $validated['product_slug'],
            ])->log('lead.rejected');

            abort(404, 'Unknown or inactive product.');
        }

        $attributes = [
            'product_id' => $product->id,
            'contact_name' => $validated['contact']['name'],
            'contact_email' => $validated['contact']['email'],
            'contact_phone' => $validated['contact']['phone'] ?? null,
            'school' => $validated['contact']['school'] ?? null,
            'band' => $validated['band'],
            'modules' => array_values($validated['modules']),
            'quote_upfront' => $validated['quote']['upfront'],
            'quote_renewal' => $validated['quote']['renewal'],
            'quote_lines' => array_values($validated['quote']['lines']),
        ];

        $lead = Lead::query()
            ->where('product_id', $product->id)
            ->where('contact_email', $attributes['contact_email'])
            ->where('created_at', '>=', now()->subDays(Lead::DEDUPE_DAYS))
            ->latest()
            ->first();

        if ($lead !== null) {
            $lead->update(array_merge($attributes, ['status' => Lead::STATUS_NEW]));

            activity('leads')
                ->performedOn($lead)
                ->log('lead.updated');

            return response()->json(['message' => 'Lead updated.', 'deduped' => true], 200);
        }

        $lead = Lead::query()->create(array_merge($attributes, ['status' => Lead::STATUS_NEW]));

        activity('leads')
            ->performedOn($lead)
            ->log('lead.received');

        return response()->json(['message' => 'Lead received.', 'deduped' => false], 201);
    }
}
