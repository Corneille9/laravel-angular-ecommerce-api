<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Order $order;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $this->order->load(['items.product', 'payment']);

        $mailMessage = (new MailMessage)
            ->subject('Order Confirmation - Order #' . $this->order->id)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Thank you for your order! Your payment has been successfully processed.')
            ->line('**Order Number:** #' . $this->order->id)
            ->line('**Order Total:** $' . number_format($this->order->total, 2))
            ->line('**Payment Status:** ' . ucfirst($this->order->payment->status))
            ->line('')
            ->line('**Order Items:**');

        foreach ($this->order->items as $item) {
            $mailMessage->line('- ' . $item->product->name . ' x ' . $item->quantity . ' = $' . number_format($item->price * $item->quantity, 2));
        }

        $mailMessage->line('')
            ->line('We will notify you once your order has been shipped.')
            ->line('If you have any questions, please don\'t hesitate to contact us.')
            ->action('View Order Details', config('app.frontend_url') . '/orders?search=' . $this->order->id)
            ->line('Thank you for shopping with us!');

        return $mailMessage;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'total' => $this->order->total,
            'status' => $this->order->status,
        ];
    }
}

