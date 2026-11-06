<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'sku' => ['sometimes', 'required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($id)],
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'slug' => ['sometimes', 'required', 'string', 'max:200', 'alpha_dash', Rule::unique('products', 'slug')->ignore($id)],
            'description' => ['sometimes', 'required', 'string'],
            'price' => ['sometimes', 'required', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'stock_quantity' => ['prohibited'],
            'coloration' => ['sometimes', 'required', 'string', 'in:colorido,sem-cor'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ];
    }
}
