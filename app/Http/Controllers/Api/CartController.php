<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddToCartRequest;
use App\Http\Requests\RemoveFromCartRequest;
use App\Http\Requests\UpdateCartRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    /**
     * Display the user's cart with items.
     */
    public function index()
    {
        $user = Auth::user();

        $cart = $user->cart;
        $cart?->load('items.product');

        $cart?->items()->whereDoesntHave('product')->delete();

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 200);
        }

        return new CartResource($cart);
    }

    /**
     * Add a product to the cart.
     */
    public function store(AddToCartRequest $request)
    {
        $user = Auth::user();

        // Get or create cart
        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        // Check if item already exists
        $item = $cart->items()->where('product_id', $request->product_id)->first();

        if ($item) {
            $item->quantity += $request->quantity;
            $item->save();
        } else {
            $cart->items()->create([
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
            ]);
        }

        return response()->json([
            'message' => 'Product added to cart successfully',
            'data' => new CartResource($cart->load('items.product'))
        ], 201);
    }

    /**
     * Display a specific cart.
     */
    public function show()
    {
        $user = Auth::user();

        $cart = $user->cart;

        if (!$cart) {
            $cart = Cart::create([
                'user_id' => $user->id,
            ]);
        }

        return new CartResource($cart);
    }

    /**
     * Update a cart item quantity.
     */
    public function update(UpdateCartRequest $request)
    {
        $user = Auth::user();

        $cart = $user->cart;

        if (!$cart) {
            return response()->json(['message' => 'Cart not found'], 404);
        }

        $item = $cart->items()->where('product_id', $request->product_id)->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found in cart'], 404);
        }

        $item->quantity = $request->quantity;
        $item->save();

        return response()->json([
            'message' => 'Cart item updated successfully',
            'data' => new CartResource($cart->load('items.product'))
        ]);
    }

    /**
     * Remove a product from the cart.
     */
    public function destroy(RemoveFromCartRequest $request)
    {
        $user = Auth::user();

        $cart = $user->cart;

        if (!$cart) {
            return response()->json(['message' => 'Cart not found'], 404);
        }

        $item = $cart->items()->where('product_id', $request->product_id)->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found in cart'], 404);
        }

        $item->delete();

        return response()->json([
            'message' => 'Item removed from cart successfully',
            'data' => new CartResource($cart->load('items.product'))
        ]);
    }
}
