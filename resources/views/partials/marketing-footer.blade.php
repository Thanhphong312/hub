{{-- ============================== FOOTER ============================== --}}
<footer class="pt-16 pb-10">
    <div class="max-w-6xl mx-auto px-6">
        {{-- CTA card --}}
        <div class="card-dark rounded-3xl px-7 py-8 lg:px-10 lg:py-9 flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between mb-12"
             style="box-shadow: 0 0 50px rgba(255,165,38,0.10);">
            <h3 class="text-2xl md:text-3xl font-extrabold text-white tracking-tight glow-text">
                Let your shop grow freely.
            </h3>
            <a href="{{ url('/#contact') }}"
               class="btn-glow inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl font-semibold w-fit text-sm">
                Get started <span aria-hidden="true">›</span>
            </a>
        </div>

        <div class="grid md:grid-cols-4 gap-8 text-sm">
            <div class="md:col-span-2">
                <img src="{{ asset('logo.png') }}" alt="BullStart" class="h-10 w-auto mb-4" />
                <p class="text-xs text-neutral-500 leading-relaxed max-w-sm">
                    The term &ldquo;Etsy&rdquo; is a trademark of Etsy, Inc.
                    This application uses the Etsy API but is not endorsed or certified by Etsy, Inc.
                </p>
                <p class="text-xs text-neutral-500 leading-relaxed max-w-sm mt-3">
                    Team BullStart · United States
                </p>
            </div>
            <div>
                <h4 class="text-xs font-bold uppercase tracking-widest text-[#FFA526] mb-3">Product</h4>
                <ul class="space-y-1.5 text-neutral-400">
                    <li><a href="{{ url('/#features') }}" class="hover:text-white transition">Features</a></li>
                    <li><a href="{{ url('/#how') }}" class="hover:text-white transition">How it works</a></li>
                    <li><a href="{{ url('/#security') }}" class="hover:text-white transition">Security</a></li>
                    <li><a href="{{ url('/#pricing') }}" class="hover:text-white transition">Pricing</a></li>
                    <li><a href="{{ url('/#faq') }}" class="hover:text-white transition">FAQ</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-xs font-bold uppercase tracking-widest text-[#FFA526] mb-3">Company</h4>
                <ul class="space-y-1.5 text-neutral-400">
                    <li><a href="{{ url('/#contact') }}" class="hover:text-white transition">Contact</a></li>
                    <li><a href="mailto:thanhp0312@gmail.com" class="hover:text-white transition">Email us</a></li>
                    <li><a href="{{ route('privacy') }}" class="hover:text-white transition">Privacy Policy</a></li>
                    <li><a href="{{ route('terms') }}" class="hover:text-white transition">Terms of Service</a></li>
                    <li><a href="{{ route('login') }}" class="hover:text-white transition">Sign in</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="border-t border-white/5 mt-10">
        <div class="max-w-6xl mx-auto px-6 py-5 flex flex-col md:flex-row justify-between items-center gap-2 text-xs text-neutral-500">
            <span>&copy; {{ date('Y') }} BullStart. All rights reserved.</span>
            <span>Built in the United States · API-compliant seller tool</span>
        </div>
    </div>

    <button type="button" onclick="window.scrollTo({top:0,behavior:'smooth'})"
            class="fixed right-5 lg:right-8 bottom-6 w-11 h-11 rounded-full text-white flex items-center justify-center transition-all z-50"
            style="background:#FFA526; box-shadow:0 0 15px rgba(255,165,38,0.45), 0 0 30px rgba(255,165,38,0.15);"
            aria-label="Scroll to top">↑</button>
</footer>
