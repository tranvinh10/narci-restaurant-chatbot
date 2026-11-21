<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng branches: thông tin từng chi nhánh nhà hàng.
     * - opening_time / closing_time dùng để validate giờ hoạt động khi đặt bàn (Quy tắc A.7).
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');                   // VD: "Narci-Restaurant - Quận 1"
            $table->string('slug')->unique();          // VD: "quan-1"
            $table->string('city');                    // VD: "TP.HCM", "Hà Nội"
            $table->string('address');
            $table->string('phone', 20)->nullable();
            $table->time('opening_time')->default('10:00:00');
            $table->time('closing_time')->default('22:00:00');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
