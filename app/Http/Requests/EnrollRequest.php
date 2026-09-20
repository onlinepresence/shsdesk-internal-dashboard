<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EnrollRequest extends FormRequest
{
    /**
     * The claim code itself is the credential — no prior auth exists
     * on this endpoint. Abuse is contained by throttles plus the
     * per-code failure budget.
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
            'deployment_uuid' => ['nullable', 'uuid', 'exists:deployments,uuid'],
            'app_version' => ['nullable', 'string', 'max:64'],
        ];
    }
}
