<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Notifications\OrderPaidNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class CheckoutController extends Controller
{
    public function __construct()
    {
        // Initialize Stripe with your secret key
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Process checkout and create Stripe payment link
     * @throws \Throwable
     */
    public function processCheckout(Request $request)
    {
        $validator = Validator::make($request->all(), [
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

            $user = auth()->user();
            $cart = $user->cart;

            // Vérifier que le panier existe
            if (!$cart) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart not found'
                ], 404);
            }

            // Charger les items du panier avec les produits
            $cart->load('items.product');

            // Vérifier que le panier n'est pas vide
            if ($cart->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart is empty'
                ], 400);
            }

            // Calculer le total et vérifier le stock
            $total = 0;
            $lineItems = [];

            foreach ($cart->items as $item) {
                // Vérifier que le produit est actif
                if (!$item->product->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => "Product '{$item->product->name}' is no longer available"
                    ], 400);
                }

                // Vérifier le stock disponible
                if ($item->product->stock < $item->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient stock for '{$item->product->name}'. Available: {$item->product->stock}, Requested: {$item->quantity}"
                    ], 400);
                }

                $itemTotal = $item->product->price * $item->quantity;
                $total += $itemTotal;

                // Préparer les line items pour Stripe
                $lineItems[] = [
                    'price_data' => [
                        'currency' => config('services.stripe.currency', 'usd'),
                        'product_data' => [
                            'name' => $item->product->name,
                            'description' => $item->product->description ?? '',
                        ],
                        'unit_amount' => (int)($item->product->price * 100), // Stripe utilise les centimes
                    ],
                    'quantity' => $item->quantity,
                ];
            }

            // Créer la commande
            $order = Order::create([
                'user_id' => $user->id,
                'total' => $total,
                'status' => 'pending',
            ]);

            // Créer les items de commande et mettre à jour le stock
            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->product->price,
                ]);

                // Décrémenter le stock du produit
                $item->product->decrement('stock', $item->quantity);
            }

            // Créer le paiement (en attente)
            $payment = Payment::create([
                'order_id' => $order->id,
                'amount' => $total,
                'payment_method' => 'stripe',
                'status' => 'pending',
            ]);

            // Créer une session Stripe Checkout
            $checkoutSession = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => config('app.frontend_url') . '/checkout/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => config('app.frontend_url') . '/checkout/cancel',
                'customer_email' => $user->email,
                'metadata' => [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'payment_id' => $payment->id,
                ],
            ]);

            // Mettre à jour le paiement avec les informations Stripe
            $payment->update([
                'stripe_checkout_session_id' => $checkoutSession->id,
                'stripe_checkout_url' => $checkoutSession->url,
            ]);

            // Vider le panier
            $cart->items()->delete();
            $cart->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'order_id' => $order->id,
                    'payment_url' => $checkoutSession->url,
                    'total' => $total,
                ]
            ], 201);

        } catch (\Stripe\Exception\CardException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Card error: ' . $e->getError()->message
            ], 400);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Invalid payment request: ' . $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Checkout failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify payment after Stripe redirect
     * @throws \Throwable
     */
    public function verifyPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Récupérer la session Stripe
            $session = Session::retrieve($request->session_id);

            // Vérifier que la session existe et est payée
            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not completed'
                ], 400);
            }

            // Récupérer le paiement correspondant
            $payment = Payment::where('stripe_checkout_session_id', $session->id)->first();

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found'
                ], 404);
            }

            // Vérifier que le paiement appartient à l'utilisateur connecté
            if ($payment->order->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            DB::transaction(function () use ($payment, $session) {
                $payment->update([
                    'status' => 'completed',
                    'stripe_payment_intent_id' => $session->payment_intent,
                ]);

                $payment->order->update([
                    'status' => 'paid',
                ]);

                // Send order confirmation email
                $payment->order->user->notify(new OrderPaidNotification($payment->order));
            });

            return response()->json([
                'success' => true,
                'message' => 'Payment verified successfully',
                'data' => [
                    'order' => $payment->order->load(['items.product', 'payment'])
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get checkout summary for current user's cart
     */
    public function getCheckoutSummary()
    {
        try {
            $user = auth()->user();
            $cart = $user->cart;

            if (!$cart) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart not found'
                ], 404);
            }

            $cart->load('items.product');

            if ($cart->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart is empty'
                ], 400);
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

            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $items,
                    'total' => round($subtotal, 2),
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
