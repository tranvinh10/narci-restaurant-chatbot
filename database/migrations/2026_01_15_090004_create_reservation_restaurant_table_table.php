<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng pivot reservation_restaurant_table: 1 đơn đặt bàn có thể dùng nhiều bàn (Quy tắc A.2).
     * Join bảng này với reservations là nơi Bước 2 sẽ kiểm tra xung đột thời gian (Quy tắc A.4).
     */
    public function up(): void
    {
        Schema::create('reservation_restaurant_table', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')
                ->constrained('reservations')
                ->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')
                ->constrained('restaurant_tables')
                ->restrictOnDelete();
            $table->timestamps();

            // Không cho gán trùng 1 bàn 2 lần vào cùng 1 đơn
            $table->unique(['reservation_id', 'restaurant_table_id'], 'resv_table_unique');
            $table->index('restaurant_table_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_restaurant_table');
    }
};
