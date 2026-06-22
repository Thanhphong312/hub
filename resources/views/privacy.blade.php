@extends('layouts.marketing')
@section('title', 'Privacy Policy — BullStart')
@section('meta_description', 'How BullStart collects, uses, stores, and protects seller and Etsy data.')

@section('head')
    @include('partials.legal-styles')
@endsection

@section('content')
<div class="max-w-4xl mx-auto px-6 pt-16 pb-10">
    <div class="legal-card">
        <h1 class="legal-title">Privacy Policy</h1>
        <p class="legal-date">Last Updated: {{ date('F j, Y') }}</p>
        <div class="legal-divider"></div>

        <div class="legal-body">
            <p>Welcome to <strong>BullStart</strong> (&ldquo;we&rdquo;, &ldquo;us&rdquo;, or &ldquo;our&rdquo;). BullStart is a U.S.-based seller workspace that helps Etsy sellers sync their listings, fulfill open orders, print packing slips, and post tracking back to Etsy — all from one place. BullStart is built and operated by Team BullStart's own print shop.</p>
            <p>This Privacy Policy explains how we collect, use, disclose, and safeguard information when you use BullStart. By accessing or using our services, you agree to the terms of this Privacy Policy.</p>
            <hr />

            <h4>1. Information We Collect</h4>
            <h5>a. Account information you provide</h5>
            <p>When you register or contact us, we may collect:</p>
            <ul>
                <li>Full name and business / shop name</li>
                <li>Email address</li>
                <li>Account credentials (passwords are stored only as salted hashes)</li>
            </ul>
            <h5>b. Etsy data accessed through the official API</h5>
            <p>When you connect your Etsy shop using OAuth 2.0, we access only what the five requested scopes allow:</p>
            <ul>
                <li>Shop metadata (shop name, ID, country, currency, timezone)</li>
                <li>Listings (title, price, inventory, description, images, variations)</li>
                <li>Open orders and line items, including the buyer's shipping name and address — used solely to fulfill and ship the order</li>
                <li>Tracking numbers and shipped status that you submit back to Etsy</li>
            </ul>
            <h5>c. Automatically collected information</h5>
            <ul>
                <li>IP address and browser type</li>
                <li>Device and operating system information</li>
                <li>Usage events and error logs needed to operate the service</li>
            </ul>
            <hr />

            <h4>2. How We Use Your Information</h4>
            <ul>
                <li><strong>Listing management:</strong> to display, edit, and — only when you click Save — write your listings back to Etsy.</li>
                <li><strong>Order fulfillment:</strong> to list open orders, generate packing slips, and post tracking and shipped status back to Etsy on your instruction.</li>
                <li><strong>Account management:</strong> to create and secure your workspace and communicate with you about it.</li>
                <li><strong>Support &amp; improvement:</strong> to respond to inquiries and improve reliability and performance.</li>
                <li><strong>Legal compliance:</strong> to comply with applicable laws and Etsy's API Terms of Use.</li>
            </ul>
            <hr />

            <h4>3. What We Never Do</h4>
            <ul>
                <li>We do <strong>not</strong> sell, lease, or share seller or buyer data with third parties.</li>
                <li>We do <strong>not</strong> use Etsy data for marketing, advertising, resale, or AI/ML training.</li>
                <li>We do <strong>not</strong> read Etsy messages, reviews, or buyer profile data.</li>
                <li>We do <strong>not</strong> access buyer email addresses without separate written approval from Etsy.</li>
                <li>We do <strong>not</strong> screen-scrape Etsy or run a buyer-facing storefront.</li>
                <li>We <strong>never</strong> write to a listing or an order autonomously — every write follows a human clicking Save.</li>
            </ul>
            <hr />

            <h4>4. How We Share Information</h4>
            <p>We do not sell your personal information. We share data only as needed to provide the service:</p>
            <ul>
                <li><strong>Etsy:</strong> we exchange listing, order, and tracking data with Etsy through the official Open API v3 to keep your shop in sync.</li>
                <li><strong>Service providers:</strong> trusted infrastructure vendors (e.g., hosting) operating under confidentiality obligations.</li>
                <li><strong>Legal requirements:</strong> when required by law, court order, or to protect our rights, safety, or property.</li>
            </ul>
            <hr />

            <h4>5. Etsy Data Caching &amp; Freshness</h4>
            <p>In compliance with Etsy API Terms, we cache Etsy data only as long as the caching policy allows and re-fetch instead of relying on stale data:</p>
            <ul>
                <li>Listing content: refreshed well within Etsy's limits (our cache is short-lived).</li>
                <li>Order / receipt data: refreshed frequently so fulfillment reflects current state.</li>
                <li>Shop and user data: re-fetched rather than served stale.</li>
            </ul>
            <hr />

            <h4>6. Data Storage &amp; Security</h4>
            <p>We implement industry-standard safeguards, including:</p>
            <ul>
                <li>SSL/TLS encryption for data in transit</li>
                <li>OAuth access tokens stored <strong>server-side only</strong>, encrypted at rest, and never exposed to the browser</li>
                <li>Access controls limiting data to authorized personnel and per-user role gates</li>
            </ul>
            <p>No method of transmission or storage is 100% secure, and we cannot guarantee absolute security.</p>
            <hr />

            <h4>7. Data Retention &amp; Deletion</h4>
            <p>Order and listing data lives in our private database for the lifetime of your account. You can disconnect Etsy at any time from your Etsy account settings (Apps &amp; Permissions → BullStart → Revoke); we stop polling and writing the moment Etsy returns an invalid-token response. To delete your data, email us — we remove your records, revoke our token with Etsy, and confirm the deletion in writing within <strong>seven business days</strong>.</p>
            <hr />

            <h4>8. Breach Notification</h4>
            <p>In the event of a data breach involving Etsy member data, we will notify Etsy at <a href="mailto:dpo@etsy.com">dpo@etsy.com</a> and the affected seller within <strong>24 hours</strong> of discovery, in line with Etsy API Terms.</p>
            <hr />

            <h4>9. Your Privacy Rights</h4>
            <p>Depending on your state of residence (e.g., California's CCPA/CPRA, and laws in Virginia, Colorado, Connecticut, Utah, and Texas), you may have the right to access, correct, delete, and port your personal data, and to opt out of sale or sharing. <em>We do not sell your personal information.</em> To exercise any of these rights, contact us at the address below; we respond within the timeframe required by applicable law.</p>
            <hr />

            <h4>10. Children's Privacy</h4>
            <p>BullStart is a tool for businesses and is not directed to individuals under 13 (or 16 in certain jurisdictions). We do not knowingly collect data from children.</p>
            <hr />

            <h4>11. Changes to This Policy</h4>
            <p>We may update this Privacy Policy from time to time. When we do, we update the &ldquo;Last Updated&rdquo; date above. Continued use of BullStart after changes constitutes acceptance of the updated policy.</p>
            <hr />

            <h4>12. Etsy Trademark Disclaimer</h4>
            <p>The term &ldquo;Etsy&rdquo; is a trademark of Etsy, Inc. This application uses the Etsy API but is not endorsed or certified by Etsy, Inc.</p>
            <hr />

            <h4>13. Contact Us</h4>
            <p>If you have questions or requests regarding this Privacy Policy or your data, please contact us:</p>
            <ul class="list-unstyled">
                <li><strong>Team BullStart</strong></li>
                <li>United States</li>
                <li>Email: <a href="mailto:thanhp0312@gmail.com">thanhp0312@gmail.com</a></li>
            </ul>
        </div>
    </div>
</div>
@endsection
