<?php

namespace App\Http\Requests;

use App\Models\Deployment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHeartbeatRequest extends FormRequest
{
    private ?Deployment $resolvedDeployment = null;

    /**
     * The token must belong to the reported deployment, carry the
     * heartbeat ability, and the deployment must not be revoked.
     */
    public function authorize(): bool
    {
        $deployment = $this->deployment();

        if ($deployment === null || $deployment->revoked_at !== null) {
            return false;
        }

        $reporter = $this->user('sanctum');

        return $reporter instanceof Deployment
            && $reporter->is($deployment)
            && $reporter->tokenCan(Deployment::HEARTBEAT_ABILITY);
    }

    /**
     * Deployment identified by the reported uuid, if any.
     */
    public function deployment(): ?Deployment
    {
        if ($this->resolvedDeployment === null && $this->filled('deployment_uuid')) {
            $this->resolvedDeployment = Deployment::where('uuid', $this->input('deployment_uuid'))->first();
        }

        return $this->resolvedDeployment;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'deployment_uuid' => ['required', 'uuid', 'exists:deployments,uuid'],
            'product' => ['required', 'string', Rule::in(Deployment::PRODUCTS)],
            'app_version' => ['required', 'string', 'max:64'],
            'counts' => ['required', 'array:students,teachers,users'],
            'counts.students' => ['required', 'integer', 'min:0'],
            'counts.teachers' => ['required', 'integer', 'min:0'],
            'counts.users' => ['required', 'integer', 'min:0'],
            'modules_in_use' => ['sometimes', 'array'],
            'modules_in_use.*' => ['string', 'max:64'],
        ];
    }
}
