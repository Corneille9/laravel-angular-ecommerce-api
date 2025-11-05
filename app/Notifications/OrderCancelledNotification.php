<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Order $order;
    public string $reason;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order, string $reason = 'Payment not completed within allowed time')
    {
        $this->order = $order;
        $this->reason = $reason;
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
        return (new MailMessage)
            ->subject('Order Cancelled - Order #' . $this->order->id)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('We regret to inform you that your order has been cancelled.')
            ->line('**Order Number:** #' . $this->order->id)
            ->line('**Order Total:** $' . number_format($this->order->total, 2))
            ->line('')
            ->line('**Reason:** ' . $this->reason)
            ->line('')
            ->line('If this was unintentional or if you have any questions, please contact our support team.')
            ->line('You can create a new order anytime by visiting our store.')
            ->action('Browse Products', config('app.frontend_url'))
            ->line('Thank you for your understanding.');
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
            'reason' => $this->reason,
        ];
    }
}

