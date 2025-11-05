<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     * @unauthenticated
     *
     * @queryParam per_page integer Number of products per page. Default: 20. Example: 15
     * @queryParam page integer Page number. Example: 1
     * @queryParam search string Search in product name and description. Example: laptop
     * @queryParam category_id integer Filter by category ID. Example: 5
     * @queryParam min_price numeric Filter by minimum price. Example: 100
     * @queryParam max_price numeric Filter by maximum price. Example: 1000
     * @queryParam in_stock boolean Filter products in stock (1) or out of stock (0). Example: 1
     * @queryParam is_active boolean Filter active (1) or inactive (0) products. Example: 1
     * @queryParam sort_by string Sort by field (name, price, created_at, stock). Default: created_at. Example: price
     * @queryParam sort_order string Sort order (asc, desc). Default: desc. Example: asc
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $perPage = min(max((int)$perPage, 1), 100); // Limit between 1 and 100

        $query = Product::with('categories');

        // Search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Category filter
        if ($request->filled('category_id')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('categories.id', $request->input('category_id'));
            });
        }

        // Price range filters
        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->input('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->input('max_price'));
        }

        // Stock filter
        if ($request->has('in_stock')) {
            $inStock = filter_var($request->input('in_stock'), FILTER_VALIDATE_BOOLEAN);
            if ($inStock) {
                $query->where('stock', '>', 0);
            } else {
                $query->where('stock', '<=', 0);
            }
        }

        if ($request->filled('min_stock')) {
            $query->where('stock', '>=', $request->input('min_stock'));
        }

        if ($request->filled('max_stock')) {
            $query->where('stock', '<=', $request->input('max_stock'));
        }

        // Active status filter
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['name', 'price', 'created_at', 'stock', 'updated_at'];
        $allowedSortOrders = ['asc', 'desc'];

        if (in_array($sortBy, $allowedSortFields) && in_array($sortOrder, $allowedSortOrders)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $products = $query->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * Store a newly created product.
     */
    public function store(StoreProductRequest $request)
    {
        $data = $request->only('name', 'description', 'price', 'stock');
        $data['slug'] = \Str::slug($request->name);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $data['image'] = Storage::url($path);
        }

        $product = Product::create($data);

        // Attacher les catégories
        $product->categories()->attach($request->input('category_ids'));

        $product->load('categories');

        return response()->json([
            'message' => 'Product created successfully',
            'data' => new ProductResource($product)
        ], 201);
    }

    /**
     * Display the specified product.
     * @unauthenticated
     */
    public function show(Product $product)
    {
        $product->load('categories');
        return new ProductResource($product);
    }

    /**
     * Update the specified product.
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
        $data = $request->only('name', 'description', 'price', 'stock');

        // Mettre à jour le slug si le nom change
        if ($request->filled('name')) {
            $data['slug'] = \Str::slug($request->name);
        }

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $data['image'] = Storage::url($path);

            Log::info($data['image']);
        }

        $product->update($data);

        if ($request->has('category_ids')) {
            $product->categories()->sync($request->input('category_ids'));
        }

        $product->load('categories');

        return response()->json([
            'message' => 'Product updated successfully',
            'data' => new ProductResource($product)
        ]);
    }

    /**
     * Remove the specified product.
     */
    public function destroy(Product $product)
    {
        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }
}
