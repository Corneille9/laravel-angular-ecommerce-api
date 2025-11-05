<?php

namespace App\Http\Resources;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cart
 */
class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('items');
        $total = 0;

        if ($items) {
            $total = $items->sum(function ($item) {
                return $item->product ? $item->product->price * $item->quantity : 0;
            });
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'items' => CartItemResource::collection($items),
            'items_count' => $items ? $items->count() : 0,
            'total' => $total,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
