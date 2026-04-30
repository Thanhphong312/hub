@extends('layouts.app')
@section('title', 'Login - BullStart')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4 relative overflow-hidden">
    {{-- Background glow accents --}}
    <div class="absolute top-[-150px] left-1/2 -translate-x-1/2 w-[500px] h-[500px] bg-orange-400/15 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-[-80px] right-[-80px] w-[250px] h-[250px] bg-orange-300/10 rounded-full blur-3xl pointer-events-none"></div>

    <div class="w-[420px] max-w-full relative z-10">
        <div class="flex flex-col items-center mb-6">
            <img src="{{ asset('logo.png') }}" alt="BullStart" class="h-24 mb-3 drop-shadow-[0_4px_20px_rgba(234,88,12,0.15)]" />
            <p class="text-center text-sm text-neutral-500 mt-1">Sign in to view orders via QR</p>
        </div>

        <div class="bg-white rounded-2xl border border-orange-100 p-8 shadow-xl shadow-orange-900/5">
            @if ($errors->any())
                <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-600 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ url('/login') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-semibold text-neutral-600 mb-2 uppercase tracking-wider">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                           class="w-full px-4 py-3 bg-[#faf8f6] border border-neutral-200 rounded-xl text-neutral-800 text-sm focus:outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-400/20 transition-all" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-neutral-600 mb-2 uppercase tracking-wider">Password</label>
                    <input type="password" name="password" required
                           class="w-full px-4 py-3 bg-[#faf8f6] border border-neutral-200 rounded-xl text-neutral-800 text-sm focus:outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-400/20 transition-all" />
                </div>

                <label class="flex items-center gap-2 text-xs text-neutral-600">
                    <input type="checkbox" name="remember" class="accent-orange-500" /> Remember me
                </label>

                <button type="submit"
                        class="w-full py-3 bg-gradient-to-r from-orange-500 to-orange-400 hover:from-orange-600 hover:to-orange-500 text-white rounded-xl text-sm font-bold uppercase tracking-wider transition-all shadow-lg shadow-orange-500/25">
                    Sign In
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
