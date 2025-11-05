<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminCreateOrderRequest;
use App\Http\Requests\AdminUpdatePaymentRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderPaidNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    /**
     * Display a listing of all orders (admin).
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $perPage = min(max((int)$perPage, 1), 100);

        $query = Order::with(['user', 'items.product']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // Search by order ID
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('id', 'like', "%{$search}%");
        }

        // Date range filters
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['created_at', 'total', 'status'];
        $allowedSortOrders = ['asc', 'desc'];

        if (in_array($sortBy, $allowedSortFields) && in_array($sortOrder, $allowedSortOrders)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $orders = $query->paginate($perPage);

        return OrderResource::collection($orders);
    }

    /**
     * Display the specified order.
     */
    public function show(Order $order)
    {
        $order->load(['user', 'items.product']);
        return new OrderResource($order);
    }

    /**
     * Update the specified order.
     */
    public function update(UpdateOrderRequest $request, Order $order)
    {
        $order->update(['status' => $request->status]);

        return response()->json([
            'message' => 'Order status updated successfully',
            'data' => new OrderResource($order->load(['user', 'items.product']))
        ]);
    }

    /**
     * Remove the specified order.
     */
    public function destroy(Order $order)
    {
        $order->delete();

        return response()->json([
            'message' => 'Order deleted successfully'
        ]);
    }

    /**
     * Get order statistics.
     */
    public function statistics()
    {
        $stats = [
            'total_orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'completed_orders' => Order::where('status', 'completed')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),
            'total_revenue' => Order::where('status', 'completed')->sum('total'),
            'today_orders' => Order::whereDate('created_at', today())->count(),
            'today_revenue' => Order::whereDate('created_at', today())
                ->where('status', 'completed')
                ->sum('total'),
        ];

        return response()->json($stats);
    }

    /**
     * Create a new order manually (Admin).
     */
    public function store(AdminCreateOrderRequest $request)
    {
        try {
            DB::beginTransaction();

            // Calculate total
            $total = 0;
            $orderItemsData = [];

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);

                // Check if product is active
                if (!$product->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => "Product '{$product->name}' is not available"
                    ], 400);
                }

                // Check stock
                if ($product->stock < $item['quantity']) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient stock for '{$product->name}'. Available: {$product->stock}, Requested: {$item['quantity']}"
                    ], 400);
                }

                // Use provided price or product price
                $price = $item['price'] ?? $product->price;
                $itemTotal = $price * $item['quantity'];
                $total += $itemTotal;

                $orderItemsData[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'price' => $price,
                ];
            }

            // Create order
            $order = Order::create([
                'user_id' => $request->user_id,
                'total' => $total,
                'status' => $request->status ?? 'pending',
            ]);

            // Create order items and update stock
            foreach ($orderItemsData as $itemData) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $itemData['product']->id,
                    'quantity' => $itemData['quantity'],
                    'price' => $itemData['price'],
                ]);

                // Decrement stock
                $itemData['product']->decrement('stock', $itemData['quantity']);
            }

            // Create payment if requested
            if ($request->create_payment) {
                $paymentAmount = $request->payment_amount ?? $total;
                $paymentStatus = $request->payment_status ?? 'pending';

                $payment = Payment::create([
                    'order_id' => $order->id,
                    'amount' => $paymentAmount,
                    'payment_method' => $request->payment_method ?? 'other',
                    'status' => $paymentStatus,
                ]);

                // If payment is completed, update order status to paid
                if ($paymentStatus === 'completed') {
                    $order->update(['status' => 'paid']);

                    // Send confirmation email
                    $order->user->notify(new OrderPaidNotification($order));
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => new OrderResource($order->load(['user', 'items.product', 'payment']))
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark order as paid.
     */
    public function markAsPaid(Order $order)
    {
        try {
            DB::transaction(function () use ($order) {
                // Update or create payment
                if ($order->payment) {
                    $order->payment->update(['status' => 'completed']);
                } else {
                    Payment::create([
                        'order_id' => $order->id,
                        'amount' => $order->total,
                        'payment_method' => 'manual',
                        'status' => 'completed',
                    ]);
                }

                // Update order status
                $order->update(['status' => 'paid']);

                // Send confirmation email
                $order->user->notify(new OrderPaidNotification($order));
            });

            return response()->json([
                'success' => true,
                'message' => 'Order marked as paid successfully',
                'data' => new OrderResource($order->load(['user', 'items.product', 'payment']))
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark order as paid: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark order as unpaid.
     */
    public function markAsUnpaid(Order $order)
    {
        try {
            DB::transaction(function () use ($order) {
                // Update payment status if exists
                if ($order->payment) {
                    $order->payment->update(['status' => 'pending']);
                }

                // Update order status
                $order->update(['status' => 'pending']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Order marked as unpaid successfully',
                'data' => new OrderResource($order->load(['user', 'items.product', 'payment']))
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark order as unpaid: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel order and restore stock.
     */
    public function cancelOrder(Order $order, Request $request)
    {
        if ($order->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Order is already cancelled'
            ], 400);
        }

        try {
            DB::transaction(function () use ($order, $request) {
                // Restore stock
                foreach ($order->items as $item) {
                    if ($item->product) {
                        $item->product->increment('stock', $item->quantity);
                    }
                }

                // Update payment status
                if ($order->payment) {
                    $order->payment->update(['status' => 'cancelled']);
                }

                // Update order status
                $order->update(['status' => 'cancelled']);

                // Send cancellation email
                $reason = $request->input('reason', 'Order cancelled by administrator');
                $order->user->notify(new OrderCancelledNotification($order, $reason));
            });

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully',
                'data' => new OrderResource($order->load(['user', 'items.product', 'payment']))
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment details for an order.
     */
    public function getPayment(Order $order)
    {
        if (!$order->payment) {
            return response()->json([
                'success' => false,
                'message' => 'No payment found for this order'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new PaymentResource($order->payment->load('order'))
        ]);
    }

    /**
     * Create or update payment for an order.
     */
    public function updatePayment(Order $order, AdminUpdatePaymentRequest $request)
    {
        try {
            DB::transaction(function () use ($order, $request) {
                $paymentData = [
                    'status' => $request->status,
                    'amount' => $request->amount ?? $order->payment?->amount ?? $order->total,
                    'payment_method' => $request->payment_method ?? $order->payment?->payment_method ?? 'manual',
                ];

                if ($request->filled('stripe_payment_intent_id')) {
                    $paymentData['stripe_payment_intent_id'] = $request->stripe_payment_intent_id;
                }

                // Update or create payment
                if ($order->payment) {
                    $order->payment->update($paymentData);
                } else {
                    $paymentData['order_id'] = $order->id;
                    Payment::create($paymentData);
                }

                // Update order status based on payment status
                if ($request->status === 'completed') {
                    $order->update(['status' => 'paid']);

                    // Send confirmation email
                    $order->user->notify(new OrderPaidNotification($order));
                } elseif ($request->status === 'cancelled' || $request->status === 'failed') {
                    if ($order->status !== 'cancelled') {
                        $order->update(['status' => 'pending']);
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Payment updated successfully',
                'data' => new PaymentResource($order->payment->load('order'))
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all payments with filters.
     */
    public function getAllPayments(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $perPage = min(max((int)$perPage, 1), 100);

        $query = Payment::with(['order.user']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by payment method
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        // Date range filters
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['created_at', 'amount', 'status'];
        $allowedSortOrders = ['asc', 'desc'];

        if (in_array($sortBy, $allowedSortFields) && in_array($sortOrder, $allowedSortOrders)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $payments = $query->paginate($perPage);

        return PaymentResource::collection($payments);
    }

    /**
     * Refund a payment.
     */
    public function refundPayment(Order $order, Request $request)
    {
        if (!$order->payment) {
            return response()->json([
                'success' => false,
                'message' => 'No payment found for this order'
            ], 404);
        }

        if ($order->payment->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Only completed payments can be refunded'
            ], 400);
        }

        try {
            DB::transaction(function () use ($order, $request) {
                // Update payment status
                $order->payment->update(['status' => 'refunded']);

                // Update order status
                $order->update(['status' => 'cancelled']);

                // Restore stock
                foreach ($order->items as $item) {
                    if ($item->product) {
                        $item->product->increment('stock', $item->quantity);
                    }
                }

                // Send notification
                $reason = $request->input('reason', 'Payment refunded');
                $order->user->notify(new OrderCancelledNotification($order, $reason));
            });

            return response()->json([
                'success' => true,
                'message' => 'Payment refunded successfully',
                'data' => [
                    'order' => new OrderResource($order->load(['user', 'items.product', 'payment'])),
                    'payment' => new PaymentResource($order->payment)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to refund payment: ' . $e->getMessage()
            ], 500);
        }
    }
}

