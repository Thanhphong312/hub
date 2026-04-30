@extends('layouts.app')
@section('title', 'Order ' . $order->system_id)

@section('content')
@php
    $statusMap = [0 => 'new_order', 1 => 'processing', 2 => 'wrongsize', 3 => 'fixed', 4 => 'reprint', 5 => 'onhold', 6 => 'shipped'];

    // Convert any URL into something we can <img src=> at. Drive share links
    // get rewritten to Drive's public thumbnail endpoint; everything else is
    // treated as a direct image URL (B2 / S3 / etc.).
    $driveThumb = function ($url, $size = 'w800') {
        if (!$url) return null;
        if (preg_match('#(?:/file/d/|[?&]id=)([a-zA-Z0-9_-]+)#', $url, $m)) {
            return "https://drive.google.com/thumbnail?id={$m[1]}&sz={$size}";
        }
        return $url;
    };

    $isImageish = function ($url) {
        return $url && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'));
    };

    $sourceKeys = ['front', 'back', 'left', 'right', 'neck', 'special'];
@endphp

<div class="max-w-6xl mx-auto p-6">
    <div class="flex justify-between items-start mb-6">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800">Order {{ $order->system_id }}</h1>
            @if ($order->ref_id)
                <p class="text-xs text-neutral-500 mt-1">Ref: <span class="font-mono">{{ $order->ref_id }}</span></p>
            @endif
            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                <span class="px-2 py-0.5 bg-orange-100 text-orange-700 rounded font-medium">{{ $statusMap[$order->status] ?? $order->status }}</span>
                <span class="px-2 py-0.5 bg-neutral-100 text-neutral-600 rounded">{{ $order->ship_type }}</span>
                @if ($order->production)
                    <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded font-medium">Production</span>
                @endif
            </div>
        </div>
        <form method="POST" action="{{ url('/logout') }}">
            @csrf
            <button class="text-xs text-neutral-500 hover:text-red-500">Logout ({{ $user->name }})</button>
        </form>
    </div>

    {{-- Top row: Order info + Address + Shipping label preview --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-neutral-200 p-4 shadow-sm space-y-1.5 text-sm">
            <h2 class="font-semibold text-neutral-700 mb-2 text-sm">Order info</h2>
            <div class="flex justify-between"><span class="text-neutral-500">Tracking</span><span class="font-medium">{{ $order->tracking_id ?? '-' }}</span></div>
            <div class="flex justify-between"><span class="text-neutral-500">Print Cost</span><span class="font-medium">${{ $order->print_cost }}</span></div>
            <div class="flex justify-between"><span class="text-neutral-500">Shipping Cost</span><span class="font-medium">${{ $order->shipping_cost }}</span></div>
            <div class="flex justify-between border-t border-neutral-100 pt-1.5"><span class="text-neutral-500">Total</span><span class="font-semibold text-neutral-800">${{ $order->total_cost }}</span></div>
            <div class="flex justify-between"><span class="text-neutral-500">Paid</span><span class="font-medium {{ $order->paid_cost >= $order->total_cost ? 'text-green-600' : 'text-red-500' }}">${{ $order->paid_cost }}</span></div>
            <div class="flex justify-between text-xs text-neutral-500 pt-1.5 border-t border-neutral-100"><span>Created</span><span>{{ $order->created_at->toDateTimeString() }}</span></div>
            @if ($order->completed_time)
                <div class="flex justify-between text-xs text-neutral-500"><span>Completed</span><span>{{ $order->completed_time->toDateTimeString() }}</span></div>
            @endif
        </div>

        @if ($order->address)
            <div class="bg-white rounded-xl border border-neutral-200 p-4 shadow-sm text-sm">
                <h2 class="font-semibold text-neutral-700 mb-2 text-sm">Shipping address</h2>
                <p class="font-medium">{{ $order->address->first_name }} {{ $order->address->last_name }}</p>
                <p class="text-neutral-600">{{ $order->address->address_1 }}</p>
                @if ($order->address->address_2)
                    <p class="text-neutral-600">{{ $order->address->address_2 }}</p>
                @endif
                <p class="text-neutral-600">{{ $order->address->city }}, {{ $order->address->state }} {{ $order->address->zipcode }}</p>
                <p class="text-neutral-600">{{ $order->address->country }}</p>
            </div>
        @endif

        @if ($isImageish($order->shipping_label))
            <div class="bg-white rounded-xl border border-neutral-200 p-4 shadow-sm text-sm">
                <h2 class="font-semibold text-neutral-700 mb-2 text-sm">Shipping label</h2>
                <a href="{{ $order->shipping_label }}" target="_blank" rel="noreferrer" class="block">
                    <img src="{{ $driveThumb($order->shipping_label, 'w600') }}" alt="shipping label" class="w-full h-40 object-contain bg-neutral-50 rounded border border-neutral-200" onerror="this.style.display='none'" />
                </a>
                <p class="text-[10px] text-neutral-400 mt-1 truncate">{{ $order->shipping_label }}</p>
            </div>
        @endif
    </div>

    {{-- Items: card per item with mockups + metas as image gallery --}}
    <div class="space-y-5">
        @foreach ($order->items as $item)
            @php
                $variant = $item->productVariant;
                $product = $variant?->product;
                $accUnit = $item->accessoryPrice ? (float) $item->accessoryPrice->price : 0;
                $qty = $item->quantity ?? 1;
                $subtotal = ((float) $item->price + $accUnit) * $qty;

                $metasByKey = $item->metas->keyBy('key');
                $sourceMetas = collect($sourceKeys)
                    ->mapWithKeys(fn($k) => [$k => $metasByKey->get($k)])
                    ->filter(fn($m) => $m && $isImageish($m->value));
                $qrMetas = $item->metas
                    ->filter(fn($m) => preg_match('/_qr(_\d+)?$/', $m->key) && $isImageish($m->value))
                    ->sortBy('key');
                $otherMetas = $item->metas->filter(function ($m) use ($sourceKeys) {
                    if (preg_match('/_qr(_\d+)?$/', $m->key)) return false;
                    if (in_array($m->key, $sourceKeys)) return false;
                    return true;
                });
            @endphp

            <div class="bg-white rounded-xl border border-neutral-200 shadow-sm overflow-hidden">
                {{-- Header --}}
                <div class="px-4 py-3 border-b border-neutral-100 bg-[#faf8f6] flex justify-between items-start gap-3">
                    <div>
                        <div class="text-sm font-semibold text-neutral-800">
                            {{ $product?->name ?? '-' }}
                            @if ($variant)
                                <span class="text-neutral-500 font-normal">— {{ $variant->color }} / {{ $variant->size }}</span>
                            @endif
                        </div>
                        <div class="text-xs text-neutral-500 mt-0.5 flex flex-wrap gap-2">
                            @if ($variant?->sku)
                                <span class="font-mono">SKU: {{ $variant->sku }}</span>
                            @endif
                            <span>Type: {{ $item->order_type === 0 ? 'Greeting Card' : 'Pass Sleeve' }}</span>
                            <span>Qty: <span class="font-semibold text-neutral-700">{{ $qty }}</span></span>
                            @if ($item->accessoryPrice)
                                <span class="text-neutral-700">
                                    Accessory: {{ $item->accessoryPrice->accessory?->name }}
                                    @if ($item->accessoryPrice->style) / {{ $item->accessoryPrice->style }} @endif
                                    <span class="text-neutral-400">(+${{ $item->accessoryPrice->price }})</span>
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="text-right text-xs">
                        <div class="text-neutral-500">Price <span class="text-neutral-800 font-medium">${{ $item->price }}</span></div>
                        <div class="text-sm font-semibold text-neutral-800">${{ number_format($subtotal, 2) }}</div>
                    </div>
                </div>

                {{-- Mockups + source design metas — split into two side-by-side panels --}}
                @php
                    $hasMockup = $isImageish($item->mockup_front) || $isImageish($item->mockup_back);
                    $hasDesign = $sourceMetas->isNotEmpty();
                @endphp
                @if ($hasMockup || $hasDesign)
                    <div class="p-4 border-b border-neutral-100">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h3 class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-2">Mockups</h3>
                                @if ($hasMockup)
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                        @if ($isImageish($item->mockup_front))
                                            <a href="{{ $item->mockup_front }}" target="_blank" rel="noreferrer" class="block group">
                                                <div class="aspect-square bg-neutral-50 border border-neutral-200 rounded overflow-hidden group-hover:ring-2 group-hover:ring-orange-400 transition">
                                                    <img src="{{ $driveThumb($item->mockup_front) }}" alt="mockup front" class="w-full h-full object-cover" loading="lazy" onerror="this.parentElement.innerHTML='<div class=&quot;text-[10px] text-neutral-400 p-2 break-all&quot;>{{ $item->mockup_front }}</div>'" />
                                                </div>
                                                <div class="mt-1 text-[11px] text-neutral-600 font-medium">Mockup front</div>
                                            </a>
                                        @endif
                                        @if ($isImageish($item->mockup_back))
                                            <a href="{{ $item->mockup_back }}" target="_blank" rel="noreferrer" class="block group">
                                                <div class="aspect-square bg-neutral-50 border border-neutral-200 rounded overflow-hidden group-hover:ring-2 group-hover:ring-orange-400 transition">
                                                    <img src="{{ $driveThumb($item->mockup_back) }}" alt="mockup back" class="w-full h-full object-cover" loading="lazy" onerror="this.parentElement.innerHTML='<div class=&quot;text-[10px] text-neutral-400 p-2 break-all&quot;>{{ $item->mockup_back }}</div>'" />
                                                </div>
                                                <div class="mt-1 text-[11px] text-neutral-600 font-medium">Mockup back</div>
                                            </a>
                                        @endif
                                    </div>
                                @else
                                    <p class="text-xs text-neutral-400">No mockup uploaded.</p>
                                @endif
                            </div>

                            <div class="md:border-l md:border-neutral-100 md:pl-6">
                                <h3 class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-2">Designs</h3>
                                @if ($hasDesign)
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                        @foreach ($sourceMetas as $key => $meta)
                                            <a href="{{ $meta->value }}" target="_blank" rel="noreferrer" class="block group">
                                                <div class="aspect-square bg-neutral-50 border border-neutral-200 rounded overflow-hidden group-hover:ring-2 group-hover:ring-orange-400 transition">
                                                    <img src="{{ $driveThumb($meta->value) }}" alt="{{ $key }}" class="w-full h-full object-cover" loading="lazy" onerror="this.parentElement.innerHTML='<div class=&quot;text-[10px] text-neutral-400 p-2 break-all&quot;>{{ $meta->value }}</div>'" />
                                                </div>
                                                <div class="mt-1 text-[11px] text-neutral-600 font-medium capitalize">{{ $key }}</div>
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-xs text-neutral-400">No design metas.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- _qr produced images --}}
                @if ($qrMetas->isNotEmpty())
                    <div class="p-4 bg-blue-50/30">
                        <h3 class="text-xs font-semibold text-blue-700 uppercase tracking-wide mb-2">Production (_qr)</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                            @foreach ($qrMetas as $meta)
                                <a href="{{ $meta->value }}" target="_blank" rel="noreferrer" class="block group">
                                    <div class="aspect-[3/2] bg-white border border-blue-200 rounded overflow-hidden group-hover:ring-2 group-hover:ring-blue-400 transition">
                                        <img src="{{ $driveThumb($meta->value) }}" alt="{{ $meta->key }}" class="w-full h-full object-cover" loading="lazy" onerror="this.parentElement.innerHTML='<div class=&quot;text-[10px] text-neutral-400 p-2 break-all&quot;>{{ $meta->value }}</div>'" />
                                    </div>
                                    <div class="mt-1 flex items-center justify-between text-[11px]">
                                        <span class="font-mono text-blue-700">{{ $meta->key }}</span>
                                        @if ($meta->production)
                                            <span class="text-green-600" title="Produced (in a gangsheet)">●</span>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Other (non-image) metas --}}
                @if ($otherMetas->isNotEmpty())
                    <div class="px-4 py-3 border-t border-neutral-100">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($otherMetas as $meta)
                                <div class="text-xs bg-[#faf8f6] px-2 py-1 rounded">
                                    <span class="text-neutral-500 font-medium">{{ $meta->key }}:</span>
                                    @if ($isImageish($meta->value))
                                        <a href="{{ $meta->value }}" target="_blank" class="text-orange-500 truncate inline-block max-w-[260px] align-middle">{{ $meta->value }}</a>
                                    @else
                                        <span class="text-neutral-700">{{ $meta->value }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endsection
