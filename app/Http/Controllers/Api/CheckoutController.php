<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class CheckoutController extends Controller
{
    public function __construct()
    {
        // Initialize Stripe with your secret key
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Process checkout from cart
     * @throws \Throwable
     */
    public function processCheckout(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cart_id' => 'required|exists:carts,id',
            'payment_method' => 'required|in:offline,stripe',
            'shipping_address_id' => 'required|exists:addresses,id',
            'billing_address_id' => 'nullable|exists:addresses,id',
            'payment_method_id' => 'required_if:payment_method,stripe|string',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Get cart with items
            $cart = Cart::with(['items.product', 'user'])->findOrFail($request->cart_id);

            // Verify cart belongs to authenticated user
            if ($cart->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to cart'
                ], 403);
            }

            // Check if cart is empty
            if ($cart->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart is empty'
                ], 400);
            }

            // Verify addresses belong to user
            $shippingAddress = Address::where('id', $request->shipping_address_id)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            $billingAddress = $request->billing_address_id
                ? Address::where('id', $request->billing_address_id)
                    ->where('user_id', auth()->id())
                    ->firstOrFail()
                : $shippingAddress;

            // Calculate total and verify stock
            $total = 0;
            foreach ($cart->items as $item) {
                if (!$item->product->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => "Product {$item->product->name} is no longer available"
                    ], 400);
                }

                if ($item->product->stock < $item->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient stock for {$item->product->name}"
                    ], 400);
                }

                $total += $item->product->price * $item->quantity;
            }

            // Create order
            $order = Order::create([
                'user_id' => auth()->id(),
                'total' => $total,
                'status' => 'pending',
            ]);

            // Create order items and update stock
            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->product->price,
                ]);

                // Decrease product stock
                $item->product->decrement('stock', $item->quantity);
            }

            // Process payment
            if ($request->payment_method === 'offline') {
                $paymentResult = $this->processOfflinePayment($order, $total);
            } else {
                $paymentResult = $this->processStripePayment($order, $total, $request->payment_method_id);
            }

            if (!$paymentResult['success']) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => $paymentResult['message']
                ], 400);
            }

            // Update order status based on payment
            $order->update([
                'status' => $paymentResult['order_status']
            ]);

            // Clear cart
            $cart->items()->delete();
            $cart->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully',
                'data' => [
                    'order' => $order->load(['items.product', 'payment']),
                    'payment' => $paymentResult['payment_data'] ?? null
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Checkout failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process offline payment
     */
    private function processOfflinePayment(Order $order, $amount)
    {
        try {
            $payment = Payment::create([
                'order_id' => $order->id,
                'amount' => $amount,
                'payment_method' => 'offline',
                'status' => 'pending',
            ]);

            return [
                'success' => true,
                'order_status' => 'pending',
                'payment_data' => $payment
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to process offline payment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process Stripe payment
     */
    private function processStripePayment(Order $order, $amount, $paymentMethodId)
    {
        try {
            // Create payment intent
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount * 100, // Stripe uses cents
                'currency' => config('services.stripe.currency', 'usd'),
                'payment_method' => $paymentMethodId,
                'confirmation_method' => 'manual',
                'confirm' => true,
                'return_url' => config('app.url') . '/payment/return',
                'metadata' => [
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                ]
            ]);

            // Check payment status
            if ($paymentIntent->status === 'requires_action' &&
                $paymentIntent->next_action->type === 'use_stripe_sdk') {

                // 3D Secure authentication required
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'amount' => $amount,
                    'payment_method' => 'stripe',
                    'status' => 'requires_action',
                ]);

                return [
                    'success' => true,
                    'order_status' => 'pending',
                    'payment_data' => [
                        'payment' => $payment,
                        'requires_action' => true,
                        'payment_intent_client_secret' => $paymentIntent->client_secret
                    ]
                ];
            }

            if ($paymentIntent->status === 'succeeded') {
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'amount' => $amount,
                    'payment_method' => 'stripe',
                    'status' => 'completed',
                ]);

                return [
                    'success' => true,
                    'order_status' => 'processing',
                    'payment_data' => $payment
                ];
            }

            // Payment failed
            return [
                'success' => false,
                'message' => 'Payment failed'
            ];

        } catch (\Stripe\Exception\CardException $e) {
            return [
                'success' => false,
                'message' => 'Card error: ' . $e->getError()->message
            ];
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            return [
                'success' => false,
                'message' => 'Invalid payment request: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Payment processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Confirm Stripe payment (for 3D Secure)
     */
    public function confirmStripePayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'payment_intent_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $order = Order::where('id', $request->order_id)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);

            if ($paymentIntent->status === 'succeeded') {
                $order->payment()->update(['status' => 'completed']);
                $order->update(['status' => 'processing']);

                return response()->json([
                    'success' => true,
                    'message' => 'Payment confirmed successfully',
                    'data' => $order->load(['payment', 'items.product'])
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Payment not completed'
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment confirmation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get checkout summary
     */
    public function getCheckoutSummary(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cart_id' => 'required|exists:carts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cart = Cart::with(['items.product'])->findOrFail($request->cart_id);

            if ($cart->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to cart'
                ], 403);
            }

            $subtotal = 0;
            $items = [];

            foreach ($cart->items as $item) {
                $itemTotal = $item->product->price * $item->quantity;
                $subtotal += $itemTotal;

                $items[] = [
                    'product_id' => $item->product->id,
                    'name' => $item->product->name,
                    'price' => $item->product->price,
                    'quantity' => $item->quantity,
                    'total' => $itemTotal,
                    'in_stock' => $item->product->stock >= $item->quantity,
                    'available_stock' => $item->product->stock,
                ];
            }

            $shipping = 0; // Calculate based on your shipping rules
            $tax = $subtotal * 0.1; // 10% tax example
            $total = $subtotal + $shipping + $tax;

            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $items,
                    'subtotal' => round($subtotal, 2),
                    'shipping' => round($shipping, 2),
                    'tax' => round($tax, 2),
                    'total' => round($total, 2),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get checkout summary: ' . $e->getMessage()
            ], 500);
        }
    }
}
