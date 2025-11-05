<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    /**
     * Handle Stripe webhook events
     * @throws \Throwable
     */
    public function handleWebhook(Request $request)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            // Verify webhook signature
            if ($webhookSecret) {
                $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            } else {
                $event = json_decode($payload);
            }

            // Handle the event
            switch ($event->type) {
                case 'checkout.session.completed':
                    $this->handleCheckoutSessionCompleted($event->data->object);
                    break;

                case 'checkout.session.expired':
                    $this->handleCheckoutSessionExpired($event->data->object);
                    break;

                case 'payment_intent.succeeded':
                    $this->handlePaymentIntentSucceeded($event->data->object);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handlePaymentIntentFailed($event->data->object);
                    break;

                default:
                    Log::info('Unhandled Stripe webhook event: ' . $event->type);
            }

            return response()->json(['status' => 'success'], 200);

        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            Log::error('Stripe webhook error: Invalid payload', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            Log::error('Stripe webhook error: Invalid signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Webhook handling failed'], 500);
        }
    }

    /**
     * Handle successful checkout session
     */
    private function handleCheckoutSessionCompleted($session)
    {
        try {
            DB::transaction(function () use ($session) {
                $payment = Payment::where('stripe_checkout_session_id', $session->id)->first();

                if ($payment && $payment->status === 'pending') {
                    $payment->update([
                        'status' => 'completed',
                        'stripe_payment_intent_id' => $session->payment_intent,
                    ]);

                    $payment->order->update([
                        'status' => 'processing',
                    ]);

                    Log::info('Payment completed via webhook', [
                        'payment_id' => $payment->id,
                        'order_id' => $payment->order_id,
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error('Error handling checkout.session.completed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle expired checkout session
     * @throws \Throwable
     */
    private function handleCheckoutSessionExpired($session)
    {
        try {
            DB::transaction(function () use ($session) {
                $payment = Payment::where('stripe_checkout_session_id', $session->id)->first();

                if ($payment && $payment->status === 'pending') {
                    $payment->update([
                        'status' => 'failed',
                    ]);

                    $payment->order->update([
                        'status' => 'cancelled',
                    ]);

                    // Restore product stock
                    foreach ($payment->order->items as $item) {
                        $item->product->increment('stock', $item->quantity);
                    }

                    Log::info('Payment session expired via webhook', [
                        'payment_id' => $payment->id,
                        'order_id' => $payment->order_id,
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error('Error handling checkout.session.expired', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle successful payment intent
     */
    private function handlePaymentIntentSucceeded($paymentIntent)
    {
        try {
            $payment = Payment::where('stripe_payment_intent_id', $paymentIntent->id)->first();

            if ($payment && $payment->status !== 'completed') {
                $payment->update([
                    'status' => 'completed',
                ]);

                Log::info('Payment intent succeeded via webhook', [
                    'payment_id' => $payment->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error handling payment_intent.succeeded', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle failed payment intent
     * @throws \Throwable
     */
    private function handlePaymentIntentFailed($paymentIntent)
    {
        try {
            DB::transaction(function () use ($paymentIntent) {
                $payment = Payment::where('stripe_payment_intent_id', $paymentIntent->id)->first();

                if ($payment) {
                    $payment->update([
                        'status' => 'failed',
                    ]);

                    $payment->order->update([
                        'status' => 'cancelled',
                    ]);

                    // Restore product stock
                    foreach ($payment->order->items as $item) {
                        $item->product->increment('stock', $item->quantity);
                    }

                    Log::info('Payment intent failed via webhook', [
                        'payment_id' => $payment->id,
                        'order_id' => $payment->order_id,
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error('Error handling payment_intent.payment_failed', ['error' => $e->getMessage()]);
        }
    }
}
