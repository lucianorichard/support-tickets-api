<?php

namespace App\Http\Requests;

use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TransitionTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('transition', $this->route('ticket'));

        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(TicketStatus::class)]];
    }
}
