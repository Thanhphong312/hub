@extends('layouts.marketing')
@section('title', 'BullStart — The seller workspace for Etsy listings and order fulfillment')
@section('meta_description', 'BullStart syncs your Etsy listings, fulfills open orders, prints packing slips, and posts tracking back to Etsy — all from one workspace. Free during early access.')

@section('content')

{{-- ============================== HERO ============================== --}}
<section class="relative">
    <div class="relative max-w-6xl mx-auto px-6 pt-20 pb-16 md:pt-28 md:pb-20 text-center">
        <span class="inline-flex items-center gap-2 px-3 py-1 bg-white/5 border border-[#FFA526]/40 text-[#FFC565] text-xs font-bold rounded-full mb-6"
              style="box-shadow:0 0 20px rgba(255,165,38,0.12);">
            <span class="w-1.5 h-1.5 bg-[#FFA526] rounded-full"></span>
            SELLER TOOL · OAUTH 2.0 · API-COMPLIANT
        </span>
        <h1 class="text-5xl md:text-7xl font-extrabold text-white leading-[1.05] tracking-tight mb-6">
            Run your Etsy shop<br />
            <span class="text-gradient glow-text">from one workspace.</span>
        </h1>
        <p class="text-lg md:text-xl text-neutral-400 max-w-2xl mx-auto mb-10 leading-relaxed">
            BullStart syncs your listings, fulfills your open orders, prints packing slips, and
            posts tracking back to Etsy — so you can ship faster without juggling tabs.
            <span class="inline-block mt-2 px-2 py-0.5 bg-emerald-500/10 text-emerald-400 text-sm font-semibold rounded border border-emerald-500/20">Free during early access.</span>
        </p>
        <div class="flex flex-col sm:flex-row justify-center gap-3 mb-14">
            <a href="#contact" class="px-6 py-3.5 btn-glow rounded-xl font-semibold text-sm">
                Request early access →
            </a>
            <a href="#how" class="px-6 py-3.5 btn-ghost rounded-xl font-semibold text-sm">
                See how it works
            </a>
        </div>

        {{-- Trust row --}}
        <div class="flex flex-wrap justify-center items-center gap-x-8 gap-y-3 text-xs font-semibold text-neutral-500 uppercase tracking-wider">
            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                OAuth 2.0
            </span>
            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Encrypted storage
            </span>
            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Seller-initiated writes
            </span>
            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                No buyer surface
            </span>
        </div>
    </div>

    {{-- Hero mock dashboard --}}
    <div class="relative max-w-5xl mx-auto px-6 pb-24">
        <div class="card-dark rounded-2xl overflow-hidden" style="box-shadow:0 0 60px rgba(255,165,38,0.10), 0 24px 64px -16px rgba(0,0,0,0.6);">
            {{-- browser chrome --}}
            <div class="flex items-center gap-1.5 px-4 py-3 bg-[#0F0F0F] border-b border-white/5">
                <span class="w-3 h-3 rounded-full bg-red-400/80"></span>
                <span class="w-3 h-3 rounded-full bg-yellow-400/80"></span>
                <span class="w-3 h-3 rounded-full bg-green-400/80"></span>
                <div class="ml-4 px-3 py-1 bg-black/40 border border-white/5 rounded text-[11px] text-neutral-500 font-mono">app.bullstart.us/orders</div>
            </div>
            {{-- mock dashboard --}}
            <div class="grid grid-cols-12 gap-0 h-[360px] bg-[#101010]">
                {{-- sidebar --}}
                <div class="col-span-2 bg-[#0c0c0c] border-r border-white/5 p-4 space-y-2">
                    <div class="flex items-center gap-2 mb-3">
                        <div class="w-6 h-6 rounded bg-[#FFA526]" style="box-shadow:0 0 12px rgba(255,165,38,0.5);"></div>
                        <div class="skel h-3 w-16"></div>
                    </div>
                    <div class="flex items-center gap-2 p-2 rounded bg-[#FFA526]/10 border border-[#FFA526]/20"><div class="h-2 w-14 rounded" style="background:#FFA526;opacity:.6;"></div></div>
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
                            <div class="rounded-md h-7 w-24" style="background:#FFA526;box-shadow:0 0 14px rgba(255,165,38,0.4);"></div>
                        </div>
                    </div>
                    {{-- order rows --}}
                    @php $rows = [['PS_C115','Shipped','#34d399'],['PS_C116','Processing','#fbbf24'],['PS_C117','New','#60a5fa'],['PS_C118','New','#60a5fa'],['PS_C119','Processing','#fbbf24'],['PS_C120','New','#60a5fa']]; @endphp
                    @foreach ($rows as $r)
                        <div class="grid grid-cols-12 gap-3 items-center py-2 border-b border-white/5">
                            <div class="col-span-2 font-mono text-[11px] text-[#FFC565] font-bold">{{ $r[0] }}</div>
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
        <div class="absolute -inset-x-10 -bottom-10 h-40 bg-[#FFA526]/20 blur-3xl -z-10"></div>
    </div>
