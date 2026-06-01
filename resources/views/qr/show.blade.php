@extends('layouts.app')
@section('title', 'Order ' . $order->system_id)

@section('content')
@php
    $statusMap = [0 => 'new_order', 1 => 'processing', 2 => 'wrongsize', 3 => 'fixed', 4 => 'reprint', 5 => 'onhold', 6 => 'shipped', 7 => 'cancelled'];

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

<div class="sticky top-0 z-20 bg-white/95 backdrop-blur border-b border-neutral-200 px-6 py-3">
    <div class="max-w-6xl mx-auto flex items-center gap-3">
        <label for="systemIdInput" class="text-xs font-semibold text-neutral-600 uppercase tracking-wider whitespace-nowrap">Scan / Type</label>
        <input
            type="text"
            id="systemIdInput"
            value="{{ $order->system_id }}"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            spellcheck="false"
            class="flex-1 px-3 py-2 bg-[#faf8f6] border border-neutral-200 rounded-lg text-neutral-800 text-sm font-mono focus:outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-400/20"
            placeholder="e.g. GC123"
        />
        <span id="scannerBadge"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold whitespace-nowrap bg-amber-50 text-amber-700 border border-amber-200"
              title="Browsers can't see HID scanners directly. We detect them by their characteristic fast burst of keystrokes (≥3 chars typed in <30ms each).">
            <span id="scannerDot" class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
            <span id="scannerLabel">Connecting…</span>
        </span>
        <span id="systemIdStatus" class="text-xs text-neutral-400 w-24 text-right"></span>
    </div>
</div>

