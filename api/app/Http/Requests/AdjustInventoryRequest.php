<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'quantity_delta' => ['required', 'integer', 'between:-2147483647,2147483647', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
