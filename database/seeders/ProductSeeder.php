<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $limit = 100;
        $skip = 0;

        do {
            $response = Http::get("https://dummyjson.com/products?limit={$limit}&skip={$skip}");

            if (!$response->successful()) {
                $this->command->error('Failed to fetch products from DummyJSON.');
                return;
            }

            $data = $response->json();
            $products = $data['products'] ?? [];

            $products = collect($products)->shuffle()->toArray();

            foreach ($products as $item) {
                $category = Category::where('name', ucfirst($item['category']))->first();

                if (!$category) {
                    $category = Category::create([
                        'name' => ucfirst($item['category']),
                        'description' => null,
                        'parent_id' => null,
                    ]);
                }

                $product = Product::updateOrCreate(
                    ['slug' => Str::slug($item['title'])],
                    [
                        'name' => $item['title'],
                        'description' => $item['description'],
                        'price' => $item['price'],
                        'stock' => $item['stock'],
                        'image' => $item['thumbnail'] ?? null,
                        'is_active' => true,
                    ]
                );

                // Attacher la catégorie au produit si elle n'est pas déjà attachée
                if (!$product->categories->contains($category->id)) {
                    $product->categories()->attach($category->id);
                }
            }

            $skip += $limit;
        } while ($skip < $data['total']);

        $this->command->info('Products imported successfully from DummyJSON API.');
    }
}
