<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * §4 customer creation rules — mirrored on the frontend by
 * validateCustomer() so both sides reject the same input.
 */
class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('sanctum')->check();
    }

    /**
     * @return array<string, array<int, string|array<int, string>>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9()\- ]{7,30}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'customer_type' => ['required', 'in:retail,wholesale,vip,other'],
            'registration_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.now(DB::table('company_settings')->value('timezone') ?: 'Asia/Kabul')->toDateString()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Customer name is required.',
            'name.min' => 'Customer name must be at least 2 characters.',
            'name.max' => 'Customer name may not exceed 150 characters.',
            'phone.regex' => 'Use digits, spaces, +, - and ( ) only.',
            'phone.max' => 'Phone may not exceed 30 characters.',
            'address.max' => 'Address may not exceed 255 characters.',
            'notes.max' => 'Notes may not exceed 2000 characters.',
            'customer_type.required' => 'Choose a valid customer type.',
            'customer_type.in' =>
                'Choose a valid customer type.',
            'registration_date.required' => 'Registration date is required.',
            'registration_date.date_format' => 'Enter a valid date.',
            'registration_date.after_or_equal' => 'Enter a valid date.',
            'registration_date.before_or_equal' =>
                'Date cannot be in the future.',
            'status.in' => 'Choose a valid status.',
            'status.required' => 'Choose a valid status.',
        ];
    }
}
