<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyDemoKeyRequest extends FormRequest
{
    /**
     * Demo verification is an open lookup: the code is the entire
     * credential and revocation is enforced here. Abuse is contained
     * by throttles — a hit only ever returns a signed document the
     * caller must still verify itself.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
        ];
    }
}
