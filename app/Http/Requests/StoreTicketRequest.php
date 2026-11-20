<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    // Authorisation runs before validation: a forbidden user gets 403, never field errors.
    public function authorize(): bool
    {
        Gate::authorize('create', Ticket::class);

        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
        ];
    }
}
