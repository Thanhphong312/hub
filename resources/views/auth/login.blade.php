@extends('layouts.app')
@section('title', 'Login - BullStart')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-[420px] max-w-full">
        <h1 class="text-center text-2xl font-bold text-neutral-800 mb-1">BULLSTART</h1>
        <p class="text-center text-sm text-neutral-500 mb-6">Sign in to view orders via QR</p>

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
