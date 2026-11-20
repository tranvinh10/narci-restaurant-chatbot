<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /**
     * GET /menu — trả JSON danh sách món, dùng chung cho:
     * - Bước 4: AJAX lọc món theo category / chay / không cay / không hải sản
     * - Bước 3: tool search_menu của AI chatbot
     */
    public function index(Request $request): JsonResponse
    {
        $query = MenuItem::query()
            ->where('is_available', true)
            ->category($request->query('category'));

        if ($request->boolean('vegetarian')) {
            $query->vegetarian();
        }
        if ($request->boolean('no_spicy')) {
            $query->notSpicy();
        }
        if ($request->boolean('no_seafood')) {
            $query->noSeafood();
        }
        if ($keyword = $request->query('keyword')) {
            $query->where('name', 'like', "%{$keyword}%");
        }

        return response()->json($query->orderBy('category')->orderBy('name')->get());
    }
}
