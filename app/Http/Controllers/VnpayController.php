<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VnpayController extends Controller
{
    /**
     * Read a VNPay setting from the database first (app_settings.vnpay_merchant),
     * falling back to config('services.vnpay.*') when not set. This lets admin
     * manage credentials from the Settings UI without touching .env, while
     * existing env-based installs keep working.
     */
    private function setting(string $key)
    {
        $merchant = AppSetting::getJson('vnpay_merchant', []);
        $rate     = AppSetting::getJson('wallet_exchange_rate', []);

        return match ($key) {
            'tmn_code'    => $merchant['tmn_code']    ?? config('services.vnpay.tmn_code'),
            'hash_secret' => $merchant['hash_secret'] ?? config('services.vnpay.hash_secret'),
            'url'         => ($merchant['env'] ?? null) === 'production'
                                ? 'https://vnpayment.vn/paymentv2/vpcpay.html'
                                : (($merchant['env'] ?? null) === 'sandbox'
                                    ? 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'
                                    : config('services.vnpay.url')),
            'return_url'  => $merchant['return_url'] ?: config('services.vnpay.return_url'),
            'rate'        => (float) ($rate['vnd_per_usd'] ?? config('services.vnpay.rate')),
            'min_vnd'     => (int) config('services.vnpay.min_vnd'),
            default       => null,
        };
    }

    /**
     * Public — return current VND→USD rate so the seller wallet UI can show a
     * preview of how many USD they'll get for a given VND amount.
     */
    public function rate()
    {
        return response()->json([
            'vnd_to_usd_rate' => $this->setting('rate'),
            'min_vnd'         => $this->setting('min_vnd'),
        ]);
    }

    /**
     * Authenticated — seller posts the VND amount they want to deposit. We
     * snapshot the rate, create a pending wallet_transaction, and return the
     * VNPay redirect URL for the renderer to open in the user's browser.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $minVnd = $this->setting('min_vnd');
        $request->validate([
            'vnd_amount' => "required|numeric|min:{$minVnd}",
        ]);

        $vndAmount = (int) round($request->vnd_amount);
        $rate      = $this->setting('rate');
        $usdAmount = round($vndAmount / $rate, 2);

        // Unique txn ref — also serves as wallet_transactions.transaction_id
        $txnRef = 'BS' . time() . '-' . $user->id . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        $tx = WalletTransaction::create([
            'user_id'        => $user->id,
            'type'           => 'deposit',
            'status'         => WalletTransaction::STATUS_PENDING,
            'method'         => WalletTransaction::METHOD_VNPAY,
            'transaction_id' => $txnRef,
            'amount'         => $usdAmount,
            'vnd_amount'     => $vndAmount,
            'exchange_rate'  => $rate,
            'note'           => 'VNPay deposit',
        ]);

        $tmnCode    = $this->setting('tmn_code');
        $hashSecret = $this->setting('hash_secret');
        $vnpayUrl   = $this->setting('url');
        $returnUrl  = $this->setting('return_url');

        if (!$tmnCode || !$hashSecret) {
            return response()->json([
                'message' => 'VNPay is not configured. Set VNPAY_TMN_CODE and VNPAY_HASH_SECRET in .env',
            ], 503);
        }

        $params = [
            'vnp_Version'    => '2.1.0',
            'vnp_Command'    => 'pay',
            'vnp_TmnCode'    => $tmnCode,
            'vnp_Amount'     => $vndAmount * 100,           // VNPay expects amount × 100
            'vnp_CurrCode'   => 'VND',
            'vnp_TxnRef'     => $txnRef,
            'vnp_OrderInfo'  => "Deposit USD wallet for {$user->email}",
            'vnp_OrderType'  => 'other',
            'vnp_Locale'     => 'vn',
            'vnp_ReturnUrl'  => $returnUrl,
            'vnp_IpAddr'     => $request->ip(),
            'vnp_CreateDate' => now('Asia/Ho_Chi_Minh')->format('YmdHis'),
        ];

        ksort($params);
        $hashData = [];
        foreach ($params as $k => $v) {
            // Per VNPay docs: rawurlencode keys + values, join with `=` and `&`.
            $hashData[] = $k . '=' . rawurlencode($v);
        }
        $hashData = implode('&', $hashData);
        $secureHash = hash_hmac('sha512', $hashData, $hashSecret);

        $params['vnp_SecureHash'] = $secureHash;
        $paymentUrl = $vnpayUrl . '?' . http_build_query($params);

        return response()->json([
            'payment_url'   => $paymentUrl,
            'txn_ref'       => $txnRef,
            'vnd_amount'    => $vndAmount,
            'usd_amount'    => $usdAmount,
            'exchange_rate' => $rate,
        ]);
    }

    /**
     * Server-to-server callback from VNPay. No auth (VNPay won't be logged in).
     * Verifies HMAC, marks the transaction approved, and credits the seller's
     * wallet inside a transaction with row-level lock.
     */
    public function ipn(Request $request)
    {
        $params = $request->all();
        $secureHash = $params['vnp_SecureHash'] ?? '';
        unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);

        ksort($params);
        $hashData = [];
        foreach ($params as $k => $v) {
            $hashData[] = $k . '=' . rawurlencode($v);
        }
        $hashData = implode('&', $hashData);
        $expected = hash_hmac('sha512', $hashData, $this->setting('hash_secret'));

        if (!hash_equals($expected, $secureHash)) {
            return response()->json(['RspCode' => '97', 'Message' => 'Invalid signature']);
        }

        $tx = WalletTransaction::where('transaction_id', $params['vnp_TxnRef'] ?? '')->first();
        if (!$tx) {
            return response()->json(['RspCode' => '01', 'Message' => 'Order not found']);
        }
        if ($tx->status === WalletTransaction::STATUS_APPROVED) {
            return response()->json(['RspCode' => '02', 'Message' => 'Already confirmed']);
        }

        // Verify amount matches what we created.
        $expectedAmount = (int) ($tx->vnd_amount * 100);
        if ((int) ($params['vnp_Amount'] ?? 0) !== $expectedAmount) {
            Log::warning('VNPay amount mismatch', ['tx' => $tx->id, 'expected' => $expectedAmount, 'got' => $params['vnp_Amount'] ?? null]);
            return response()->json(['RspCode' => '04', 'Message' => 'Amount mismatch']);
        }

        $rspCode = $params['vnp_ResponseCode'] ?? '';
        $txStatus = $params['vnp_TransactionStatus'] ?? '';
        if ($rspCode !== '00' || $txStatus !== '00') {
            // Mark as a failed pending-attempt; don't credit. Keep status pending
            // so seller can retry. Append note for audit.
            $tx->update([
                'note' => trim(($tx->note ?? '') . " | VNPay rsp={$rspCode} ts={$txStatus}"),
            ]);
            return response()->json(['RspCode' => '00', 'Message' => 'Confirm but payment failed']);
        }

        DB::transaction(function () use ($tx) {
            $user = User::lockForUpdate()->find($tx->user_id);
            $balanceBefore = (float) $user->wallet;
            $user->increment('wallet', $tx->amount);
            $tx->update([
                'status'         => WalletTransaction::STATUS_APPROVED,
                'balance_before' => $balanceBefore,
                'balance_after'  => $user->fresh()->wallet,
            ]);
        });

        return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
    }

    /**
     * User-facing return URL after VNPay redirects them back. Don't credit
     * here (the IPN does the actual work) — this is just the success/fail
     * page the user sees in their browser.
     */
    public function return(Request $request)
    {
        $code = $request->vnp_ResponseCode;
        $txnRef = $request->vnp_TxnRef;
        $vndAmount = (int) ($request->vnp_Amount ?? 0) / 100;

        $tx = WalletTransaction::where('transaction_id', $txnRef)->first();

        return view('vnpay.return', [
            'code'      => $code,
            'success'   => $code === '00',
            'txnRef'    => $txnRef,
            'vndAmount' => $vndAmount,
            'tx'        => $tx,
        ]);
    }
}
