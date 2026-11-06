<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class CatalogController extends Controller
{
    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json($categories);
    }

    public function products(): JsonResponse
    {
        $products = Product::query()
            ->where('status', 'active')
            ->whereHas('images', fn ($query) => $query->where(
                fn ($query) => $query
                    ->whereRaw('LOWER(path) LIKE ?', ['%.glb'])
                    ->orWhereRaw('LOWER(path) LIKE ?', ['%.gltf']),
            ))
            ->with([
                'categories' => fn ($query) => $query
                    ->where('categories.is_active', true)
                    ->select('categories.id', 'categories.name', 'categories.slug'),
                'images:id,product_id,path,alt_text,sort_order,is_primary',
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $product): bool => $product->hasAvailable3dModel())
            ->values();

        $perPage = 15;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $products->forPage($page, $perPage)->values(),
            $products->count(),
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );

        return response()->json($paginator);
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->status === 'active', 404);

        $product->load([
            'categories' => fn ($query) => $query
                ->where('categories.is_active', true)
                ->select('categories.id', 'categories.name', 'categories.slug'),
            'images:id,product_id,path,alt_text,sort_order,is_primary',
        ]);
        abort_unless($product->hasAvailable3dModel(), 404);

        return response()->json($product);
    }
}
