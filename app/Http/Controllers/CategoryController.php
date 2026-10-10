<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = DB::table('category')->orderBy('name', 'asc')->get();

        return response()->json($categories, 200);
    }
}
