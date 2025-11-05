<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /**
     * Get dashboard statistics.
     */
    public function index()
    {
        $stats = [
            'users' => [
                'total' => User::count(),
                'admins' => User::where('role', 'admin')->count(),
                'customers' => User::where('role', 'user')->count(),
                'new_today' => User::whereDate('created_at', today())->count(),
            ],
            'products' => [
                'total' => Product::count(),
                'active' => Product::where('is_active', true)->count(),
                'inactive' => Product::where('is_active', false)->count(),
                'out_of_stock' => Product::where('stock', '<=', 0)->count(),
                'low_stock' => Product::whereBetween('stock', [1, 10])->count(),
            ],
            'orders' => [
                'total' => Order::count(),
                'pending' => Order::where('status', 'pending')->count(),
                'paid' => Order::where('status', 'paid')->count(),
                'shipped' => Order::where('status', 'shipped')->count(),
                'completed' => Order::where('status', 'completed')->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
                'today' => Order::whereDate('created_at', today())->count(),
                'this_month' => Order::whereMonth('created_at', now()->month)->count(),
            ],
            'revenue' => [
                'total' => Payment::where('status', 'completed')->sum('amount'),
                'today' => Payment::whereDate('created_at', today())
                    ->where('status', 'completed')
                    ->sum('amount'),
                'this_week' => Payment::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                    ->where('status', 'completed')
                    ->sum('amount'),
                'this_month' => Payment::whereMonth('created_at', now()->month)
                    ->where('status', 'completed')
                    ->sum('amount'),
            ],
            'recent_orders' => Order::with(['user', 'items.product'])
                ->latest()
                ->take(5)
                ->get(),
        ];

        return response()->json($stats);
    }

    /**
     * Get sales chart data.
     */
    public function salesChart(Request $request)
    {
        $period = $request->input('period', 'week'); // day, week, month, year

        $query = Order::where('status', 'completed');

        switch ($period) {
            case 'day':
                $data = $query->selectRaw('DATE(created_at) as date, SUM(total) as total, COUNT(*) as count')
                    ->whereDate('created_at', '>=', now()->subDays(7))
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get();
                break;
            case 'month':
                $data = $query->selectRaw('DATE(created_at) as date, SUM(total) as total, COUNT(*) as count')
                    ->whereDate('created_at', '>=', now()->subMonths(12))
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get();
                break;
            default: // week
                $data = $query->selectRaw('DATE(created_at) as date, SUM(total) as total, COUNT(*) as count')
                    ->whereDate('created_at', '>=', now()->subWeeks(4))
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get();
        }

        return response()->json($data);
    }
}

