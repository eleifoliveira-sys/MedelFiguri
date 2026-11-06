<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminProductService
{
    public function list(): LengthAwarePaginator
    {
        return Product::query()->with(['categories', 'images'])->orderBy('name')->paginate(20);
    }

    public function find(int $id): Product
    {
        return Product::query()->with(['categories', 'images'])->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $admin, InventoryService $inventory): Product
    {
        return DB::transaction(function () use ($data, $admin, $inventory): Product {
            $categoryIds = $data['category_ids'] ?? [];
            $initialStock = (int) $data['stock_quantity'];
            unset($data['category_ids'], $data['stock_quantity']);

            $product = Product::query()->create([...$data, 'status' => 'inactive', 'stock_quantity' => 0]);
            $product->categories()->sync($categoryIds);

            if ($initialStock > 0) {
                $inventory->adjustStock(
                    $product,
                    $initialStock,
                    'Estoque inicial do produto',
                    $admin,
                );
            }

            return $product->refresh()->load(['categories', 'images']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): Product
    {
        return DB::transaction(function () use ($id, $data): Product {
            $product = Product::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $categoryIds = $data['category_ids'] ?? null;
            unset($data['category_ids'], $data['stock_quantity']);
            $product->loadMissing('images');
            if (($data['status'] ?? $product->status) === 'active' && ! $product->hasAvailable3dModel()) {
                $data['status'] = 'inactive';
            }
            $product->update($data);

            if ($categoryIds !== null) {
                $product->categories()->sync($categoryIds);
            }

            return $product->load(['categories', 'images']);
        }, 3);
    }

    public function archive(int $id): Product
    {
        $product = Product::query()->findOrFail($id);
        $product->update(['status' => 'inactive']);

        return $product->load(['categories', 'images']);
    }

    public function storeImage(
        int $productId,
        UploadedFile $file,
        ?string $altText,
        bool $primary,
    ): ProductImage {
        $product = Product::query()->findOrFail($productId);
        $path = $file->store('products', 'public');

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Não foi possível armazenar a imagem do produto.');
        }

        try {
            return DB::transaction(function () use ($product, $path, $altText, $primary): ProductImage {
                if ($primary) {
                    $product->images()->update(['is_primary' => false]);
                }

                $image = $product->images()->create([
                    'path' => $path,
                    'alt_text' => $altText,
                    'is_primary' => $primary,
                    'sort_order' => (int) $product->images()->max('sort_order') + 1,
                ]);
                $product->unsetRelation('images')->load('images');
                if ($product->status === 'active' && ! $product->hasAvailable3dModel()) {
                    $product->update(['status' => 'inactive']);
                }

                return $image;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
    }

    public function deleteImage(int $productId, int $imageId): void
    {
        $image = ProductImage::query()
            ->where('product_id', $productId)
            ->whereKey($imageId)
            ->firstOrFail();
        $path = $image->path;

        DB::transaction(fn () => $image->delete(), 3);

        $product = Product::query()->with('images')->findOrFail($productId);
        if ($product->status === 'active' && ! $product->hasAvailable3dModel()) {
            $product->update(['status' => 'inactive']);
        }

        if (! Storage::disk('public')->delete($path)) {
            throw new \RuntimeException('A imagem foi desvinculada, mas o arquivo não pôde ser removido.');
        }
    }
}
