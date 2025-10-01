<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    /**
     * Display the user's cart with items.
     */
    public function index()
    {
        $user = Auth::user();

        $cart = Cart::with('items.product')
            ->where('user_id', $user->id)
            ->first();

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 200);
        }

        return response()->json($cart);
    }

    /**
     * Add a product to the cart.
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

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
            'data' => $cart->load('items.product')
        ], 201);
    }

    /**
     * Display a specific cart.
     */
    public function show($id)
    {
        $user = Auth::user();

        $cart = Cart::with('items.product')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$cart) {
            return response()->json(['message' => 'Cart not found'], 404);
        }

        return response()->json($cart);
    }

    /**
     * Update a cart item quantity.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $user = Auth::user();

        $cart = Cart::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

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
            'data' => $cart->load('items.product')
        ]);
    }

    /**
     * Remove a product from the cart.
     */
    public function destroy(Request $request, $id)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $user = Auth::user();

        $cart = Cart::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

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
            'data' => $cart->load('items.product')
        ]);
    }
}
