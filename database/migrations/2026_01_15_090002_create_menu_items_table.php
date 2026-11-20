<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bảng menu_items: toàn bộ món ăn / đồ uống.
     * - allergens lưu JSON để quản lý rõ ràng (Quy tắc D.1) — AI không được tự suy luận dị ứng.
     * - allow_half_half chỉ bật cho các món Pizza có thể ghép 2 vị.
     */
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->enum('category', [
                'pizza',
                'pasta',
                'appetizer',
                'cheese',    // Phô mai tự làm
                'dessert',
                'drink',
            ]);
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->boolean('is_vegetarian')->default(false);
            $table->boolean('is_spicy')->default(false);
            $table->boolean('contains_seafood')->default(false);
            $table->json('allergens')->nullable();     // VD: ["dairy","gluten","shellfish","nuts"]
            $table->boolean('allow_half_half')->default(false);
            $table->string('image_url')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
