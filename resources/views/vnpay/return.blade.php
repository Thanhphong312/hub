@extends('layouts.app')
@section('title', 'VNPay deposit result')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-[480px] max-w-full bg-white rounded-2xl border border-orange-100 p-8 shadow-xl text-center">
        @if ($success)
            <div class="text-5xl mb-4">✅</div>
            <h1 class="text-xl font-bold text-emerald-600 mb-2">Thanh toán thành công</h1>
            <p class="text-sm text-neutral-600 mb-1">Mã giao dịch: <span class="font-mono">{{ $txnRef }}</span></p>
            <p class="text-sm text-neutral-600 mb-4">Số tiền VND: <span class="font-semibold">{{ number_format($vndAmount) }} ₫</span></p>
            @if ($tx)
                <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-sm">
                    <div class="text-neutral-600">Quy đổi USD</div>
                    <div class="text-2xl font-bold text-emerald-700 mt-1">${{ number_format($tx->amount, 2) }}</div>
                    <div class="text-xs text-neutral-500 mt-1">Tỷ giá: 1 USD = {{ number_format($tx->exchange_rate) }} ₫</div>
                </div>
                <p class="text-xs text-neutral-500 mt-3">Số dư sẽ được cộng vào ví khi VNPay gửi xác nhận (thường tức thì).</p>
            @endif
        @else
            <div class="text-5xl mb-4">❌</div>
            <h1 class="text-xl font-bold text-red-600 mb-2">Thanh toán không thành công</h1>
            <p class="text-sm text-neutral-600 mb-1">Mã lỗi VNPay: <span class="font-mono">{{ $code }}</span></p>
            @if ($txnRef)
                <p class="text-sm text-neutral-600">Mã giao dịch: <span class="font-mono">{{ $txnRef }}</span></p>
            @endif
            <p class="text-xs text-neutral-500 mt-4">Bạn có thể thử lại từ ứng dụng BullStart Seller.</p>
        @endif

        <p class="text-xs text-neutral-400 mt-6">Bạn có thể đóng cửa sổ này.</p>
    </div>
</div>
@endsection
