@extends('layouts.app')
@section('title', 'Gangsheet Label ' . $scanCode)

@section('content')
@php
    $statusMap = [0 => 'new_order', 1 => 'processing', 2 => 'wrongsize', 3 => 'fixed', 4 => 'reprint', 5 => 'onhold', 6 => 'shipped', 7 => 'cancelled'];
    $driveThumb = function ($url, $size = 'w300') {
        if (!$url) return null;
        if (preg_match('#(?:/file/d/|[?&]id=)([a-zA-Z0-9_-]+)#', $url, $m)) {
            return "https://drive.google.com/thumbnail?id={$m[1]}&sz={$size}";
        }
        return $url;
    };
@endphp

{{-- Scan input at top — mirrors /qr layout so an operator can switch
     between scanning order codes (PS_...) and gangsheet-label codes (GSL...)
     from either page. --}}
<div class="sticky top-0 z-20 bg-white/95 backdrop-blur border-b border-neutral-200 px-6 py-3">
    <div class="max-w-6xl mx-auto flex items-center gap-3">
        <label for="scanInput" class="text-xs font-semibold text-neutral-600 uppercase tracking-wider whitespace-nowrap">Scan / Type</label>
        <input
            type="text"
            id="scanInput"
            value="{{ $scanCode }}"
            autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
            class="flex-1 px-3 py-2 bg-[#faf8f6] border border-neutral-200 rounded-lg text-neutral-800 text-sm font-mono focus:outline-none focus:border-orange-400"
            placeholder="GSL123 (gangsheet) or PS_C605 (order)"
        />
        <span id="scanStatus" class="text-xs text-neutral-400 w-24 text-right"></span>
    </div>
</div>
<script>
(function () {
    const input = document.getElementById('scanInput');
    const status = document.getElementById('scanStatus');
    const current = @json($scanCode);
    let timer = null;

    function go(raw) {
        const v = (raw || '').trim();
        if (!v) return;
        if (v === current) { status.textContent = ''; return; }
        status.textContent = 'Loading…';
        // Route by prefix: GSL → gangsheet label, anything else → order.
        if (/^GSL\d+$/i.test(v) || /^\d+$/.test(v)) {
            window.location.href = '/gs/' + encodeURIComponent(v);
        } else {
            window.location.href = '/qr/' + encodeURIComponent(v);
        }
    }

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const v = input.value;
        status.textContent = v.trim() && v.trim() !== current ? 'Waiting…' : '';
        timer = setTimeout(() => go(v), 350);
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(timer);
            go(input.value);
        }
    });
    setTimeout(() => { input.focus(); input.select(); }, 50);
    window.addEventListener('focus', () => { input.focus(); input.select(); });
})();
</script>

