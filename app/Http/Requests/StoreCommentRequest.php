<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('comment', $this->route('ticket'));

        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            // Internal notes are a technician tool; requesters cannot create (or see) them.
            'is_internal' => $this->user()->isTechnician() ? ['sometimes', 'boolean'] : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['is_internal.prohibited' => 'Only technicians can write internal notes.'];
    }
}
