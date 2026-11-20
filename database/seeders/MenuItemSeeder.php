<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class MenuItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'category' => 'pizza', 'name' => 'Burrata Parma Ham Pizza',
                'description' => 'Đế bánh mỏng giòn, phô mai Burrata tươi tự làm, Prosciutto di Parma, rau arugula.',
                'price' => 245000, 'is_vegetarian' => false, 'is_spicy' => false, 'contains_seafood' => false,
                'allergens' => ['dairy', 'gluten'], 'allow_half_half' => true,
            ],
            [
                'category' => 'pizza', 'name' => 'Four Cheese Pizza',
                'description' => 'Mozzarella, Gorgonzola, Parmesan và Taleggio tan chảy trên nền sốt kem.',
                'price' => 229000, 'is_vegetarian' => true, 'is_spicy' => false, 'contains_seafood' => false,
                'allergens' => ['dairy', 'gluten'], 'allow_half_half' => true,
            ],
            [
                'category' => 'pizza', 'name' => 'Seafood Spicy Tomato Pizza',
                'description' => 'Tôm, mực, sốt cà chua cay kiểu Ý, ớt khô Calabria.',
                'price' => 259000, 'is_vegetarian' => false, 'is_spicy' => true, 'contains_seafood' => true,
                'allergens' => ['shellfish', 'gluten'], 'allow_half_half' => true,
            ],
            [
                'category' => 'pizza', 'name' => 'Margherita Pizza (Chay)',
                'description' => 'Sốt cà chua San Marzano, Mozzarella tươi, lá húng quế.',
                'price' => 189000, 'is_vegetarian' => true, 'is_spicy' => false, 'contains_seafood' => false,
                'allergens' => ['dairy', 'gluten'], 'allow_half_half' => true,
            ],
            [
                'category' => 'pasta', 'name' => 'Crab Tomato Cream Spaghetti',
                'description' => 'Mì Ý sốt kem cà chua, thịt cua tươi, chút rượu trắng.',
                'price' => 219000, 'is_vegetarian' => false, 'is_spicy' => false, 'contains_seafood' => true,
                'allergens' => ['shellfish', 'dairy', 'gluten'], 'allow_half_half' => false,
            ],
            [
                'category' => 'cheese', 'name' => 'House-made Cheese Platter',
                'description' => 'Khay phô mai tự làm: Burrata, Mozzarella, Camembert kèm mật ong.',
                'price' => 285000, 'is_vegetarian' => true, 'is_spicy' => false, 'contains_seafood' => false,
                'allergens' => ['dairy'], 'allow_half_half' => false,
            ],
            [
                'category' => 'appetizer', 'name' => 'Cold Cuts & Olives',
                'description' => 'Prosciutto, Salami Ý, ô liu ngâm dầu olive, bánh mì nướng.',
                'price' => 195000, 'is_vegetarian' => false, 'is_spicy' => false, 'contains_seafood' => false,
                'allergens' => ['gluten'], 'allow_half_half' => false,
            ],
            [
                'category' => 'dessert', 'name' => 'Tiramisu',
                'description' => 'Tiramisu kiểu Ý truyền thống, cà phê espresso, mascarpone.',
                'price' => 89000, 'is_vegetarian' => true, 'is_spicy' => false, 'contains_seafood' => false,
                'allergens' => ['dairy', 'egg', 'gluten'], 'allow_half_half' => false,
            ],
            [
                'category' => 'drink', 'name' => 'Yuzu Craft Beer',
                'description' => 'Bia thủ công vị yuzu (chanh Nhật), chua thanh nhẹ.',
                'price' => 79000, 'is_vegetarian' => true, 'is_spicy' => false, 'contains_seafood' => false,
                'allergens' => [], 'allow_half_half' => false,
            ],
            [
                'category' => 'drink', 'name' => 'Fresh Lemonade',
                'description' => 'Nước chanh tươi, bạc hà, siro đường mía.',
                'price' => 49000, 'is_vegetarian' => true, 'is_spicy' => false, 'contains_seafood' => false,
                'allergens' => [], 'allow_half_half' => false,
            ],
        ];

        foreach ($items as $item) {
            MenuItem::create($item + ['is_available' => true]);
        }
    }
}
