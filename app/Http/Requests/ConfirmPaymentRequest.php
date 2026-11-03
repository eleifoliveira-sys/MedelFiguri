<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'card_number' => ['prohibited'],
            'cardholder_name' => ['prohibited'],
            'expiration_date' => ['prohibited'],
            'cvv' => ['prohibited'],
            'security_code' => ['prohibited'],
            'bank_password' => ['prohibited'],
        ];
    }
}
