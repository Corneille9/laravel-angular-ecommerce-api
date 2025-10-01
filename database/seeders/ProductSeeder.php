<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $limit = 30;
        $skip = 0;

        do {
            $response = Http::get("https://dummyjson.com/products?limit={$limit}&skip={$skip}");

            if (!$response->successful()) {
                $this->command->error('Failed to fetch products from DummyJSON.');
                return;
            }

            $data = $response->json();
            $products = $data['products'] ?? [];

            foreach ($products as $item) {
                $category = Category::where('name', ucfirst($item['category']))->first();

                if (!$category) {
                    $category = Category::create([
                        'name' => ucfirst($item['category']),
                        'description' => null,
                        'parent_id' => null,
                    ]);
                }

                Product::updateOrCreate(
                    ['slug' => Str::slug($item['title'])],
                    [
                        'category_id' => $category->id,
                        'name' => $item['title'],
                        'description' => $item['description'],
                        'price' => $item['price'],
                        'stock' => $item['stock'],
                        'image' => $item['thumbnail'] ?? null,
                        'is_active' => true,
                    ]
                );
            }

            $skip += $limit;
        } while ($skip < $data['total']);

        $this->command->info('Products imported successfully from DummyJSON API.');
    }
}
