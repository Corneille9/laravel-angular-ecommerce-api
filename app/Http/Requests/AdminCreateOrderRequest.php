<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCreateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization is handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'], // Optional, will use product price if not provided

            // Payment information (optional, can be added later)
            'create_payment' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', 'string', Rule::in(['stripe', 'cash', 'bank_transfer', 'other'])],
            'payment_status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'cancelled', 'refunded'])],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'User is required',
            'user_id.exists' => 'User not found',
            'items.required' => 'Order must have at least one item',
            'items.*.product_id.exists' => 'One or more products not found',
            'items.*.quantity.min' => 'Quantity must be at least 1',
        ];
    }
}

