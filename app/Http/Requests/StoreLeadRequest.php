<?php

namespace App\Http\Requests;

use App\Models\Feature;
use App\Models\Setting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    /**
     * The leads endpoint authenticates nothing — the product slug only
     * identifies which registry row the quote belongs to. Abuse is
     * contained by strict validation, throttles, and dedupe; spam
     * lands in the review queue, never as access.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Note: the `array:` key lists are exact — this Laravel version
     * rejects unlisted keys, so every field the sender may include
     * must be named here and below. Unknown keys 422 instead of
     * slipping into storage.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_slug' => ['required', 'string', 'max:64'],
            'contact' => ['required', 'array:name,email,role,phone,school,college'],
            'contact.name' => ['required', 'string', 'max:255'],
            'contact.email' => ['required', 'email:rfc', 'max:255'],
            'contact.role' => ['nullable', 'string', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'contact.school' => ['nullable', 'string', 'max:255'],
            'contact.college' => ['nullable', 'string', 'max:255'],
            'band' => ['required', 'string', Rule::in(array_column(Setting::corePricing(), 'key'))],
            'modules' => ['required', 'array'],
            'modules.*' => ['string', Rule::in(array_keys(Feature::FLOWEDU_MODULE_MAP))],
            'quote' => ['required', 'array:upfront,renewal,lines'],
            'quote.upfront' => ['required', 'numeric', 'min:0'],
            'quote.renewal' => ['required', 'numeric', 'min:0'],
            'quote.lines' => ['required', 'array'],
            'quote.lines.*' => ['array:label,amount'],
            'quote.lines.*.label' => ['required', 'string', 'max:255'],
            'quote.lines.*.amount' => ['required', 'numeric'],
        ];
    }
}