<script>
(function () {
    const input = document.getElementById('systemIdInput');
    const statusEl = document.getElementById('systemIdStatus');
    const badge = document.getElementById('scannerBadge');
    const dot = document.getElementById('scannerDot');
    const label = document.getElementById('scannerLabel');
    const currentId = @json($order->system_id);
    let timer = null;

    function go(raw) {
        const id = (raw || '').trim();
        if (!id) return;
        if (id === currentId) {
            statusEl.textContent = '';
            return;
        }
        statusEl.textContent = 'Loading…';
        // Route by prefix: "GSL123" → gangsheet-label scan page; everything
        // else falls through to the per-order /qr page.
        if (/^GSL\d+$/i.test(id)) {
            window.location.href = '/gs/' + encodeURIComponent(id);
        } else {
            window.location.href = '/qr/' + encodeURIComponent(id);
        }
    }

    // Passive scanner detection: a hardware HID scanner sends keystrokes
    // dramatically faster than human typing. We watch the last few keydown
    // intervals and flip the badge once we see 3+ keystrokes each <30ms apart.
    let lastKeyAt = 0;
    let fastStreak = 0;
    let detected = false;
    let burstChars = '';        // accumulated chars of the current burst, for logging
    let burstStartedAt = 0;
    function flipDetected(reason) {
        if (detected) return;
        detected = true;
        badge.classList.remove('bg-amber-50', 'text-amber-700', 'border-amber-200');
        badge.classList.add('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');
        dot.classList.remove('bg-amber-500', 'animate-pulse');
        dot.classList.add('bg-emerald-500');
        label.textContent = 'Scanner connected';
        console.log('[qr-scan] ✅ Scanner DETECTED →', reason);
    }

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const v = input.value;
        statusEl.textContent = v.trim() && v.trim() !== currentId ? 'Waiting…' : '';
        timer = setTimeout(() => go(v), 350);
    });

    input.addEventListener('keydown', (e) => {
        // Detection runs on every printable key (ignore modifiers and Enter).
        if (e.key.length === 1) {
            const now = performance.now();
            const gap = lastKeyAt ? now - lastKeyAt : null;
            lastKeyAt = now;
            if (gap !== null && gap < 30) {
                if (fastStreak === 0) {
                    burstStartedAt = now;
                    burstChars = '';
                }
                fastStreak += 1;
                burstChars += e.key;
                console.log(`[qr-scan] key="${e.key}" gap=${gap.toFixed(1)}ms streak=${fastStreak} (FAST)`);
                if (fastStreak >= 3 && !detected) {
                    flipDetected(`burst of ${fastStreak} chars @ avg ${((now - burstStartedAt) / fastStreak).toFixed(1)}ms gap → "${burstChars}"`);
                }
            } else {
                if (fastStreak > 0) {
                    console.log(`[qr-scan] streak broken at ${fastStreak} chars ("${burstChars}"), gap=${gap?.toFixed(1) ?? '∞'}ms`);
                }
                console.log(`[qr-scan] key="${e.key}" gap=${gap === null ? '∞ (first)' : gap.toFixed(1) + 'ms'} streak=0`);
                fastStreak = 0;
                burstChars = '';
            }
        }

        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(timer);
            const value = input.value;
            console.log(`[qr-scan] ⏎ Enter → value="${value}" streak-before=${fastStreak}`);
            // Enter that closes a fast burst is the scanner's terminator — also
            // a strong signal it was a scanner, not a human typing.
            if (fastStreak >= 2 && !detected) {
                flipDetected(`Enter after burst of ${fastStreak} fast chars → "${burstChars}"`);
            }
            fastStreak = 0;
            burstChars = '';
            go(value);
        }
    });

    input.addEventListener('focus', () => {
        input.select();
        console.log('[qr-scan] 📥 input FOCUSED');
    });
    input.addEventListener('blur', () => {
        console.log('[qr-scan] 📤 input BLURRED (scanner keystrokes will go elsewhere unless global capture rescues them)');
    });

    // Select all on load so barcode scanners overwrite cleanly.
    setTimeout(() => { input.focus(); input.select(); }, 50);

    // Re-focus when the tab/window regains focus (alt-tab back, returning
    // from another app). Without this, scanner keystrokes get lost in body.
    window.addEventListener('focus', () => {
        console.log('[qr-scan] 🪟 window FOCUS event — refocusing input');
        input.focus();
        input.select();
    });

    // Global capture: even if the user clicked away and input lost focus,
    // we catch keystrokes at the window level, programmatically append the
    // character to the input, and refocus. Skip if focus is on another
    // editable field so we don't interfere with normal typing.
    //
    // Why we append manually instead of just calling input.focus(): refocus
    // happens asynchronously after the current keydown finishes propagating,
    // so the FIRST scanner key would still go to <body> and get lost. By
    // mutating input.value directly and dispatching an input event, we never
    // drop a character — even the very first one after print dialog closes.
    let pendingBurstStart = 0;
    window.addEventListener('keydown', (e) => {
        const active = document.activeElement;
        if (active === input) return;            // input handler already runs
        if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable)) return;
        if (e.ctrlKey || e.altKey || e.metaKey) return;

        if (e.key === 'Enter') {
            console.log('[qr-scan] 🌐 window-level Enter (input was not focused) → submit value="' + input.value + '"');
            e.preventDefault();
            input.focus();
            clearTimeout(timer);
            go(input.value);
            return;
        }
        if (e.key.length === 1) {
            e.preventDefault();
            // First char after focus loss: clear input so the new scan starts
            // clean instead of appending to the old systemId.
            const now = performance.now();
            if (now - pendingBurstStart > 100) {
                input.value = '';
            }
            pendingBurstStart = now;
            input.value += e.key;
            input.focus();
            // Fire input event so the 350ms debounced navigate kicks in even
            // if no Enter suffix is sent by the scanner.
            input.dispatchEvent(new Event('input', { bubbles: true }));
            console.log(`[qr-scan] 🌐 window-level key="${e.key}" appended to input (was not focused, active=${active?.tagName || 'none'}, value now="${input.value}")`);
        }
    }, true);
})();
</script>

