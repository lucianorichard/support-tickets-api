<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Field-level permissions: requesters edit the text, technicians set the priority. */
class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('ticket'));

        return true;
    }

    public function rules(): array
    {
        if ($this->user()->isTechnician()) {
            return [
                'priority' => ['required', Rule::enum(TicketPriority::class)],
                'title' => ['prohibited'],
                'description' => ['prohibited'],
            ];
        }

        return [
            'title' => ['required_without:description', 'string', 'min:5', 'max:150'],
            'description' => ['required_without:title', 'string', 'max:5000'],
            'priority' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['*.prohibited' => 'Your role cannot change the :attribute field.'];
    }
}
