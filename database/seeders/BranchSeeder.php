<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\RestaurantTable;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Mỗi chi nhánh được tạo kèm 8 bàn mẫu: 2 bàn-2, 2 bàn-4, 1 bàn-6,
     * 2 ghế quầy bếp mở, 1 bàn khu yên tĩnh — đủ để test việc ghép bàn
     * và kiểm tra bàn trống ở các bước sau.
     */
    public function run(): void
    {
        $branches = [
            [
                'name' => "Narci Restaurant - Quận 1",
                'slug' => 'quan-1',
                'city' => 'TP.HCM',
                'address' => '68 Nguyễn Huệ, Quận 1, TP.HCM',
                'phone' => '0281234001',
            ],
            [
                'name' => "Narci Restaurant - Nha Trang",
                'slug' => 'nha-trang',
                'city' => 'TP. Nha Trang',
                'address' => '24 Nguyễn Chánh, Lộc Thọ, TP. Nha Trang',
                'phone' => '0258234002',
            ],
            [
                'name' => "Narci Restaurant - Đà Lạt",
                'slug' => 'da-lat',
                'city' => 'TP. Đà Lạt',
                'address' => '25 Khu Hòa Bình, Xuân Hương, TP. Đà Lạt',
                'phone' => '0263234003',
            ],
        ];

        $tableTemplate = [
            ['table_code' => 'B2-01', 'capacity' => 2, 'seating_type' => 'regular'],
            ['table_code' => 'B2-02', 'capacity' => 2, 'seating_type' => 'regular'],
            ['table_code' => 'B4-01', 'capacity' => 4, 'seating_type' => 'regular'],
            ['table_code' => 'B4-02', 'capacity' => 4, 'seating_type' => 'regular'],
            ['table_code' => 'B6-01', 'capacity' => 6, 'seating_type' => 'regular'],
            ['table_code' => 'QUAY-01', 'capacity' => 2, 'seating_type' => 'open_kitchen_counter'],
            ['table_code' => 'QUAY-02', 'capacity' => 2, 'seating_type' => 'open_kitchen_counter'],
            ['table_code' => 'YT-01', 'capacity' => 4, 'seating_type' => 'quiet_zone'],
        ];

        foreach ($branches as $branchData) {
            $branch = Branch::create($branchData);

            foreach ($tableTemplate as $table) {
                RestaurantTable::create($table + ['branch_id' => $branch->id]);
            }
        }
    }
}
