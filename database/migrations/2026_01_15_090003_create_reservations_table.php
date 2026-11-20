<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng reservations: đơn đặt bàn.
     * - end_time lưu sẵn (start_time + 90 phút, tính ở Service tầng Bước 2) để truy vấn
     *   xung đột thời gian nhanh hơn, không phải tính lại mỗi lần check.
     * - status 'cancelled' sẽ bị loại khi kiểm tra bàn đang được sử dụng (Quy tắc A.6).
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 20)->unique(); // VD: RES-7F3K
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->string('customer_name');
            $table->string('phone', 20);
            $table->string('email')->nullable();
            $table->date('reservation_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('party_size');
            $table->enum('seating_preference', [
                'regular',
                'open_kitchen_counter',
                'quiet_zone',
                'any',
            ])->default('any');
            $table->string('occasion')->nullable();   // VD: "Sinh nhật"
            $table->text('notes')->nullable();          // ghi chú / dị ứng khách tự khai thêm
            $table->enum('status', [
                'pending',
                'confirmed',
                'seated',
                'cancelled',
            ])->default('confirmed');
            $table->enum('source', ['website', 'ai_chatbot', 'admin'])->default('website');
            $table->timestamps();

            // Tăng tốc truy vấn kiểm tra bàn trống theo chi nhánh + ngày
            $table->index(['branch_id', 'reservation_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
