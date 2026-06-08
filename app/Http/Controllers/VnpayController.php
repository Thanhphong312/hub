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

        // Active environment selects which credential pair is used. Sandbox
        // and production creds are stored separately so switching env doesn't
        // wipe the other set. Legacy flat tmn_code/hash_secret still honored
        // as a fallback.
        $env = $merchant['env'] ?? 'sandbox';
        $isProd = $env === 'production';
        $tmn = $isProd
            ? ($merchant['production_tmn_code'] ?? null)
            : ($merchant['sandbox_tmn_code'] ?? null);
        $secret = $isProd
            ? ($merchant['production_hash_secret'] ?? null)
            : ($merchant['sandbox_hash_secret'] ?? null);

        return match ($key) {
            'tmn_code'    => $tmn ?: ($merchant['tmn_code'] ?? config('services.vnpay.tmn_code')),
            'hash_secret' => $secret ?: ($merchant['hash_secret'] ?? config('services.vnpay.hash_secret')),
            'url'         => $isProd
                                ? 'https://vnpayment.vn/paymentv2/vpcpay.html'
                                : 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
            'return_url'  => $merchant['return_url'] ?: config('services.vnpay.return_url'),
            'rate'        => (float) ($rate['vnd_per_usd'] ?? config('services.vnpay.rate')),
            'min_vnd'     => (int) config('services.vnpay.min_vnd'),
            'env'         => $env,
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
            'user_id'    => 'sometimes|exists:users,id',
        ]);

        // Sellers can only top up their own wallet; admin/staff can top up on
        // behalf of any user (same rule as manual deposit). The target user is
        // who gets credited by the IPN, since it reads $tx->user_id.
        $targetUser = $user->hasRole('seller')
            ? $user
            : ($request->filled('user_id') ? User::find($request->user_id) : $user);

        $vndAmount = (int) round($request->vnd_amount);
        $rate      = $this->setting('rate');
        $usdAmount = round($vndAmount / $rate, 2);

        // Unique txn ref — also serves as wallet_transactions.transaction_id
        $txnRef = 'BS' . time() . '-' . $targetUser->id . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        $tx = WalletTransaction::create([
            'user_id'        => $targetUser->id,
            'type'           => 'deposit',
            'status'         => WalletTransaction::STATUS_PENDING,
            'method'         => WalletTransaction::METHOD_VNPAY,
            'transaction_id' => $txnRef,
            'amount'         => $usdAmount,
            'vnd_amount'     => $vndAmount,
            'exchange_rate'  => $rate,
            'note'           => $targetUser->id === $user->id
                ? 'VNPay deposit'
                : 'VNPay deposit (topup by ' . ($user->name ?? $user->email) . ')',
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
            'vnp_OrderInfo'  => "Deposit USD wallet for {$targetUser->email}",
            'vnp_OrderType'  => 'other',
            'vnp_Locale'     => 'vn',
            'vnp_ReturnUrl'  => $returnUrl,
            'vnp_IpAddr'     => $request->ip(),
            'vnp_CreateDate' => now('Asia/Ho_Chi_Minh')->format('YmdHis'),
        ];

        // Build hashData + query with the SAME urlencode pass (VNPay 2.1.0
        // demo). The previous code hashed with rawurlencode (space → %20) but
        // sent the URL via http_build_query (space → +) — VNPay recomputed the
        // hash over '+' and rejected it as "Sai chữ ký" (code 70). Both must
        // use urlencode and be built identically.
        ksort($params);
        $hashData = '';
        $query = '';
        $i = 0;
        foreach ($params as $k => $v) {
            if ($i === 1) {
                $hashData .= '&' . urlencode($k) . '=' . urlencode($v);
            } else {
                $hashData .= urlencode($k) . '=' . urlencode($v);
                $i = 1;
            }
            $query .= urlencode($k) . '=' . urlencode($v) . '&';
        }
        $secureHash = hash_hmac('sha512', $hashData, $hashSecret);
        $paymentUrl = $vnpayUrl . '?' . $query . 'vnp_SecureHash=' . $secureHash;

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
        $all = $request->all();
        $secureHash = $all['vnp_SecureHash'] ?? '';

        // Only vnp_* fields participate in the checksum (VNPay demo). Build the
        // hash with urlencode, matching how create() signs the outbound URL.
        $params = [];
        foreach ($all as $k => $v) {
            if (str_starts_with($k, 'vnp_')) $params[$k] = $v;
        }
        unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);

        ksort($params);
        $hashData = '';
        $i = 0;
        foreach ($params as $k => $v) {
            if ($i === 1) {
                $hashData .= '&' . urlencode($k) . '=' . urlencode($v);
            } else {
                $hashData .= urlencode($k) . '=' . urlencode($v);
                $i = 1;
            }
        }
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