<div class="max-w-6xl mx-auto p-6">
    @if (session('status_message'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
            {{ session('status_message') }}
        </div>
    @endif

    {{-- Header + summary stats --}}
    <div class="flex justify-between items-start mb-4 gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800">Gangsheet Label <span class="font-mono text-orange-600">{{ $scanCode }}</span></h1>
            <p class="text-xs text-neutral-500 mt-1">{{ $label->name }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="px-2 py-0.5 rounded font-medium {{ $label->status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700' }}">
                    {{ $label->status }}
                </span>
                @if ($label->line_id)
                    <span class="px-2 py-0.5 bg-neutral-100 text-neutral-600 rounded">line: {{ $label->line_id }}</span>
                @endif
                <span class="text-neutral-400">Created {{ $label->created_at->toDateTimeString() }}</span>
            </div>
        </div>
        <form method="POST" action="{{ url('/logout') }}">
            @csrf
            <button class="text-xs text-neutral-500 hover:text-red-500">Logout ({{ $user->name }})</button>
        </form>
    </div>

    {{-- Big stats --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-neutral-200 p-5 text-center">
            <div class="text-xs uppercase tracking-wider text-neutral-500">Orders</div>
            <div class="text-4xl font-extrabold text-neutral-800 mt-1">{{ $orders->count() }}</div>
        </div>
        <div class="bg-orange-50 rounded-xl border border-orange-200 p-5 text-center">
            <div class="text-xs uppercase tracking-wider text-orange-700">Total items × qty</div>
            <div class="text-5xl font-extrabold text-orange-600 mt-1 leading-none">{{ $totalItemsQty }}</div>
        </div>
        <div class="bg-white rounded-xl border border-neutral-200 p-5 text-center">
            <div class="text-xs uppercase tracking-wider text-neutral-500">Shipped</div>
            <div class="text-4xl font-extrabold {{ $shippedCount === $orders->count() && $orders->count() > 0 ? 'text-green-600' : 'text-neutral-700' }} mt-1">
                {{ $shippedCount }} / {{ $orders->count() }}
            </div>
        </div>
    </div>

    {{-- Complete all CTA --}}
    @if ($orders->count() > 0)
        <form method="POST" action="{{ route('gs.complete-all', ['code' => $scanCode]) }}"
              onsubmit="return confirm('Mark all {{ $orders->count() }} order(s) in this gangsheet label as SHIPPED?');"
              class="mb-6">
            @csrf
            <button type="submit"
                @if ($shippedCount === $orders->count()) disabled @endif
                class="w-full px-6 py-4 rounded-xl font-extrabold text-lg border-2 transition shadow-sm
                       {{ $shippedCount === $orders->count()
                          ? 'bg-green-100 text-green-700 border-green-200 cursor-not-allowed opacity-60'
                          : 'bg-green-500 text-white border-green-600 hover:bg-green-600 hover:shadow-md' }}">
                @if ($shippedCount === $orders->count())
                    ✓ All orders already shipped
                @else
                    ✓ Complete all {{ $orders->count() }} orders (mark shipped)
                @endif
            </button>
        </form>
    @endif

    {{-- Orders table --}}
    <div class="bg-white rounded-xl border border-neutral-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-neutral-100">
            <h3 class="text-sm font-semibold text-neutral-700">Orders in this group</h3>
        </div>
        @if ($orders->isEmpty())
            <p class="text-sm text-neutral-400 p-6 text-center">No orders.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-xs uppercase tracking-wider text-neutral-500 bg-[#faf8f6]">
                    <tr>
                        <th class="text-left px-4 py-2">System ID</th>
                        <th class="text-left px-4 py-2">Ref</th>
                        <th class="text-left px-4 py-2">User</th>
                        <th class="text-left px-4 py-2">Items × qty</th>
                        <th class="text-left px-4 py-2">Status</th>
                        <th class="text-left px-4 py-2">Tracking</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @foreach ($orders as $o)
                        @php $qty = $o->items->sum('quantity'); @endphp
                        <tr class="hover:bg-orange-50/40">
                            <td class="px-4 py-2.5">
                                <a href="{{ route('qr.show', ['systemId' => $o->system_id]) }}" class="font-mono text-orange-600 hover:underline">{{ $o->system_id }}</a>
                            </td>
                            <td class="px-4 py-2.5 text-neutral-600 text-xs">{{ $o->ref_id ?? '-' }}</td>
                            <td class="px-4 py-2.5 text-neutral-600 text-xs">{{ $o->user?->name }}</td>
                            <td class="px-4 py-2.5 font-semibold text-neutral-800">{{ $qty }}</td>
                            <td class="px-4 py-2.5">
                                <span class="px-2 py-0.5 rounded text-xs font-medium {{ $o->status === \App\Models\Order::STATUS_SHIPPED ? 'bg-green-100 text-green-700' : 'bg-neutral-100 text-neutral-600' }}">
                                    {{ $statusMap[$o->status] ?? $o->status }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-neutral-500 text-xs font-mono">{{ $o->tracking_id ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
