<?php

use App\Http\Controllers\MenuController;
use App\Http\Controllers\ReservationController;
use App\Models\Branch;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

// Danh sách chi nhánh — phục vụ dropdown ở form đặt bàn (Bước 4) và AI chatbot (Bước 3)
Route::get('/branches', function () {
    return Branch::where('is_active', true)
        ->get(['id', 'name', 'city', 'opening_time', 'closing_time']);
});

Route::get('/menu', [MenuController::class, 'index'])->name('menu.index');

Route::prefix('reservations')->name('reservations.')->group(function () {
    Route::post('/check-availability', [ReservationController::class, 'checkAvailability'])->name('check');
    Route::post('/', [ReservationController::class, 'store'])->name('store');
});
