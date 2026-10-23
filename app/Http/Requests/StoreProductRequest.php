<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['required', 'integer', 'min:0'],
            'unit' => ['required', 'string', 'max:32'],
            'original_price' => ['required', 'numeric', 'min:0'],
            'discount_price' => ['nullable', 'numeric', 'min:0', 'lte:original_price'],
            'expiration_date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:active,discounted,reserved_donation,donated,disposed'],
            'batches' => ['sometimes', 'array', 'min:1'],
            'batches.*.batch_code' => ['nullable', 'string', 'max:100'],
            'batches.*.quantity' => ['required_with:batches', 'integer', 'min:0'],
            'batches.*.expiration_date' => ['required_with:batches', 'date'],
        ];
    }

    /**
     * Custom attribute names for validation errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'category',
            'sku' => 'SKU',
            'original_price' => 'original price',
            'discount_price' => 'discount price',
            'expiration_date' => 'expiration date',
        ];
    }
}
