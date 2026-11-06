<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminCategoryService
{
    public function list(): LengthAwarePaginator
    {
        return Category::query()->withCount('products')->orderBy('name')->paginate(30);
    }

    public function find(int $id): Category
    {
        return Category::query()->withCount('products')->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Category
    {
        return Category::query()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): Category
    {
        return DB::transaction(function () use ($id, $data): Category {
            $category = Category::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
                $this->assertParentChainIsValid($category, (int) $data['parent_id']);
            }

            $category->update($data);

            return $category->refresh()->loadCount('products');
        }, 3);
    }

    public function archive(int $id): Category
    {
        $category = Category::query()->findOrFail($id);
        $category->update(['is_active' => false]);

        return $category->refresh()->loadCount('products');
    }

    private function assertParentChainIsValid(Category $category, int $parentId): void
    {
        $visited = [$category->id => true];
        $currentId = $parentId;

        while ($currentId !== null) {
            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A categoria não pode ser subordinada a si própria ou a uma descendente.'],
                ]);
            }

            $visited[$currentId] = true;
            $currentId = Category::query()->whereKey($currentId)->value('parent_id');
        }
    }
}
