@extends('layouts.app')
@section('title', 'BullStart — The seller workspace for Etsy listings and order fulfillment')

@section('content')
<style>
    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
    .gradient-hero {
        background:
            radial-gradient(60rem 60rem at 80% -10%, rgba(255,138,76,0.18), transparent 60%),
            radial-gradient(50rem 50rem at -10% 30%, rgba(255,196,140,0.18), transparent 55%),
            linear-gradient(180deg, #fffaf5 0%, #f5f0eb 100%);
    }
    .grid-bg {
        background-image:
            linear-gradient(rgba(0,0,0,0.04) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0,0,0,0.04) 1px, transparent 1px);
        background-size: 32px 32px;
        mask-image: radial-gradient(ellipse 80% 60% at 50% 0%, #000 30%, transparent 80%);
        -webkit-mask-image: radial-gradient(ellipse 80% 60% at 50% 0%, #000 30%, transparent 80%);
    }
    .glass {
        background: rgba(255,255,255,0.7);
        backdrop-filter: saturate(160%) blur(10px);
        -webkit-backdrop-filter: saturate(160%) blur(10px);
    }
    .shadow-soft { box-shadow: 0 1px 2px rgba(0,0,0,0.04), 0 8px 32px -8px rgba(0,0,0,0.08); }
    .shadow-soft-lg { box-shadow: 0 2px 4px rgba(0,0,0,0.04), 0 24px 64px -16px rgba(0,0,0,0.12); }
    .ring-glow { box-shadow: 0 0 0 1px rgba(255,138,76,0.4), 0 8px 32px -4px rgba(255,138,76,0.25); }
    /* Mock dashboard skeleton bars */
    .skel { background: linear-gradient(90deg, #f3eee9 0%, #faf6f1 50%, #f3eee9 100%); border-radius: 4px; }
</style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

{{-- ============================== STICKY NAV ============================== --}}
<nav class="sticky top-0 z-50 glass border-b border-neutral-200/60">
    <div class="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between">
        <a href="/" class="flex items-center">
            <img src="{{ asset('logo.png') }}" alt="BullStart" class="h-9 w-auto" />
        </a>
        <nav class="hidden md:flex items-center gap-7 text-sm font-medium">
            <a href="#features" class="text-neutral-600 hover:text-neutral-900 transition">Features</a>
            <a href="#how" class="text-neutral-600 hover:text-neutral-900 transition">How it works</a>
            <a href="#security" class="text-neutral-600 hover:text-neutral-900 transition">Security</a>
            <a href="#faq" class="text-neutral-600 hover:text-neutral-900 transition">FAQ</a>
        </nav>
        <div class="flex items-center gap-2">
            <a href="{{ route('login') }}" class="text-sm font-semibold text-neutral-700 hover:text-neutral-900 px-3 py-1.5 transition">Sign in</a>
            <a href="#contact" class="text-sm font-semibold text-white bg-neutral-900 hover:bg-neutral-800 px-4 py-2 rounded-lg transition shadow-soft">Request access</a>
        </div>
    </div>
</nav>

{{-- ============================== HERO ============================== --}}
<section class="gradient-hero relative overflow-hidden">
    <div class="absolute inset-0 grid-bg pointer-events-none"></div>
    <div class="relative max-w-6xl mx-auto px-6 pt-20 pb-24 md:pt-28 md:pb-32 text-center">
        <span class="inline-flex items-center gap-2 px-3 py-1 bg-white/80 border border-orange-200 text-orange-700 text-xs font-bold rounded-full mb-6 shadow-soft">
            <span class="w-1.5 h-1.5 bg-orange-500 rounded-full"></span>
            SELLER TOOL · OAUTH 2.0 · API-COMPLIANT
        </span>
        <h1 class="text-5xl md:text-7xl font-extrabold text-neutral-900 leading-[1.05] tracking-tight mb-6">
            Run your Etsy shop<br />
            <span class="bg-gradient-to-r from-orange-500 to-orange-700 bg-clip-text text-transparent">from one workspace.</span>
        </h1>
        <p class="text-lg md:text-xl text-neutral-600 max-w-2xl mx-auto mb-10 leading-relaxed">
            BullStart syncs your listings, fulfills your open orders, prints packing labels, and
            posts tracking back to Etsy — so your team can ship faster without juggling tabs.
        </p>
        <div class="flex flex-col sm:flex-row justify-center gap-3 mb-14">
            <a href="#contact" class="px-6 py-3.5 bg-neutral-900 hover:bg-neutral-800 text-white rounded-xl font-semibold text-sm shadow-soft-lg transition">
                Request early access →
            </a>
            <a href="#how" class="px-6 py-3.5 bg-white hover:bg-neutral-50 text-neutral-800 rounded-xl font-semibold text-sm border border-neutral-300 shadow-soft transition">
                See how it works
            </a>
        </div>

        {{-- Trust row --}}
        <div class="flex flex-wrap justify-center items-center gap-x-8 gap-y-3 text-xs font-semibold text-neutral-500 uppercase tracking-wider">
            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                OAuth 2.0
            </span>
            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Encrypted storage
            </span>
            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Seller-initiated writes
            </span>
            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                No buyer surface
            </span>
        </div>
    </div>

    {{-- Hero mock dashboard --}}
    <div class="relative max-w-5xl mx-auto px-6 pb-24">
        <div class="bg-white rounded-2xl border border-neutral-200 shadow-soft-lg overflow-hidden">
            {{-- browser chrome --}}
            <div class="flex items-center gap-1.5 px-4 py-3 bg-neutral-50 border-b border-neutral-200">
                <span class="w-3 h-3 rounded-full bg-red-400"></span>
                <span class="w-3 h-3 rounded-full bg-yellow-400"></span>
                <span class="w-3 h-3 rounded-full bg-green-400"></span>
                <div class="ml-4 px-3 py-1 bg-white border border-neutral-200 rounded text-[11px] text-neutral-500 font-mono">app.bullstart.us/orders</div>
            </div>
            {{-- mock dashboard --}}
            <div class="grid grid-cols-12 gap-0 h-[360px]">
                {{-- sidebar --}}
                <div class="col-span-2 bg-[#faf6f1] border-r border-neutral-200 p-4 space-y-2">
                    <div class="flex items-center gap-2 mb-3">
                        <div class="w-6 h-6 rounded bg-orange-500"></div>
                        <div class="skel h-3 w-16"></div>
                    </div>
                    <div class="flex items-center gap-2 p-2 rounded bg-orange-100"><div class="skel h-2 w-14 bg-orange-200"></div></div>
                    <div class="flex items-center gap-2 p-2"><div class="skel h-2 w-16"></div></div>
                    <div class="flex items-center gap-2 p-2"><div class="skel h-2 w-12"></div></div>
                    <div class="flex items-center gap-2 p-2"><div class="skel h-2 w-14"></div></div>
                    <div class="flex items-center gap-2 p-2"><div class="skel h-2 w-10"></div></div>
                </div>
                {{-- main --}}
                <div class="col-span-10 p-5 space-y-3">
                    <div class="flex items-center justify-between mb-2">
                        <div class="skel h-4 w-32"></div>
                        <div class="flex gap-2">
                            <div class="skel h-7 w-20"></div>
                            <div class="bg-orange-500 rounded-md h-7 w-24"></div>
                        </div>
                    </div>
                    {{-- order rows --}}
                    @php $rows = [['PS_C115','Shipped','#15803d'],['PS_C116','Processing','#b45309'],['PS_C117','New','#1d4ed8'],['PS_C118','New','#1d4ed8'],['PS_C119','Processing','#b45309'],['PS_C120','New','#1d4ed8']]; @endphp
                    @foreach ($rows as $r)
                        <div class="grid grid-cols-12 gap-3 items-center py-2 border-b border-neutral-100">
                            <div class="col-span-2 font-mono text-[11px] text-orange-600 font-bold">{{ $r[0] }}</div>
                            <div class="col-span-3 skel h-2"></div>
                            <div class="col-span-2 skel h-2"></div>
                            <div class="col-span-3 skel h-2"></div>
                            <div class="col-span-2 text-right">
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" style="color:{{ $r[2] }};background-color:{{ $r[2] }}1A">{{ $r[1] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        {{-- ambient glow --}}
        <div class="absolute -inset-x-10 -bottom-10 h-32 bg-gradient-to-t from-orange-200/40 to-transparent blur-3xl -z-10"></div>
    </div>
</section>

{{-- ============================== STAT BAR ============================== --}}
<section class="border-y border-neutral-200 bg-white">
    <div class="max-w-6xl mx-auto px-6 py-12 grid grid-cols-2 md:grid-cols-4 gap-8 text-center">
        @php
            $stats = [
                ['n' => '< 60s', 'l' => 'From scan to "shipped" on Etsy'],
                ['n' => '5', 'l' => 'OAuth scopes — no more, no less'],
                ['n' => '100%', 'l' => 'Server-side token storage'],
                ['n' => '0', 'l' => 'Listings created without seller approval'],
            ];
        @endphp
        @foreach ($stats as $s)
            <div>
                <div class="text-3xl md:text-4xl font-extrabold text-neutral-900 tracking-tight">{{ $s['n'] }}</div>
                <div class="text-xs md:text-sm text-neutral-500 mt-1 font-medium">{{ $s['l'] }}</div>
            </div>
        @endforeach
    </div>
</section>

{{-- ============================== FEATURES ============================== --}}
<section id="features" class="py-24 bg-[#faf6f1]">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-14">
            <span class="text-xs font-bold uppercase tracking-widest text-orange-600">Features</span>
            <h2 class="text-3xl md:text-4xl font-extrabold text-neutral-900 mt-2 mb-3 tracking-tight">Both ends of the workflow</h2>
            <p class="text-neutral-600 max-w-2xl mx-auto">From the moment a buyer hits Buy on Etsy to the moment the package leaves the warehouse — BullStart covers your catalog and your fulfillment floor.</p>
        </div>

        @php
            $featureGroups = [
                [
                    'kicker' => 'Catalog',
                    'title' => 'Sync, edit, and push back',
                    'desc' => 'Your existing Etsy listings appear in BullStart. Edit inventory, price, title, description, and images in bulk. When you click Save, the change is written back to Etsy through the official API.',
                    'features' => ['Listing sync', 'Bulk inventory edits', 'Variation manager', 'Image gallery sync'],
                    'icon' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>',
                ],
                [
                    'kicker' => 'Fulfillment',
                    'title' => 'From open order to shipped',
                    'desc' => 'Filter open orders, batch designs onto gangsheets, scan a Code 128 barcode at the QC station, print the carrier label, and BullStart posts the tracking number back to Etsy. The buyer gets Etsy\'s standard shipped email — exactly as if you\'d marked it shipped on Etsy.',
                    'features' => ['Unified order list', 'Gangsheet batcher', 'QR-scan QC station', 'Tracking write-back'],
                    'icon' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>',
                ],
                [
                    'kicker' => 'Control',
                    'title' => 'Seller-initiated, always reversible',
                    'desc' => 'BullStart never edits your listings autonomously. Every write — to a listing or an order — happens only after a human in your team clicks Save. Revoke our access from Etsy settings any time; we honor the revocation immediately and stop polling.',
                    'features' => ['No autonomous writes', 'Audit-friendly log', 'One-click revocation', 'Per-user role gates'],
                    'icon' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>',
                ],
            ];
        @endphp

        <div class="space-y-10">
            @foreach ($featureGroups as $i => $g)
                <div class="bg-white rounded-3xl border border-neutral-200 shadow-soft p-8 md:p-10 grid md:grid-cols-5 gap-8 items-center">
                    <div class="md:col-span-3">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-11 h-11 rounded-xl bg-orange-100 text-orange-600 flex items-center justify-center">{!! $g['icon'] !!}</div>
                            <span class="text-xs font-bold uppercase tracking-widest text-orange-600">{{ $g['kicker'] }}</span>
                        </div>
                        <h3 class="text-2xl md:text-3xl font-extrabold text-neutral-900 mb-3 tracking-tight">{{ $g['title'] }}</h3>
                        <p class="text-neutral-600 leading-relaxed mb-5">{{ $g['desc'] }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($g['features'] as $f)
                                <span class="text-xs font-semibold text-neutral-700 bg-neutral-100 px-2.5 py-1 rounded-md">{{ $f }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        {{-- decorative mock card --}}
                        <div class="bg-[#faf6f1] rounded-2xl border border-neutral-200 p-4 space-y-2.5">
                            @for ($k = 0; $k < 4; $k++)
                                <div class="flex items-center justify-between bg-white rounded-lg border border-neutral-200 px-3 py-2.5">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-7 h-7 rounded bg-gradient-to-br from-orange-100 to-orange-200"></div>
                                        <div>
                                            <div class="skel h-2 w-20 mb-1"></div>
                                            <div class="skel h-1.5 w-12"></div>
                                        </div>
                                    </div>
                                    <div class="w-3 h-3 rounded-full bg-emerald-400"></div>
                                </div>
                            @endfor
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================== HOW IT WORKS ============================== --}}
<section id="how" class="py-24 bg-white">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-14">
            <span class="text-xs font-bold uppercase tracking-widest text-orange-600">How it works</span>
            <h2 class="text-3xl md:text-4xl font-extrabold text-neutral-900 mt-2 mb-3 tracking-tight">OAuth from end to end</h2>
            <p class="text-neutral-600 max-w-2xl mx-auto">No screen scraping. No shared credentials. Just the official Etsy Open API v3 with the seller's consent.</p>
        </div>

        @php
            $steps = [
                ['t' => 'Connect your shop', 'd' => 'Click "Connect Etsy shop" in BullStart and you\'re sent to Etsy\'s OAuth 2.0 authorization page.'],
                ['t' => 'Approve scopes', 'd' => 'Review the five scopes BullStart needs (listings_r, listings_w, transactions_r, transactions_w, shops_r) and approve them on Etsy.'],
                ['t' => 'Sync your catalog', 'd' => 'BullStart performs a one-time pull of your listings and open orders into your private workspace.'],
                ['t' => 'Edit listings', 'd' => 'Update inventory, price, title, description, and images inside BullStart. Click Save to push the change back to Etsy.'],
                ['t' => 'Print &amp; ship', 'd' => 'Scan a Code 128 barcode at the QC station, print the carrier label, and BullStart marks the order shipped.'],
                ['t' => 'Tracking write-back', 'd' => 'BullStart posts the tracking number to Etsy via the API. The buyer receives Etsy\'s standard shipped notification.'],
            ];
        @endphp

        <div class="grid md:grid-cols-3 gap-6 mb-14">
            @foreach ($steps as $i => $s)
                <div class="relative bg-[#faf6f1] rounded-2xl border border-neutral-200 p-6">
                    <div class="absolute -top-3 -left-3 w-9 h-9 rounded-xl bg-orange-500 text-white flex items-center justify-center font-extrabold text-sm shadow-soft">{{ $i + 1 }}</div>
                    <h3 class="font-bold text-neutral-900 mb-2 mt-1">{!! $s['t'] !!}</h3>
                    <p class="text-sm text-neutral-600 leading-relaxed">{!! $s['d'] !!}</p>
                </div>
            @endforeach
        </div>

        {{-- Scopes table --}}
        <div class="bg-neutral-900 rounded-2xl p-7 md:p-9 text-white">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-10 h-10 rounded-lg bg-orange-500/20 text-orange-400 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <div>
                    <h3 class="font-bold text-lg">OAuth 2.0 scopes we request</h3>
                    <p class="text-sm text-neutral-400">The minimum scopes needed — nothing more.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-widest text-neutral-500 border-b border-white/10">
                        <tr>
                            <th class="text-left py-3 pr-6 font-semibold">Scope</th>
                            <th class="text-left py-3 font-semibold">Purpose</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <tr><td class="py-3 pr-6 font-mono text-orange-300">listings_r</td><td class="py-3 text-neutral-300">Read the seller's existing listings into the BullStart catalog.</td></tr>
                        <tr><td class="py-3 pr-6 font-mono text-orange-300">listings_w</td><td class="py-3 text-neutral-300">Save listing edits (inventory, price, title, description, images) back to Etsy when the seller clicks Save.</td></tr>
                        <tr><td class="py-3 pr-6 font-mono text-orange-300">transactions_r</td><td class="py-3 text-neutral-300">Fetch open orders for fulfillment.</td></tr>
                        <tr><td class="py-3 pr-6 font-mono text-orange-300">transactions_w</td><td class="py-3 text-neutral-300">Post tracking numbers and shipped status when a package leaves the warehouse.</td></tr>
                        <tr><td class="py-3 pr-6 font-mono text-orange-300">shops_r</td><td class="py-3 text-neutral-300">Read shop metadata (name, country, timezone) to render the dashboard correctly.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

{{-- ============================== SECURITY ============================== --}}
<section id="security" class="py-24 bg-[#faf6f1]">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-14">
            <span class="text-xs font-bold uppercase tracking-widest text-orange-600">Security &amp; Privacy</span>
            <h2 class="text-3xl md:text-4xl font-extrabold text-neutral-900 mt-2 mb-3 tracking-tight">Your shop's data, handled carefully</h2>
            <p class="text-neutral-600 max-w-2xl mx-auto">We follow Etsy's API Terms of Use and only access the data the seller has explicitly authorized.</p>
        </div>

        @php
            $sec = [
                ['icon' => '👁', 'title' => 'What we read', 'desc' => 'Listings (title, price, inventory, description, images, variations), open orders, line items, shipping addresses, and shop metadata. We do not read messages, reviews, or buyer profile data.'],
                ['icon' => '✏️', 'title' => 'What we write', 'desc' => 'Listing edits and tracking numbers — only when a human in your team clicks Save or marks an order fulfilled. We never write to a listing or an order autonomously.'],
                ['icon' => '🔐', 'title' => 'How we store it', 'desc' => 'Order and listing data lives in our private database for the lifetime of your account. Access tokens are stored server-side and never exposed to the browser.'],
                ['icon' => '🗑', 'title' => 'How you delete it', 'desc' => 'Email us and we remove your data within seven business days, revoke our token with Etsy, and confirm the deletion in writing.'],
                ['icon' => '⏱', 'title' => 'How we cache', 'desc' => 'Listing, shop, and user data is cached only as long as the Etsy API caching policy allows. We re-fetch instead of relying on stale data.'],
                ['icon' => '🚫', 'title' => 'What we never do', 'desc' => 'We never sell, lease, or share seller or buyer data with third parties. We do not screen-scrape Etsy. We do not run a buyer-facing storefront.'],
            ];
        @endphp

        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ($sec as $s)
                <div class="bg-white rounded-2xl border border-neutral-200 p-6 shadow-soft">
                    <div class="text-2xl mb-3">{{ $s['icon'] }}</div>
                    <h3 class="font-bold text-neutral-900 mb-1.5">{{ $s['title'] }}</h3>
                    <p class="text-sm text-neutral-600 leading-relaxed">{{ $s['desc'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================== FAQ ============================== --}}
<section id="faq" class="py-24 bg-white">
    <div class="max-w-3xl mx-auto px-6">
        <div class="text-center mb-12">
            <span class="text-xs font-bold uppercase tracking-widest text-orange-600">FAQ</span>
            <h2 class="text-3xl md:text-4xl font-extrabold text-neutral-900 mt-2 mb-3 tracking-tight">Questions, answered</h2>
        </div>

        @php
            $faqs = [
                ['q' => 'Is BullStart endorsed by Etsy?', 'a' => 'No. BullStart is an independent seller tool that uses the public Etsy Open API v3. The term "Etsy" is a trademark of Etsy, Inc. This application uses the Etsy API but is not endorsed or certified by Etsy, Inc.'],
                ['q' => 'Do you take a cut of my sales?', 'a' => 'No. BullStart does not touch the buyer payment flow — buyers continue to check out on Etsy. We are not commercial software; we do not charge a per-order fee.'],
                ['q' => 'Can buyers see BullStart?', 'a' => 'No. There is no buyer-facing surface. BullStart only renders inside the seller\'s workspace and on the production-floor desktop app.'],
                ['q' => 'Will BullStart change my listings without asking?', 'a' => 'Never. Every write happens after a human in your team clicks Save inside BullStart. There are no scheduled or autonomous edits.'],
                ['q' => 'How do I revoke access?', 'a' => 'Open your Etsy account settings → Apps & Permissions → BullStart → Revoke. We stop polling and writing the moment Etsy returns an invalid-token response.'],
                ['q' => 'Where can I delete my data?', 'a' => 'Email us at the address below. We remove your records and confirm the deletion within seven business days.'],
                ['q' => 'Do you support more than 5 shops?', 'a' => 'Our current API key is approved for Personal Access (up to 5 shops). Once we onboard a sixth shop we will request Commercial Access from Etsy.'],
            ];
        @endphp

        <div class="space-y-3">
            @foreach ($faqs as $f)
                <details class="bg-[#faf6f1] rounded-xl border border-neutral-200 group">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer list-none">
                        <span class="font-semibold text-neutral-900">{{ $f['q'] }}</span>
                        <svg class="w-5 h-5 text-neutral-400 group-open:rotate-180 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-neutral-600 leading-relaxed">{{ $f['a'] }}</div>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================== CTA ============================== --}}
<section id="contact" class="py-20 bg-neutral-900 relative overflow-hidden">
    <div class="absolute inset-0 grid-bg opacity-20"></div>
    <div class="relative max-w-3xl mx-auto px-6 text-center text-white">
        <h2 class="text-3xl md:text-5xl font-extrabold tracking-tight mb-4">Want to bring your shop on?</h2>
        <p class="text-neutral-300 text-lg mb-8 max-w-xl mx-auto">
            BullStart is currently in limited rollout to our team and a small group of partner Etsy
            sellers. Drop us a note — we'll reply within one business day.
        </p>
        <a href="mailto:thanhp0312@gmail.com" class="inline-flex items-center gap-2 px-6 py-3.5 bg-orange-500 hover:bg-orange-600 text-white rounded-xl font-bold text-sm shadow-soft-lg transition">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            thanhp0312@gmail.com
        </a>
        <p class="text-neutral-500 text-xs mt-6">Team BullStart · United States</p>
    </div>
</section>

{{-- ============================== FOOTER ============================== --}}
<footer class="bg-[#faf6f1] border-t border-neutral-200">
    <div class="max-w-6xl mx-auto px-6 py-10 grid md:grid-cols-4 gap-8 text-sm">
        <div class="md:col-span-2">
            <img src="{{ asset('logo.png') }}" alt="BullStart" class="h-10 w-auto mb-4" />
            <p class="text-xs text-neutral-500 leading-relaxed max-w-sm">
                The term &ldquo;Etsy&rdquo; is a trademark of Etsy, Inc.
                This application uses the Etsy API but is not endorsed or certified by Etsy, Inc.
            </p>
        </div>
        <div>
            <h4 class="text-xs font-bold uppercase tracking-widest text-neutral-500 mb-3">Product</h4>
            <ul class="space-y-1.5 text-neutral-600">
                <li><a href="#features" class="hover:text-neutral-900">Features</a></li>
                <li><a href="#how" class="hover:text-neutral-900">How it works</a></li>
                <li><a href="#security" class="hover:text-neutral-900">Security</a></li>
                <li><a href="#faq" class="hover:text-neutral-900">FAQ</a></li>
            </ul>
        </div>
        <div>
            <h4 class="text-xs font-bold uppercase tracking-widest text-neutral-500 mb-3">Company</h4>
            <ul class="space-y-1.5 text-neutral-600">
                <li><a href="#contact" class="hover:text-neutral-900">Contact</a></li>
                <li><a href="mailto:thanhp0312@gmail.com" class="hover:text-neutral-900">Email us</a></li>
                <li><a href="{{ route('login') }}" class="hover:text-neutral-900">Sign in</a></li>
            </ul>
        </div>
    </div>
    <div class="border-t border-neutral-200">
        <div class="max-w-6xl mx-auto px-6 py-4 flex flex-col md:flex-row justify-between items-center gap-2 text-xs text-neutral-500">
            <span>&copy; {{ date('Y') }} BullStart. All rights reserved.</span>
            <span>Built in the United States · API-compliant seller tool</span>
        </div>
    </div>
</footer>
@endsection
