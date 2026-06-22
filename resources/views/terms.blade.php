@extends('layouts.marketing')
@section('title', 'Terms of Service — BullStart')
@section('meta_description', 'The terms that govern your use of the BullStart seller workspace.')

@section('head')
    @include('partials.legal-styles')
@endsection

@section('content')
<div class="max-w-4xl mx-auto px-6 pt-16 pb-10">
    <div class="legal-card">
        <h1 class="legal-title">Terms of Service</h1>
        <p class="legal-date">Last Updated: {{ date('F j, Y') }}</p>
        <div class="legal-divider"></div>

        <div class="legal-body">
            <p>Welcome to <strong>BullStart</strong> (&ldquo;we&rdquo;, &ldquo;us&rdquo;, &ldquo;our&rdquo;, or the &ldquo;Application Developer&rdquo;). BullStart is a U.S.-based seller workspace that helps Etsy sellers sync listings, fulfill open orders, print packing slips, and post tracking back to Etsy. It is built and operated by Team BullStart's own print shop.</p>
            <p>By accessing or using BullStart, you agree to these Terms of Service. If you do not agree, please do not use the service.</p>
            <hr />

            <h4>1. The Service</h4>
            <p>BullStart provides Etsy sellers with:</p>
            <ul>
                <li>Listing sync, bulk inventory/price/title/description edits, and variation &amp; image management</li>
                <li>A unified open-order list with one-click packing slips</li>
                <li>Tracking and shipped-status write-back to Etsy</li>
                <li>Per-user roles and an audit-friendly activity log</li>
            </ul>
            <hr />

            <h4>2. Account Registration</h4>
            <p>To use BullStart you must create an account and provide accurate, current information. You are responsible for:</p>
            <ul>
                <li>Maintaining the confidentiality of your credentials</li>
                <li>All activity that occurs under your account</li>
                <li>Notifying us immediately of any unauthorized use</li>
            </ul>
            <hr />

            <h4>3. Etsy Integration</h4>
            <p>BullStart uses the official Etsy Open API v3. By connecting your Etsy shop, you acknowledge and agree that:</p>
            <ul>
                <li>We access your shop data through OAuth 2.0 authorization, using only five scopes: <strong>listings_r, listings_w, transactions_r, transactions_w, shops_r</strong>.</li>
                <li>We do not use screen-scraping or any unauthorized method to access Etsy data.</li>
                <li>We never write to a listing or an order autonomously — every write happens only after a human in your team clicks Save or marks an order shipped.</li>
                <li>Data accessed through the Etsy API is cached only within Etsy's caching limits.</li>
                <li>We do not access buyer email addresses without separate approval from Etsy.</li>
                <li>You may revoke access at any time from your Etsy account settings; we honor the revocation immediately and stop polling.</li>
                <li>You are responsible for complying with Etsy's Terms of Use when using BullStart.</li>
            </ul>
            <hr />

            <h4>4. Warranty Disclaimer</h4>
            <div class="alert alert-warning">
                <strong>DISCLAIMER:</strong> THIS APPLICATION IS SOLELY PROVIDED BY TEAM BULLSTART (THE APPLICATION DEVELOPER). YOU ACKNOWLEDGE THAT ETSY, INC. AND ITS AFFILIATES ARE NOT THE APPLICATION DEVELOPER, DO NOT PROVIDE THE APPLICATION SERVICE, AND MAKE NO WARRANTIES OF ANY KIND WITH RESPECT TO THE APPLICATION OR DATA ACCESSED THROUGH IT.
            </div>
            <p>BullStart is provided on an &ldquo;as-is&rdquo; and &ldquo;as-available&rdquo; basis without warranties of any kind, express or implied. We do not warrant that the service will be uninterrupted, error-free, or free of harmful components.</p>
            <hr />

            <h4>5. User Responsibilities</h4>
            <p>You agree to:</p>
            <ul>
                <li>Use BullStart only for lawful purposes</li>
                <li>Not infringe any third party's intellectual property rights</li>
                <li>Ensure all listings, designs, and content comply with applicable laws</li>
                <li>Not use BullStart to produce or distribute prohibited, counterfeit, or illegal products</li>
                <li>Comply with all applicable marketplace terms and policies, including Etsy's</li>
            </ul>
            <hr />

            <h4>6. Pricing &amp; Fees</h4>
            <p>BullStart is <strong>free during early access</strong> — no setup fee, no per-order fee, and no subscription. BullStart does not touch the buyer payment flow and never takes a percentage of your sales. Paid tiers may be introduced after general availability; we will publish any change on our website and email every active seller at least <strong>30 days</strong> in advance.</p>
            <hr />

            <h4>7. Intellectual Property</h4>
            <p>You retain ownership of all listings, designs, and content you manage through BullStart. By using the service, you grant us a limited license to process that content solely to provide the service. We do not claim ownership of your content.</p>
            <hr />

            <h4>8. Data &amp; Privacy</h4>
            <p>Our collection, use, and protection of personal and Etsy data is governed by our <a href="{{ route('privacy') }}">Privacy Policy</a>, which is incorporated into these Terms by reference. We store OAuth tokens securely server-side, process data only to provide the service, and comply with applicable privacy laws.</p>
            <hr />

            <h4>9. Limitation of Liability</h4>
            <p>To the maximum extent permitted by law, BullStart and Team BullStart shall not be liable for any indirect, incidental, special, consequential, or punitive damages, including loss of profits, data, or business opportunities, arising out of or related to your use of the service.</p>
            <hr />

            <h4>10. Indemnification</h4>
            <p>You agree to indemnify and hold harmless Team BullStart and its members from any claims, damages, losses, liabilities, and expenses (including attorneys' fees) arising out of your use of the service, your violation of these Terms, or your violation of any third party's rights.</p>
            <hr />

            <h4>11. Termination</h4>
            <p>We may suspend or terminate your account if you violate these Terms. Upon termination, your right to use BullStart ceases, we disconnect the Etsy integration, and we delete stored Etsy tokens according to our retention practices.</p>
            <hr />

            <h4>12. Changes to These Terms</h4>
            <p>We may update these Terms from time to time and will notify you of material changes by email or through the application. Continued use after changes become effective constitutes acceptance of the updated Terms.</p>
            <hr />

            <h4>13. Governing Law</h4>
            <p>These Terms are governed by the laws of the United States and the applicable state in which Team BullStart operates, without regard to conflict-of-law principles.</p>
            <hr />

            <h4>14. Third-Party Trademarks</h4>
            <p>The term &ldquo;Etsy&rdquo; is a trademark of Etsy, Inc. This application uses the Etsy API but is not endorsed or certified by Etsy, Inc. All other trademarks are the property of their respective owners.</p>
            <hr />

            <h4>15. Contact Us</h4>
            <p>If you have questions about these Terms of Service, please contact us:</p>
            <ul class="list-unstyled">
                <li><strong>Team BullStart</strong></li>
                <li>United States</li>
                <li>Email: <a href="mailto:thanhp0312@gmail.com">thanhp0312@gmail.com</a></li>
            </ul>
        </div>
    </div>
</div>
@endsection