<div class="max-w-6xl mx-auto p-6">
    @if (session('status_message'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
            {{ session('status_message') }}
        </div>
    @endif

    <div class="flex justify-between items-start mb-4 gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800">Order {{ $order->system_id }}</h1>
            @if ($order->ref_id)
                <p class="text-xs text-neutral-500 mt-1">Ref: <span class="font-mono">{{ $order->ref_id }}</span></p>
            @endif
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="px-2 py-0.5 bg-orange-100 text-orange-700 rounded font-medium">{{ $statusMap[$order->status] ?? $order->status }}</span>
                @if ($order->ship_type === 'stamp')
                    <span class="px-3 py-1 bg-amber-400 text-black rounded font-extrabold uppercase tracking-widest text-lg">★ STAMP ★</span>
                @else
                    <span class="px-2 py-0.5 bg-neutral-100 text-neutral-600 rounded">{{ $order->ship_type }}</span>
                @endif
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
        @php
            $totalQty = $order->items->sum(fn($i) => max(1, (int) ($i->quantity ?? 1)));
        @endphp
        <div class="bg-white rounded-xl border border-neutral-200 p-4 shadow-sm space-y-1.5 text-sm">
            <h2 class="font-semibold text-neutral-700 mb-2 text-sm">Order info</h2>
            <div class="flex justify-between"><span class="text-neutral-500">Tracking</span><span class="font-medium">{{ $order->tracking_id ?? '-' }}</span></div>
            <div class="flex justify-between items-center bg-orange-50 -mx-4 px-4 py-2 my-1 border-y border-orange-100">
                <span class="text-orange-700 text-sm font-semibold">Total Qty</span>
                <span class="font-extrabold text-orange-600 text-3xl leading-none">{{ $totalQty }}</span>
            </div>
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

        @php
            $isStaff = $user && ($user->hasRole('admin') || $user->hasRole('support'));
            // print_label is the source of truth for what gets printed: prefer
            // the converted overlay, fall back to the original carrier label.
            // Sellers only ever see the carrier label.
            $printLabel = ($isStaff && $order->convert_label) ? $order->convert_label : $order->shipping_label;
        @endphp
        @if ($isImageish($printLabel))
            <div class="bg-white rounded-xl border border-neutral-200 p-4 shadow-sm text-sm">
                <h2 class="font-semibold text-neutral-700 mb-2 text-sm">
                    Shipping label
                    @if ($isStaff && $order->convert_label)
                        <span class="ml-1 text-[10px] font-semibold text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded">CONVERTED</span>
                    @endif
                </h2>
                <a href="{{ $printLabel }}" target="_blank" rel="noreferrer" class="block">
                    <img src="{{ route('qr.label-image', ['systemId' => $order->system_id]) }}"
                        alt="shipping label"
                        class="w-full h-40 object-contain bg-neutral-50 rounded border border-neutral-200"
                        onerror="this.onerror=null; this.src='{{ $driveThumb($printLabel, 'w600') }}';" />
                </a>
                <p class="text-[10px] text-neutral-400 mt-1 truncate mb-3">{{ $printLabel }}</p>

                {{-- Ship action buttons next to the label for one-click marking after print --}}
                <div class="flex gap-2 pt-3 border-t border-neutral-100" data-system-id="{{ $order->system_id }}">
                    @php
                        $isShipped = $order->status === \App\Models\Order::STATUS_SHIPPED;
                        $isOnHold = $order->status === \App\Models\Order::STATUS_ONHOLD;
                    @endphp
                    <button type="button"
                        id="shipPrintBtn"
                        @if ($isShipped) disabled @endif
                        data-image-url="{{ route('qr.label-image', ['systemId' => $order->system_id]) }}"
                        data-status-url="{{ route('qr.update-status', ['systemId' => $order->system_id]) }}"
                        class="flex-1 px-4 py-2.5 rounded-lg font-bold text-sm border-2 transition shadow-sm
                               {{ $isShipped
                                  ? 'bg-green-100 text-green-700 border-green-200 cursor-not-allowed opacity-60'
                                  : 'bg-green-500 text-white border-green-600 hover:bg-green-600 hover:shadow-md' }}">
                        ✓ {{ $isShipped ? 'Shipped' : 'Ship & Print' }}
                    </button>
                    <button type="button"
                        id="printOnlyBtn"
                        data-image-url="{{ route('qr.label-image', ['systemId' => $order->system_id]) }}"
                        class="flex-1 px-4 py-2.5 rounded-lg font-bold text-sm border-2 transition shadow-sm
                               bg-white text-blue-600 border-blue-300 hover:bg-blue-50 hover:border-blue-400 hover:shadow-md">
                        🖨 Print Label
                    </button>
                    <form method="POST" action="{{ route('qr.update-status', ['systemId' => $order->system_id]) }}" class="flex-1">
                        @csrf
                        <input type="hidden" name="status" value="onhold" />
                        <button type="submit"
                            @if ($isOnHold) disabled @endif
                            class="w-full px-4 py-2.5 rounded-lg font-bold text-sm border-2 transition shadow-sm
                                   {{ $isOnHold
                                      ? 'bg-yellow-100 text-yellow-700 border-yellow-200 cursor-not-allowed opacity-60'
                                      : 'bg-yellow-500 text-white border-yellow-600 hover:bg-yellow-600 hover:shadow-md' }}">
                            ⏸ Mark On Hold
                        </button>
                    </form>
                </div>

                <script>
                    (function () {
                        const shipBtn = document.getElementById('shipPrintBtn');
                        const printBtn = document.getElementById('printOnlyBtn');
                        if ((!shipBtn || shipBtn.dataset.bound === '1') && (!printBtn || printBtn.dataset.bound === '1')) return;

                        const printLabel = (imageUrl) => {
                            // Reuse a single hidden iframe so repeated clicks don't pile up.
                            let iframe = document.getElementById('hiddenPrintFrame');
                            if (iframe) iframe.remove();
                            iframe = document.createElement('iframe');
                            iframe.id = 'hiddenPrintFrame';
                            iframe.setAttribute('aria-hidden', 'true');
                            iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
                            document.body.appendChild(iframe);

                            iframe.addEventListener('load', () => {
                                // The iframe document is the proxied label (image or PDF). Wait
                                // one tick so the browser finishes laying out the image, then
                                // print the iframe — this opens the native print dialog (Ctrl+P)
                                // on the current tab without spawning a new window.
                                setTimeout(() => {
                                    try {
                                        iframe.contentWindow.focus();
                                        iframe.contentWindow.print();
                                    } catch (e) {
                                        console.error('Print failed', e);
                                    }
                                }, 200);

                                // Clean up the iframe once the user dismisses the print dialog.
                                // After print, focus jumps to the iframe (we called
                                // contentWindow.focus() above) and then drops to <body>
                                // when the iframe is removed — that swallows the next
                                // scanner burst. Restore focus to the scan input.
                                const restoreScanFocus = () => {
                                    const scanInput = document.getElementById('systemIdInput');
                                    if (scanInput) { scanInput.focus(); scanInput.select(); }
                                };
                                try {
                                    iframe.contentWindow.addEventListener('afterprint', () => {
                                        setTimeout(() => {
                                            iframe.remove();
                                            restoreScanFocus();
                                            console.log('[qr-scan] 🖨 afterprint → systemIdInput refocused');
                                        }, 200);
                                    });
                                } catch (e) { /* same-origin, should not throw */ }
                                // Belt-and-suspenders: some browsers don't deliver
                                // afterprint reliably (especially if the user clicks
                                // outside the print preview to dismiss). Also refocus
                                // when the main window itself regains focus.
                                const onWinFocus = () => {
                                    restoreScanFocus();
                                    window.removeEventListener('focus', onWinFocus);
                                };
                                window.addEventListener('focus', onWinFocus);
                            }, { once: true });

                            // Cache-bust + invalidate poisoned cache for this URL once.
                            const sep = imageUrl.includes('?') ? '&' : '?';
                            const refreshOnce = sessionStorage.getItem('qrLabelRefreshed-{{ $order->system_id }}') ? '' : '&nocache=1';
                            sessionStorage.setItem('qrLabelRefreshed-{{ $order->system_id }}', '1');
                            iframe.src = imageUrl + sep + '_=' + Date.now() + refreshOnce;
                        };

                        if (printBtn && printBtn.dataset.bound !== '1') {
                            printBtn.dataset.bound = '1';
                            printBtn.addEventListener('click', () => printLabel(printBtn.dataset.imageUrl));
                        }

                        if (shipBtn && shipBtn.dataset.bound !== '1') {
                            shipBtn.dataset.bound = '1';
                            shipBtn.addEventListener('click', async () => {
                                if (shipBtn.disabled) return;

                                // Trigger Ctrl+P on the current page via hidden iframe.
                                printLabel(shipBtn.dataset.imageUrl);

                                shipBtn.disabled = true;
                                const orig = shipBtn.innerHTML;
                                shipBtn.innerHTML = '… marking shipped';

                                try {
                                    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                                        || document.querySelector('input[name="_token"]')?.value;

                                    const res = await fetch(shipBtn.dataset.statusUrl, {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: {
                                            'X-Requested-With': 'XMLHttpRequest',
                                            'Accept': 'application/json',
                                            'Content-Type': 'application/x-www-form-urlencoded',
                                            'X-CSRF-TOKEN': csrf || '',
                                        },
                                        body: 'status=shipped&_token=' + encodeURIComponent(csrf || ''),
                                    });
                                    if (!res.ok) throw new Error('HTTP ' + res.status);

                                    shipBtn.innerHTML = '✓ Shipped';
                                    shipBtn.classList.remove('bg-green-500', 'text-white', 'border-green-600', 'hover:bg-green-600');
                                    shipBtn.classList.add('bg-green-100', 'text-green-700', 'border-green-200', 'cursor-not-allowed', 'opacity-60');
                                } catch (err) {
                                    shipBtn.disabled = false;
                                    shipBtn.innerHTML = orig;
                                    alert('Could not mark shipped: ' + err.message);
                                }
                            });
                        }
                    })();
                </script>
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
                                <span class="text-neutral-500 font-normal">—
                                    {{ $variant->paper_type ?: $variant->color }}
                                    @if ($variant->size) / {{ $variant->size }} @endif
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-neutral-500 mt-0.5 flex flex-wrap items-center gap-2">
                            @if ($variant?->sku)
                                <span class="font-mono">SKU: {{ $variant->sku }}</span>
                            @endif
                            <span>Qty: <span class="font-semibold text-neutral-700">{{ $qty }}</span></span>
                            @if ($item->accessoryPrice)
                                @php
                                    $style = trim((string) ($item->accessoryPrice->style ?? ''));
                                    $styleColor = match (strtolower($style)) {
                                        'pink' => 'bg-pink-100 text-pink-700 border border-pink-300',
                                        'red' => 'bg-red-100 text-red-700 border border-red-300',
                                        'white' => 'bg-white text-neutral-800 border border-neutral-400',
                                        'brown' => 'bg-amber-100 text-amber-800 border border-amber-400',
                                        'black' => 'bg-neutral-800 text-white border border-neutral-900',
                                        'blue' => 'bg-blue-100 text-blue-700 border border-blue-300',
                                        'green' => 'bg-green-100 text-green-700 border border-green-300',
                                        'yellow' => 'bg-yellow-100 text-yellow-800 border border-yellow-400',
                                        default => 'bg-neutral-100 text-neutral-700 border border-neutral-300',
                                    };
                                @endphp
                                <span class="text-neutral-700 inline-flex items-center gap-1.5">
                                    Accessory: {{ $item->accessoryPrice->accessory?->name }}
                                    @if ($style)
                                        / <span class="px-2 py-0.5 rounded {{ $styleColor }} font-extrabold text-base uppercase tracking-wide">{{ $style }}</span>
                                    @endif
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
