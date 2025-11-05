<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminPaymentController extends Controller
{
    /**
     * Display a listing of all payments (admin).
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $perPage = min(max((int)$perPage, 1), 100);

        $query = Payment::with('order.user');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by payment method
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        // Filter by order ID
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->input('order_id'));
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
     * Store a newly created payment for a given order.
     */
    public function store(StorePaymentRequest $request)
    {
        $order = Order::find($request->order_id);

        if ($order->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Crée le paiement
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'status' => 'pending', // par défaut
        ]);

        return response()->json([
            'message' => 'Payment created successfully',
            'payment' => new PaymentResource($payment)
        ], 201);
    }

    /**
     * Display the specified payment.
     */
    public function show(Payment $payment)
    {
        $payment->load('order.user');
        return new PaymentResource($payment);
    }

    /**
     * Get payment statistics.
     */
    public function statistics()
    {
        $stats = [
            'total_payments' => Payment::count(),
            'pending_payments' => Payment::where('status', 'pending')->count(),
            'completed_payments' => Payment::where('status', 'completed')->count(),
            'failed_payments' => Payment::where('status', 'failed')->count(),
            'total_amount' => Payment::where('status', 'completed')->sum('amount'),
            'today_payments' => Payment::whereDate('created_at', today())->count(),
            'today_amount' => Payment::whereDate('created_at', today())
                ->where('status', 'completed')
                ->sum('amount'),
        ];

        return response()->json($stats);
    }
}

