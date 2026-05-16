@extends('layouts.app')
@section('title', 'BullStart — Order Fulfillment Workspace for Etsy Sellers')

@section('content')
<div class="max-w-5xl mx-auto px-6 py-10">

    {{-- Header --}}
    <header class="flex items-center justify-between mb-12">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-orange-500 text-white flex items-center justify-center font-bold text-lg">BS</div>
            <span class="font-semibold text-neutral-800 text-lg">BullStart</span>
        </div>
        <nav class="flex items-center gap-6 text-sm">
            <a href="#features" class="text-neutral-600 hover:text-neutral-900">Features</a>
            <a href="#how" class="text-neutral-600 hover:text-neutral-900">How it works</a>
            <a href="#privacy" class="text-neutral-600 hover:text-neutral-900">Privacy</a>
            <a href="#contact" class="text-neutral-600 hover:text-neutral-900">Contact</a>
            <a href="{{ route('login') }}" class="px-3 py-1.5 bg-orange-500 hover:bg-orange-600 text-white rounded-lg font-medium">Sign in</a>
        </nav>
    </header>

    {{-- Hero --}}
    <section class="text-center mb-20">
        <span class="inline-block px-3 py-1 bg-orange-50 text-orange-700 text-xs font-semibold rounded-full border border-orange-200 mb-5">SELLER TOOL · OAUTH 2.0</span>
        <h1 class="text-4xl md:text-5xl font-extrabold text-neutral-900 leading-tight mb-5">
            Manage your listings and orders<br />from one workspace.
        </h1>
        <p class="text-lg text-neutral-600 max-w-2xl mx-auto mb-8">
            BullStart helps our team and a small group of partner Etsy sellers run their own shop
            end-to-end. Sync your listings, edit inventory and pricing, fulfill open orders, print
            packing labels, and post tracking back to Etsy — all from one dashboard.
        </p>
        <div class="flex justify-center gap-3">
            <a href="#how" class="px-5 py-2.5 bg-neutral-900 hover:bg-neutral-800 text-white rounded-lg font-medium text-sm">See how it works</a>
            <a href="#contact" class="px-5 py-2.5 bg-white hover:bg-neutral-50 text-neutral-700 rounded-lg font-medium text-sm border border-neutral-300">Request access</a>
        </div>
    </section>

    {{-- Features --}}
    <section id="features" class="mb-20">
        <h2 class="text-2xl font-bold text-neutral-900 mb-2">What BullStart does</h2>
        <p class="text-neutral-600 mb-8">A focused seller tool covering both ends of the workflow — your catalog and your fulfillment floor.</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            @php
                $features = [
                    ['title' => 'Listing sync', 'desc' => 'Pull your existing Etsy listings into the BullStart catalog. Every edit you make in BullStart — inventory, price, title, description, images — is saved back to Etsy when you click Save.'],
                    ['title' => 'Bulk inventory edits', 'desc' => 'Update stock levels across variations from one table. Useful when receiving a new batch of supplies or pausing items that are out of stock.'],
                    ['title' => 'Unified order list', 'desc' => 'Open orders from your authorized Etsy shop appear in one searchable, filterable dashboard. Search by ref ID, system ID, or tracking number.'],
                    ['title' => 'Batch print files', 'desc' => 'Group designs from many orders into a single print-ready gangsheet. Reduce paper waste and turnaround time on the production floor.'],
                    ['title' => 'QR-scan workflow', 'desc' => 'Each order is tagged with a Code 128 barcode. Scan at the QC station to pull up the order, print the carrier label, and mark it shipped in one motion.'],
                    ['title' => 'Tracking write-back', 'desc' => 'When a package leaves the warehouse, BullStart writes the tracking number and shipped status back to your Etsy shop so the buyer is notified through Etsy.'],
                    ['title' => 'Seller-initiated writes', 'desc' => 'BullStart never edits your listings autonomously. Every write — to a listing or an order — happens only after you save the change inside the app.'],
                    ['title' => 'Production cost ledger', 'desc' => 'An internal wallet tracks the print cost of each shipped order — used by our team for accounting only. No buyer data is exposed.'],
                    ['title' => 'No buyer surface', 'desc' => 'BullStart has no buyer-facing storefront. Buyers continue to browse and check out on Etsy as usual; we only help the seller fulfill what they already sold.'],
                ];
            @endphp
            @foreach ($features as $f)
                <div class="bg-white rounded-xl border border-neutral-200 p-5 shadow-sm">
                    <h3 class="font-semibold text-neutral-900 mb-1.5">{{ $f['title'] }}</h3>
                    <p class="text-sm text-neutral-600 leading-relaxed">{{ $f['desc'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- How it works --}}
    <section id="how" class="mb-20">
        <h2 class="text-2xl font-bold text-neutral-900 mb-2">How it works</h2>
        <p class="text-neutral-600 mb-8">A clear, OAuth-only flow. The seller is always in control of what BullStart can see.</p>

        <ol class="space-y-4">
            @php
                $steps = [
                    'The shop owner clicks "Connect Etsy shop" inside BullStart and is redirected to Etsy\'s OAuth 2.0 authorization page.',
                    'On Etsy, the seller reviews the requested scopes (listings_r, listings_w, transactions_r, transactions_w, shops_r) and grants or declines access.',
                    'BullStart receives an access token, performs a one-time sync of the seller\'s listings and open orders, and stores them against the seller\'s BullStart account. We never see other sellers\' data.',
                    'The seller manages their catalog inside BullStart — editing inventory, prices, titles, descriptions, and images. Every save sends a write back to the matching Etsy listing through the official API.',
                    'Production staff at our warehouse use the BullStart desktop app to print packing labels and barcode tags. No buyer-facing surface is created — buyers continue their journey on Etsy.',
                    'When an order ships, BullStart sends the tracking number and "shipped" status back to Etsy through the API so the buyer receives Etsy\'s standard shipped notification.',
                    'The seller can revoke BullStart\'s access at any time from their Etsy account settings; we honor revocation and stop polling and writing immediately.',
                ];
            @endphp
            @foreach ($steps as $i => $s)
                <li class="flex gap-4">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-orange-500 text-white flex items-center justify-center font-bold text-sm">{{ $i + 1 }}</div>
                    <p class="text-neutral-700 leading-relaxed pt-1">{{ $s }}</p>
                </li>
            @endforeach
        </ol>

        {{-- Scopes table — Etsy reviewers like to see this clearly. --}}
        <div class="mt-10 bg-white rounded-xl border border-neutral-200 p-5">
            <h3 class="font-semibold text-neutral-900 mb-1">OAuth 2.0 scopes we request</h3>
            <p class="text-sm text-neutral-600 mb-4">We only request the minimum scopes needed to deliver the features above. Sellers see and approve each scope on Etsy's consent screen.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-200">
                        <tr>
                            <th class="text-left py-2 pr-4">Scope</th>
                            <th class="text-left py-2">Purpose</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr><td class="py-2 pr-4 font-mono text-neutral-800">listings_r</td><td class="py-2 text-neutral-600">Read the seller's existing listings into the BullStart catalog.</td></tr>
                        <tr><td class="py-2 pr-4 font-mono text-neutral-800">listings_w</td><td class="py-2 text-neutral-600">Save listing edits (inventory, price, title, description, images) back to Etsy when the seller clicks Save.</td></tr>
                        <tr><td class="py-2 pr-4 font-mono text-neutral-800">transactions_r</td><td class="py-2 text-neutral-600">Fetch open orders for fulfillment.</td></tr>
                        <tr><td class="py-2 pr-4 font-mono text-neutral-800">transactions_w</td><td class="py-2 text-neutral-600">Post tracking numbers and shipped status when a package leaves the warehouse.</td></tr>
                        <tr><td class="py-2 pr-4 font-mono text-neutral-800">shops_r</td><td class="py-2 text-neutral-600">Read shop metadata (name, country, timezone) to render the dashboard correctly.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Privacy --}}
    <section id="privacy" class="mb-20">
        <h2 class="text-2xl font-bold text-neutral-900 mb-2">Privacy &amp; data handling</h2>
        <p class="text-neutral-600 mb-6">We follow Etsy's API Terms of Use and only access data the seller has explicitly authorized.</p>

        <div class="bg-white rounded-xl border border-neutral-200 p-6 space-y-4 text-sm text-neutral-700 leading-relaxed">
            <p><strong class="text-neutral-900">What we read.</strong> The authorized shop's listings (title, price, inventory, description, images, variations), open orders, line items, shipping addresses, and shop metadata. We do not read messages, reviews, or buyer profile data.</p>
            <p><strong class="text-neutral-900">What we write.</strong> Listing edits (inventory, price, title, description, images) — only when the seller clicks Save inside BullStart. Tracking numbers and shipped status — only when a BullStart user marks an order as fulfilled. We never write to a listing or an order autonomously.</p>
            <p><strong class="text-neutral-900">Where it is stored.</strong> Listing and order data lives in our private database for the lifetime of the seller's BullStart account. Listing images and packing-label images are stored on encrypted object storage. Access tokens are kept server-side, never exposed to the browser.</p>
            <p><strong class="text-neutral-900">Retention &amp; deletion.</strong> Sellers can request full deletion of their data at any time via the contact email below. We honor the request within seven business days and revoke our token with Etsy.</p>
            <p><strong class="text-neutral-900">Caching.</strong> Listing, shop, and user data is cached only as long as the Etsy API caching policy permits. We re-fetch instead of relying on stale data.</p>
            <p><strong class="text-neutral-900">No resale.</strong> We do not sell, lease, or share seller or buyer data with third parties. The data is used solely to operate the seller's own shop.</p>
        </div>
    </section>

    {{-- Contact --}}
    <section id="contact" class="mb-12">
        <h2 class="text-2xl font-bold text-neutral-900 mb-2">Contact</h2>
        <p class="text-neutral-600 mb-4">Questions about access, integration, or data deletion?</p>
        <div class="bg-white rounded-xl border border-neutral-200 p-5 inline-block">
            <p class="text-sm text-neutral-700"><strong>Team BullStart</strong></p>
            <p class="text-sm text-neutral-600">Email: <a href="mailto:thanhp0312@gmail.com" class="text-orange-600 hover:underline">thanhp0312@gmail.com</a></p>
        </div>
    </section>

    {{-- Mandatory Etsy attribution per Section 6 of the API Terms of Use --}}
    <footer class="border-t border-neutral-200 pt-6 mt-12 text-xs text-neutral-500 leading-relaxed">
        <p class="mb-2">
            The term &ldquo;Etsy&rdquo; is a trademark of Etsy, Inc.
            This application uses the Etsy API but is not endorsed or certified by Etsy, Inc.
        </p>
        <p>&copy; {{ date('Y') }} BullStart. All rights reserved.</p>
    </footer>
</div>
@endsection