</section>

{{-- ============================== STAT BAR ============================== --}}
<section class="border-y border-white/5 bg-white/[0.015]">
    <div class="max-w-6xl mx-auto px-6 py-12 grid grid-cols-2 md:grid-cols-4 gap-8 text-center">
        @php
            $stats = [
                ['n' => '< 60s', 'l' => 'From order to "shipped" on Etsy'],
                ['n' => '5', 'l' => 'OAuth scopes — no more, no less'],
                ['n' => '100%', 'l' => 'Server-side token storage'],
                ['n' => '0', 'l' => 'Listings created without seller approval'],
            ];
        @endphp
        @foreach ($stats as $s)
            <div>
                <div class="text-3xl md:text-4xl font-extrabold text-white tracking-tight glow-text">{{ $s['n'] }}</div>
                <div class="text-xs md:text-sm text-neutral-500 mt-1 font-medium">{{ $s['l'] }}</div>
            </div>
        @endforeach
    </div>
</section>

{{-- ============================== FEATURES ============================== --}}
<section id="features" class="py-24">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-14">
            <span class="text-xs font-bold uppercase tracking-widest text-[#FFA526]">Features</span>
            <h2 class="text-3xl md:text-4xl font-extrabold text-white mt-2 mb-3 tracking-tight">Both ends of the workflow</h2>
            <p class="text-neutral-400 max-w-2xl mx-auto">From the moment a buyer hits Buy on Etsy to the moment the package leaves the warehouse — BullStart covers your catalog and your fulfillment floor.</p>
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
                    'desc' => 'Filter open orders, generate a print-ready packing slip with one click, mark orders shipped inside BullStart — and we post the tracking number back to Etsy. The buyer gets Etsy\'s standard shipped email, exactly as if you\'d marked it shipped on Etsy.',
                    'features' => ['Unified order list', 'One-click packing slips', 'Tracking write-back', 'Shipped status sync'],
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
                <div class="card-dark rounded-3xl p-8 md:p-10 grid md:grid-cols-5 gap-8 items-center">
                    <div class="md:col-span-3">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-11 h-11 rounded-xl bg-[#FFA526]/10 text-[#FFA526] border border-[#FFA526]/20 flex items-center justify-center">{!! $g['icon'] !!}</div>
                            <span class="text-xs font-bold uppercase tracking-widest text-[#FFA526]">{{ $g['kicker'] }}</span>
                        </div>
                        <h3 class="text-2xl md:text-3xl font-extrabold text-white mb-3 tracking-tight">{{ $g['title'] }}</h3>
                        <p class="text-neutral-400 leading-relaxed mb-5">{{ $g['desc'] }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($g['features'] as $f)
                                <span class="text-xs font-semibold text-neutral-300 bg-white/5 border border-white/10 px-2.5 py-1 rounded-md">{{ $f }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        {{-- decorative mock card --}}
                        <div class="card-soft rounded-2xl p-4 space-y-2.5">
                            @for ($k = 0; $k < 4; $k++)
                                <div class="flex items-center justify-between bg-black/30 rounded-lg border border-white/5 px-3 py-2.5">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-7 h-7 rounded bg-gradient-to-br from-[#FFA526]/30 to-[#FF8800]/20"></div>
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
<section id="how" class="py-24">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-14">
            <span class="text-xs font-bold uppercase tracking-widest text-[#FFA526]">How it works</span>
            <h2 class="text-3xl md:text-4xl font-extrabold text-white mt-2 mb-3 tracking-tight">OAuth from end to end</h2>
            <p class="text-neutral-400 max-w-2xl mx-auto">No screen scraping. No shared credentials. Just the official Etsy Open API v3 with the seller's consent.</p>
        </div>

        @php
            $steps = [
                ['t' => 'Connect your shop', 'd' => 'Click "Connect Etsy shop" in BullStart and you\'re sent to Etsy\'s OAuth 2.0 authorization page.'],
                ['t' => 'Approve scopes', 'd' => 'Review the five scopes BullStart needs (listings_r, listings_w, transactions_r, transactions_w, shops_r) and approve them on Etsy.'],
                ['t' => 'Sync your catalog', 'd' => 'BullStart performs a one-time pull of your listings and open orders into your private workspace.'],
                ['t' => 'Edit listings', 'd' => 'Update inventory, price, title, description, and images inside BullStart. Click Save to push the change back to Etsy.'],
                ['t' => 'Print &amp; ship', 'd' => 'Generate a packing slip, ship the order, and mark it shipped in BullStart. The shipped status flows back to Etsy through the API.'],
                ['t' => 'Tracking write-back', 'd' => 'BullStart posts the tracking number to Etsy via the API. The buyer receives Etsy\'s standard shipped notification.'],
            ];
        @endphp

        <div class="grid md:grid-cols-3 gap-6 mb-14">
            @foreach ($steps as $i => $s)
                <div class="relative card-soft rounded-2xl p-6">
                    <div class="absolute -top-3 -left-3 w-9 h-9 rounded-xl text-white flex items-center justify-center font-extrabold text-sm"
                         style="background:#FFA526;box-shadow:0 0 15px rgba(255,165,38,0.4);">{{ $i + 1 }}</div>
                    <h3 class="font-bold text-white mb-2 mt-1">{!! $s['t'] !!}</h3>
                    <p class="text-sm text-neutral-400 leading-relaxed">{!! $s['d'] !!}</p>
                </div>
            @endforeach
        </div>

        {{-- Scopes table --}}
        <div class="card-dark rounded-2xl p-7 md:p-9 text-white">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-10 h-10 rounded-lg bg-[#FFA526]/15 text-[#FFA526] flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <div>
                    <h3 class="font-bold text-lg">OAuth 2.0 scopes we request</h3>
                    <p class="text-sm text-neutral-500">The minimum scopes needed — nothing more.</p>
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
                    <tbody class="divide-y divide-white/5">
                        <tr><td class="py-3 pr-6 font-mono text-[#FFC565]">listings_r</td><td class="py-3 text-neutral-400">Read the seller's existing listings into the BullStart catalog.</td></tr>
                        <tr><td class="py-3 pr-6 font-mono text-[#FFC565]">listings_w</td><td class="py-3 text-neutral-400">Save listing edits (inventory, price, title, description, images) back to Etsy when the seller clicks Save.</td></tr>
                        <tr><td class="py-3 pr-6 font-mono text-[#FFC565]">transactions_r</td><td class="py-3 text-neutral-400">Fetch open orders for fulfillment.</td></tr>
                        <tr><td class="py-3 pr-6 font-mono text-[#FFC565]">transactions_w</td><td class="py-3 text-neutral-400">Post tracking numbers and shipped status when a package leaves the warehouse.</td></tr>
                        <tr><td class="py-3 pr-6 font-mono text-[#FFC565]">shops_r</td><td class="py-3 text-neutral-400">Read shop metadata (name, country, timezone) to render the dashboard correctly.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

{{-- ============================== SECURITY ============================== --}}
<section id="security" class="py-24">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-14">
            <span class="text-xs font-bold uppercase tracking-widest text-[#FFA526]">Security &amp; Privacy</span>
            <h2 class="text-3xl md:text-4xl font-extrabold text-white mt-2 mb-3 tracking-tight">Your shop's data, handled carefully</h2>
            <p class="text-neutral-400 max-w-2xl mx-auto">We follow Etsy's API Terms of Use and only access the data the seller has explicitly authorized.</p>
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
                <div class="card-dark rounded-2xl p-6 transition hover:border-[#FFA526]/30">
                    <div class="text-2xl mb-3">{{ $s['icon'] }}</div>
                    <h3 class="font-bold text-white mb-1.5">{{ $s['title'] }}</h3>
                    <p class="text-sm text-neutral-400 leading-relaxed">{{ $s['desc'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================== PRICING ============================== --}}
<section id="pricing" class="py-24">
    <div class="max-w-4xl mx-auto px-6">
        <div class="text-center mb-12">
            <span class="text-xs font-bold uppercase tracking-widest text-[#FFA526]">Pricing</span>
            <h2 class="text-3xl md:text-4xl font-extrabold text-white mt-2 mb-3 tracking-tight">Free during early access</h2>
            <p class="text-neutral-400 max-w-2xl mx-auto">BullStart is built and operated by Team Bullstart's own print shop. We use the platform to run our own fulfillment line — and during early access we open it to every Etsy seller at no cost while we collect feedback. Paid tiers will be introduced after general availability.</p>
        </div>

        <div class="card-dark rounded-3xl p-10 text-center" style="box-shadow:0 0 60px rgba(255,165,38,0.10);">
            <div class="inline-flex items-center gap-2 px-3 py-1 bg-white/5 text-[#FFC565] text-xs font-bold rounded-full mb-5 border border-[#FFA526]/30">
                <span class="w-1.5 h-1.5 bg-emerald-400 rounded-full"></span>
                EARLY ACCESS · NO CREDIT CARD
            </div>
            <div class="text-7xl font-extrabold text-white tracking-tight mb-2 glow-text">$0</div>
            <p class="text-neutral-400 mb-8">No setup fee · No per-order fee · No subscription</p>
            <div class="grid sm:grid-cols-2 gap-3 max-w-md mx-auto text-left mb-8">
                @foreach (['Unlimited listings sync', 'Unlimited orders fulfilled', 'One-click packing slips', 'Variation &amp; image editor', 'Tracking write-back to Etsy', 'Email support'] as $item)
                    <div class="flex items-start gap-2 text-sm text-neutral-300">
                        <svg class="w-5 h-5 text-emerald-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        <span>{!! $item !!}</span>
                    </div>
                @endforeach
            </div>
            <a href="#contact" class="inline-block px-6 py-3.5 btn-glow rounded-xl font-semibold text-sm">Request early access</a>
        </div>

        <p class="text-center text-xs text-neutral-500 mt-6 max-w-2xl mx-auto">
            Paid tiers will be introduced after general availability. We'll publish the change on
            this page and email every active seller at least 30 days in advance.
        </p>
    </div>
</section>

{{-- ============================== FAQ ============================== --}}
<section id="faq" class="py-24">
    <div class="max-w-3xl mx-auto px-6">
        <div class="text-center mb-12">
            <span class="text-xs font-bold uppercase tracking-widest text-[#FFA526]">FAQ</span>
            <h2 class="text-3xl md:text-4xl font-extrabold text-white mt-2 mb-3 tracking-tight">Questions, answered</h2>
        </div>

        @php
            $faqs = [
                ['q' => 'Is BullStart endorsed by Etsy?', 'a' => 'No. BullStart is an independent seller tool that uses the public Etsy Open API v3. The term "Etsy" is a trademark of Etsy, Inc. This application uses the Etsy API but is not endorsed or certified by Etsy, Inc.'],
                ['q' => 'Do you take a cut of my sales?', 'a' => 'No. BullStart does not touch the buyer payment flow — buyers continue to check out on Etsy. BullStart is also free during early access, with no per-order fee. Future paid tiers will be subscription-style; we will never take a percentage of your sales.'],
                ['q' => 'Why is it free during early access?', 'a' => 'BullStart was built by Team Bullstart\'s own print shop. We use the platform to run our own fulfillment line, so the infrastructure cost is already covered. Keeping early access free for every Etsy seller lets us collect feedback and improve faster. Paid tiers will be introduced after general availability; we\'ll email every active seller at least 30 days before any change.'],
                ['q' => 'Can buyers see BullStart?', 'a' => 'No. There is no buyer-facing surface. BullStart only renders inside the seller\'s workspace. Buyers continue to browse, check out, and receive notifications on Etsy as usual.'],
                ['q' => 'Will BullStart change my listings without asking?', 'a' => 'Never. Every write happens after a human in your team clicks Save inside BullStart. There are no scheduled or autonomous edits.'],
                ['q' => 'How do I revoke access?', 'a' => 'Open your Etsy account settings → Apps & Permissions → BullStart → Revoke. We stop polling and writing the moment Etsy returns an invalid-token response.'],
                ['q' => 'Where can I delete my data?', 'a' => 'Email us at the address below. We remove your records and confirm the deletion within seven business days.'],
                ['q' => 'Do you support more than 5 shops?', 'a' => 'Yes — that is exactly why we are requesting Commercial Access from Etsy. Personal Access caps at 5 shops; Commercial Access unlocks BullStart for the general public, which is who we want to serve.'],
            ];
        @endphp

        <div class="space-y-3">
            @foreach ($faqs as $f)
                <details class="card-soft rounded-xl group">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer list-none">
                        <span class="font-semibold text-neutral-100">{{ $f['q'] }}</span>
                        <svg class="w-5 h-5 text-neutral-500 group-open:rotate-180 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-neutral-400 leading-relaxed">{{ $f['a'] }}</div>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================== CTA ============================== --}}
<section id="contact" class="py-20 relative overflow-hidden">
    <div class="relative max-w-3xl mx-auto px-6 text-center">
        <h2 class="text-3xl md:text-5xl font-extrabold tracking-tight mb-4 text-white glow-text">Want to bring your shop on?</h2>
        <p class="text-neutral-400 text-lg mb-8 max-w-xl mx-auto">
            BullStart is free during early access and rolling out to the general public. Drop us a
            note and we'll send your onboarding link within one business day.
        </p>
        <a href="mailto:thanhp0312@gmail.com" class="inline-flex items-center gap-2 px-6 py-3.5 btn-glow rounded-xl font-bold text-sm">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            thanhp0312@gmail.com
        </a>
        <p class="text-neutral-600 text-xs mt-6">Team BullStart · United States</p>
    </div>
</section>

@endsection
