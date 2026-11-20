<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string for GET /tickets. Lists accept comma-separated values,
 * e.g. ?status=open,in_progress&priority=high,urgent&sort=-priority
 */
class ListTicketsRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at', 'updated_at', '-updated_at', 'priority', '-priority'];

    protected function prepareForValidation(): void
    {
        foreach (['status', 'priority'] as $key) {
            if (is_string($this->query($key))) {
                $this->merge([$key => array_values(array_filter(array_map('trim', explode(',', $this->query($key)))))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', 'array'],
            'priority.*' => [Rule::enum(TicketPriority::class)],
            // Technicians: "me" (assigned to me), "none" (unassigned queue).
            'assignee' => ['sometimes', 'string', 'in:me,none'],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
