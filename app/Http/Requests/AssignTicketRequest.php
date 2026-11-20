<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('assign', $this->route('ticket'));

        return true;
    }

    public function rules(): array
    {
        return [
            'assignee_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::Technician->value),
            ],
        ];
    }

    public function messages(): array
    {
        return ['assignee_id.exists' => 'The assignee must be an existing technician.'];
    }
}
