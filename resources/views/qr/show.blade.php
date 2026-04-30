@extends('layouts.app')
@section('title', 'Order ' . $order->system_id)

@section('content')
@php
    $statusMap = [0 => 'new_order', 1 => 'processing', 2 => 'wrongsize', 3 => 'fixed', 4 => 'reprint', 5 => 'onhold', 6 => 'shipped'];
@endphp

<div class="max-w-4xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800">Order {{ $order->system_id }}</h1>
            @if ($order->ref_id)
                <p class="text-xs text-neutral-500 mt-1">Ref: <span class="font-mono">{{ $order->ref_id }}</span></p>
            @endif
        </div>
        <form method="POST" action="{{ url('/logout') }}">
            @csrf
            <button class="text-xs text-neutral-500 hover:text-red-500">Logout ({{ $user->name }})</button>
        </form>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Order info --}}
        <div class="bg-white rounded-xl border border-neutral-200 p-4 shadow-sm space-y-2 text-sm">
            <h2 class="font-semibold text-neutral-600 mb-2">Order Info</h2>
            <div class="flex justify-between"><span class="text-neutral-500">Status</span><span class="font-medium">{{ $statusMap[$order->status] ?? $order->status }}</span></div>
            <div class="flex justify-between"><span class="text-neutral-500">Ship Type</span><span class="font-medium">{{ $order->ship_type }}</span></div>
            <div class="flex justify-between gap-3">
                <span class="text-neutral-500">Shipping Label</span>
                @if ($order->shipping_label)
                    <a href="{{ $order->shipping_label }}" target="_blank" class="text-orange-500 truncate max-w-[60%] text-xs">{{ $order->shipping_label }}</a>
                @else
                    <span class="text-neutral-400">-</span>
                @endif
            </div>
            <div class="flex justify-between"><span class="text-neutral-500">Tracking</span><span class="font-medium">{{ $order->tracking_id ?? '-' }}</span></div>
            <div class="flex justify-between"><span class="text-neutral-500">Print Cost</span><span class="font-medium">${{ $order->print_cost }}</span></div>
            <div class="flex justify-between"><span class="text-neutral-500">Shipping Cost</span><span class="font-medium">${{ $order->shipping_cost }}</span></div>
            <div class="flex justify-between"><span class="text-neutral-500">Total Cost</span><span class="font-medium">${{ $order->total_cost }}</span></div>
            <div class="flex justify-between"><span class="text-neutral-500">Paid</span><span class="font-medium">${{ $order->paid_cost }}</span></div>
            <div class="flex justify-between"><span class="text-neutral-500">Created</span><span class="font-medium">{{ $order->created_at->toDateTimeString() }}</span></div>
            @if ($order->completed_time)
                <div class="flex justify-between"><span class="text-neutral-500">Completed</span><span class="font-medium">{{ $order->completed_time->toDateTimeString() }}</span></div>
            @endif
        </div>

        {{-- Address --}}
        @if ($order->address)
            <div class="bg-white rounded-xl border border-neutral-200 p-4 shadow-sm text-sm">
                <h2 class="font-semibold text-neutral-600 mb-2">Shipping Address</h2>
                <p class="font-medium">{{ $order->address->first_name }} {{ $order->address->last_name }}</p>
                <p>{{ $order->address->address_1 }}</p>
                @if ($order->address->address_2)
                    <p>{{ $order->address->address_2 }}</p>
                @endif
                <p>{{ $order->address->city }}, {{ $order->address->state }} {{ $order->address->zipcode }}</p>
                <p>{{ $order->address->country }}</p>
            </div>
        @endif
    </div>

    {{-- Items --}}
    <div class="mt-6 bg-white rounded-xl border border-neutral-200 p-4 shadow-sm">
        <h2 class="font-semibold text-neutral-600 mb-3 text-sm">Order Items</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-neutral-500 text-xs border-b border-neutral-200">
                    <th class="pb-2 text-left">Product</th>
                    <th class="pb-2 text-left">Type</th>
                    <th class="pb-2 text-left">Accessory</th>
                    <th class="pb-2 text-left">Mockup F/B</th>
                    <th class="pb-2 text-center">Qty</th>
                    <th class="pb-2 text-right">Price</th>
                    <th class="pb-2 text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    @php
                        $accUnit = $item->accessoryPrice ? (float) $item->accessoryPrice->price : 0;
                        $qty = $item->quantity ?? 1;
                        $subtotal = ((float) $item->price + $accUnit) * $qty;
                    @endphp
                    <tr class="border-b border-neutral-100 align-top">
                        <td class="py-2 text-neutral-800">
                            <span class="font-mono text-xs text-neutral-500">{{ $item->productVariant?->sku ?? '' }}</span>
                            <div>{{ $item->productVariant?->product?->name ?? '-' }} - {{ $item->productVariant?->color }} / {{ $item->productVariant?->size }}</div>
                        </td>
                        <td class="py-2 text-neutral-600">{{ $item->order_type === 0 ? 'Greeting Card' : 'Pass Sleeve' }}</td>
                        <td class="py-2 text-neutral-600 text-xs">
                            @if ($item->accessoryPrice)
                                {{ $item->accessoryPrice->accessory?->name }}
                                @if ($item->accessoryPrice->style) / {{ $item->accessoryPrice->style }} @endif
                                <span class="text-neutral-400">(+${{ $item->accessoryPrice->price }})</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="py-2 text-xs space-y-1">
                            @if ($item->mockup_front)
                                <div><a href="{{ $item->mockup_front }}" target="_blank" class="text-orange-500 truncate inline-block max-w-[180px]">F: {{ $item->mockup_front }}</a></div>
                            @endif
                            @if ($item->mockup_back)
                                <div><a href="{{ $item->mockup_back }}" target="_blank" class="text-orange-500 truncate inline-block max-w-[180px]">B: {{ $item->mockup_back }}</a></div>
                            @endif
                        </td>
                        <td class="py-2 text-center">{{ $qty }}</td>
                        <td class="py-2 text-right font-medium">${{ $item->price }}</td>
                        <td class="py-2 text-right font-medium">${{ number_format($subtotal, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Metas --}}
        @php
            $hasMetas = $order->items->contains(fn($it) => $it->metas->isNotEmpty());
        @endphp
        @if ($hasMetas)
            <div class="mt-4 pt-4 border-t border-neutral-100">
                <h3 class="text-xs font-semibold text-neutral-500 uppercase mb-2">Item Metas</h3>
                @foreach ($order->items->filter(fn($it) => $it->metas->isNotEmpty()) as $item)
                    <div class="mb-3 pl-2 border-l-2 border-neutral-100">
                        <div class="text-[11px] text-neutral-400 mb-1">Item #{{ $item->id }} — {{ $item->productVariant?->product?->name }}</div>
                        <div class="flex flex-wrap gap-3">
                            @foreach ($item->metas as $meta)
                                <div class="text-xs bg-[#faf8f6] px-2 py-1 rounded">
                                    <span class="text-neutral-500 font-medium">{{ $meta->key }}:</span>
                                    @if (filter_var($meta->value, FILTER_VALIDATE_URL))
                                        <a href="{{ $meta->value }}" target="_blank" class="text-orange-500 truncate inline-block max-w-[200px] align-middle">{{ $meta->value }}</a>
                                    @else
                                        <span class="text-neutral-700">{{ $meta->value }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
