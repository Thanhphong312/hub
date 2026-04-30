<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class QrController extends Controller
{
    public function show(Request $request, string $systemId)
    {
        $order = Order::with([
            'items.metas',
            'items.productVariant.product',
            'items.accessoryPrice.accessory',
            'items.accessoryPrice.tier',
            'address',
            'user',
        ])->where('system_id', $systemId)->first();

        if (!$order) {
            throw new NotFoundHttpException('Order not found');
        }

        $user = $request->user();
        // Sellers can only see their own orders
        if ($user->hasRole('seller') && $order->user_id !== $user->id) {
            abort(403, 'Forbidden');
        }

        return view('qr.show', [
            'order' => $order,
            'user' => $user,
        ]);
    }
}
