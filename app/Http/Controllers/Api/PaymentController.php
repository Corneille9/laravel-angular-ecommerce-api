<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    /**
     * Display a listing of the user's payments.
     */
    public function index()
    {
        $user = Auth::user();
        $payments = Payment::whereHas('order', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->with('order')->get();

        return PaymentResource::collection($payments);
    }

    /**
     * Display the specified payment.
     */
    public function show(Payment $payment)
    {
        $payment->load('order');

        if ($payment->order->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return new PaymentResource($payment);
    }

    /**
     * Update the specified payment (status).
     */
    public function update(UpdatePaymentRequest $request, Payment $payment)
    {
        if ($payment->order->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $payment->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'message' => 'Payment updated successfully',
            'payment' => new PaymentResource($payment)
        ]);
    }

    /**
     * Remove the specified payment from storage.
     */
    public function destroy(Payment $payment)
    {
        if ($payment->order->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $payment->delete();

        return response()->json(['message' => 'Payment deleted successfully']);
    }
}
