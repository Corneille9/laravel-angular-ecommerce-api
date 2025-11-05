<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Notifications\OrderCancelledNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as CommandAlias;

class CancelPendingOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:cancel-pending {--days=7 : Number of days after which pending orders should be cancelled}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancel orders and payments that have been in pending status for more than specified days (default: 7 days)';

    /**
     * Execute the console command.
     * @throws \Throwable
     */
    public function handle(): int
    {
        $days = $this->option('days');

        $this->info("Searching for orders pending for more than {$days} days...");

        // Find orders that are pending for more than specified days
        $pendingOrders = Order::with(['items.product', 'payment', 'user'])
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDays($days))
            ->get();

        if ($pendingOrders->isEmpty()) {
            $this->info('No pending orders found to cancel.');
            return CommandAlias::SUCCESS;
        }

        $this->info("Found {$pendingOrders->count()} pending orders to cancel.");

        $cancelledCount = 0;
        $failedCount = 0;

        foreach ($pendingOrders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    // Restore product stock
                    foreach ($order->items as $item) {
                        $product = $item->product;
                        if ($product) {
                            $product->increment('stock', $item->quantity);
                            $this->line("  Restored {$item->quantity} units to product: {$product->name}");
                        }
                    }

                    // Update payment status
                    if ($order->payment) {
                        $order->payment->update([
                            'status' => 'cancelled',
                        ]);
                    }

                    // Update order status
                    $order->update([
                        'status' => 'cancelled',
                    ]);

                    // Send cancellation email to user
                    $order->user->notify(new OrderCancelledNotification(
                        $order,
                        "Payment not completed within {$this->option('days')} days"
                    ));
                });

                $this->info("✓ Cancelled Order #{$order->id} (User: {$order->user->email})");
                $cancelledCount++;

            } catch (\Exception $e) {
                $this->error("✗ Failed to cancel Order #{$order->id}: {$e->getMessage()}");
                $failedCount++;
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("- Successfully cancelled: {$cancelledCount} orders");

        if ($failedCount > 0) {
            $this->warn("- Failed to cancel: {$failedCount} orders");
            return CommandAlias::FAILURE;
        }

        $this->info('All pending orders processed successfully.');
        return CommandAlias::SUCCESS;
    }
}
