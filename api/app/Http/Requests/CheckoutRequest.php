<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'address' => [
                'required',
                'array:recipient_name,cep,street,number,complement,neighborhood,city,state',
            ],
            'address.recipient_name' => ['required', 'string', 'max:150'],
            'address.cep' => ['required', 'string', 'max:9', 'regex:/\A\d{5}-?\d{3}\z/'],
            'address.street' => ['required', 'string', 'max:180'],
            'address.number' => ['required', 'string', 'max:30'],
            'address.complement' => ['nullable', 'string', 'max:120'],
            'address.neighborhood' => ['required', 'string', 'max:120'],
            'address.city' => ['required', 'string', 'max:120'],
            'address.state' => ['required', 'string', 'size:2', 'regex:/\A[A-Za-z]{2}\z/'],
        ];
    }
}
