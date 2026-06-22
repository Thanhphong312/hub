{{-- ============================== STICKY NAV ============================== --}}
<nav class="sticky top-0 z-50 glass-nav">
    <div class="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between">
        <a href="{{ url('/') }}" class="flex items-center">
            <img src="{{ asset('logo.png') }}" alt="BullStart" class="h-9 w-auto" />
        </a>
        <nav class="hidden md:flex items-center gap-7 text-sm font-medium">
            <a href="{{ url('/#features') }}" class="text-neutral-300 hover:text-[#FFA526] transition">Features</a>
            <a href="{{ url('/#how') }}" class="text-neutral-300 hover:text-[#FFA526] transition">How it works</a>
            <a href="{{ url('/#security') }}" class="text-neutral-300 hover:text-[#FFA526] transition">Security</a>
            <a href="{{ url('/#pricing') }}" class="text-neutral-300 hover:text-[#FFA526] transition">Pricing</a>
            <a href="{{ url('/#faq') }}" class="text-neutral-300 hover:text-[#FFA526] transition">FAQ</a>
        </nav>
        <div class="flex items-center gap-2">
            <a href="{{ route('login') }}" class="text-sm font-semibold text-neutral-200 hover:text-white px-3 py-1.5 transition">Sign in</a>
            <a href="{{ url('/#contact') }}" class="btn-glow text-sm font-semibold px-4 py-2 rounded-lg">Request access</a>
        </div>
    </div>
</nav>
