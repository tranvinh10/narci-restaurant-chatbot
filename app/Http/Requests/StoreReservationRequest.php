<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // chưa có auth khách hàng, ai cũng đặt được
    }

    protected function prepareForValidation(): void
    {
        // Chuẩn hoá "19:00" (input type=time) thành "19:00:00" để khớp cột TIME trong MySQL
        if ($this->start_time && strlen($this->start_time) === 5) {
            $this->merge(['start_time' => $this->start_time . ':00']);
        }
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'customer_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9,10}$/'],
            'email' => ['nullable', 'email'],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'party_size' => ['required', 'integer', 'min:1', 'max:20'],
            'seating_preference' => ['nullable', 'in:regular,open_kitchen_counter,quiet_zone,any'],
            'occasion' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
