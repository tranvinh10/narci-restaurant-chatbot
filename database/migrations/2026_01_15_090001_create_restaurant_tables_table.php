<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng restaurant_tables: danh sách bàn vật lý của từng chi nhánh.
     * Quy tắc A.1: mỗi bàn thuộc về đúng 1 chi nhánh, có sức chứa cụ thể.
     */
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();
            $table->string('table_code', 20);            // VD: "B4-01", "QUAY-02"
            $table->unsignedTinyInteger('capacity');       // số khách tối đa
            $table->enum('seating_type', [
                'regular',               // Bàn thường
                'open_kitchen_counter',  // Quầy bếp mở
                'quiet_zone',            // Khu yên tĩnh
            ])->default('regular');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Không cho trùng mã bàn trong cùng 1 chi nhánh
            $table->unique(['branch_id', 'table_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
