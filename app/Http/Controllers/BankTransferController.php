<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Bank-transfer top-up — a manual / "fake VNPay" deposit flow used while the
 * real VNPay merchant integration isn't live. The seller enters USD, the
 * server snapshots the VND/USD rate, generates a pending wallet_transaction
 * with a unique reference, returns the bank info + QR for the seller to scan,
 * and pings Telegram so an admin can verify the incoming transfer and
 * approve the deposit through the existing /wallet/deposit/{id}/approve flow.
 */
class BankTransferController extends Controller
{
    public const SETTING_KEY = 'bank_transfer';

    /**
     * Default shape — admin overrides these via PUT /api/settings/bank-transfer.
     * `qr_template_url` accepts {amount} and {content} placeholders so the
     * admin can plug any QR provider (VietQR, MoMo, OTP…). We pre-fill it
     * with a VietQR.io endpoint that bakes amount + content into the image.
     */
    public static function settings(): array
    {
        return AppSetting::getJson(self::SETTING_KEY, [
            'enabled'         => true,
            'rate'            => 26000,       // VND per 1 USD
            'min_usd'         => 1.0,
            'bank_name'       => '',
            'account_no'      => '',
            'account_holder'  => '',
            'branch'          => '',
            // VietQR template — auto-renders QR for any amount + content.
            // Format docs: https://www.vietqr.io/danh-sach-api/link-tao-ma-qr/
            // Replace {bank}, {account}, {amount}, {content} ourselves before
            // showing the QR to the seller.
            'qr_template_url' => 'https://img.vietqr.io/image/{bank}-{account}-compact2.png?amount={amount}&addInfo={content}&accountName={holder}',
            // {ref} is the unique transaction reference the seller must include.
            'content_template' => 'BS {ref}',
        ]);
    }

    public function getSettings()
    {
        return response()->json(self::settings());
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled'          => 'nullable|boolean',
            'rate'             => 'nullable|numeric|min:1',
            'min_usd'          => 'nullable|numeric|min:0',
            'bank_name'        => 'nullable|string|max:255',
            'account_no'       => 'nullable|string|max:64',
            'account_holder'   => 'nullable|string|max:255',
            'branch'           => 'nullable|string|max:255',
            'qr_template_url'  => 'nullable|string|max:2048',
            'content_template' => 'nullable|string|max:255',
        ]);
        $merged = array_merge(self::settings(), $data);
        AppSetting::setJson(self::SETTING_KEY, $merged);
        return response()->json($merged);
    }

    /**
     * GET /api/wallet/bank-transfer/rate — public-ish (still auth-gated by
     * the Sanctum group) so the wallet UI can show the current rate + min
     * without needing admin access to the full settings.
     */
    public function rate()
    {
        $s = self::settings();
        return response()->json([
            'enabled'   => (bool) ($s['enabled'] ?? true),
            'rate'      => (float) ($s['rate'] ?? 26000),
            'min_usd'   => (float) ($s['min_usd'] ?? 1.0),
            'bank_name' => $s['bank_name'] ?? '',
        ]);
    }

    /**
     * POST /api/wallet/bank-transfer/init
     * body: { amount_usd: number }
     * → Pure PREVIEW: generates a unique reference, computes the VND amount
     *   from the rate, and returns the bank info + QR. NO wallet_transaction
     *   is created here. The seller is expected to actually transfer first,
     *   then POST /confirm with this reference — that's when the tx is
     *   persisted and Telegram pings admin. This keeps the admin inbox
     *   clean of abandoned-QR pendings.
     */
    public function init(Request $request)
    {
        $s = self::settings();
        if (empty($s['enabled'])) {
            return response()->json(['message' => 'Bank-transfer top-up is disabled'], 422);
        }
        if (empty($s['bank_name']) || empty($s['account_no'])) {
            return response()->json(['message' => 'Bank account is not configured. Ask admin to set it in Settings.'], 422);
        }

        $data = $request->validate([
            'amount_usd' => 'required|numeric|min:' . (float) ($s['min_usd'] ?? 1.0),
        ]);

        $amountUsd = round((float) $data['amount_usd'], 2);
        $rate      = (float) ($s['rate'] ?? 26000);
        $amountVnd = (int) round($amountUsd * $rate);

        // Reference the seller types into the bank transfer memo so admin
        // can match the incoming bank entry to the wallet_transaction once
        // confirm() persists it. Re-roll if any past tx already used the
        // string (cheap unique-ish guarantee without holding a DB row).
        do {
            $ref = 'BT' . strtoupper(Str::random(6));
        } while (WalletTransaction::where('transaction_id', $ref)->exists());

        $content = str_replace('{ref}', $ref, $s['content_template'] ?? 'BS {ref}');
        $qrUrl   = $this->renderQr($s, $amountVnd, $content);

        return response()->json([
            'reference'      => $ref,
            'content'        => $content,
            'amount_usd'     => $amountUsd,
            'amount_vnd'     => $amountVnd,
            'rate'           => $rate,
            'bank_name'      => $s['bank_name'] ?? '',
            'account_no'     => $s['account_no'] ?? '',
            'account_holder' => $s['account_holder'] ?? '',
            'branch'         => $s['branch'] ?? '',
            'qr_url'         => $qrUrl,
        ]);
    }

    /**
     * POST /api/wallet/bank-transfer/confirm
     * body: { reference, amount_usd, bank_ref?, note?, screenshot_url? }
     *
     * Called after the seller actually transferred the money. We create the
     * pending wallet_transaction NOW (init() was preview only) with the
     * reference shown in the QR + the seller's bank-side ref appended to
     * note, then fire a single Telegram notification so admin can approve.
     * The tx ends up status=pending until admin approves through Wallet.
     */
    public function confirm(Request $request)
    {
        $s = self::settings();
        if (empty($s['enabled'])) {
            return response()->json(['message' => 'Bank-transfer top-up is disabled'], 422);
        }

        $data = $request->validate([
            'reference'      => 'required|string|max:32',
            'amount_usd'     => 'required|numeric|min:' . (float) ($s['min_usd'] ?? 1.0),
            'bank_ref'       => 'nullable|string|max:120',
            'note'           => 'nullable|string|max:500',
            'screenshot_url' => 'nullable|url|max:1024',
        ]);

        $user = $request->user();

        // Reference must not already be in use (init() only returns refs
        // that were unused at the time; another confirm could race in).
        if (WalletTransaction::where('transaction_id', $data['reference'])->exists()) {
            return response()->json(['message' => 'Reference đã được dùng, vui lòng tạo lại QR mới.'], 409);
        }

        $amountUsd = round((float) $data['amount_usd'], 2);
        $rate      = (float) ($s['rate'] ?? 26000);
        $amountVnd = (int) round($amountUsd * $rate);
        $content   = str_replace('{ref}', $data['reference'], $s['content_template'] ?? 'BS {ref}');

        $noteParts = ["Bank transfer · {$content}"];
        if (!empty($data['bank_ref']))       $noteParts[] = 'bank_ref=' . $data['bank_ref'];
        if (!empty($data['screenshot_url'])) $noteParts[] = 'screenshot=' . $data['screenshot_url'];
        if (!empty($data['note']))           $noteParts[] = 'seller_note=' . $data['note'];

        $tx = WalletTransaction::create([
            'user_id'        => $user->id,
            'type'           => 'deposit',
            'status'         => WalletTransaction::STATUS_PENDING,
            'method'         => WalletTransaction::METHOD_BANK_TRANSFER,
            'transaction_id' => $data['reference'],
            'amount'         => $amountUsd,
            'vnd_amount'     => $amountVnd,
            'exchange_rate'  => $rate,
            'note'           => implode(' | ', $noteParts),
        ]);

        app(TelegramService::class)->notifyTopup(implode("\n", [
            '🔔 <b>Seller confirmed bank transfer</b>',
            sprintf('User: %s (#%s)', e($user->name), $user->id),
            sprintf('Amount: <b>$%s</b>  (₫%s @ rate %s)', number_format($amountUsd, 2), number_format($amountVnd), number_format($rate)),
            sprintf('Reference: <code>%s</code>', e($data['reference'])),
            sprintf('Bank: %s · %s · %s', e($s['bank_name'] ?? ''), e($s['account_no'] ?? ''), e($s['account_holder'] ?? '')),
            !empty($data['bank_ref'])       ? 'Bank ref: <code>' . e($data['bank_ref']) . '</code>' : null,
            !empty($data['screenshot_url']) ? 'Screenshot: ' . e($data['screenshot_url'])           : null,
            !empty($data['note'])           ? 'Note: ' . e($data['note'])                          : null,
            sprintf('Tx id: %s — chờ admin approve qua Wallet.', $tx->id),
        ]));

        return response()->json([
            'message'     => 'Confirmation recorded — admin sẽ duyệt khi nhận được tiền.',
            'transaction' => $tx,
        ], 201);
    }

    private function renderQr(array $s, int $amountVnd, string $content): string
    {
        $tpl = $s['qr_template_url'] ?? '';
        if ($tpl === '') return '';
        // Strip everything except letters/digits from bank/account so they
        // slot into URL paths cleanly (VietQR uses bank short code).
        $bank    = preg_replace('/[^A-Za-z0-9]/', '', $s['bank_name'] ?? '');
        $account = preg_replace('/[^A-Za-z0-9]/', '', $s['account_no'] ?? '');
        return strtr($tpl, [
            '{bank}'    => $bank,
            '{account}' => $account,
            '{amount}'  => (string) $amountVnd,
            '{content}' => rawurlencode($content),
            '{holder}'  => rawurlencode($s['account_holder'] ?? ''),
        ]);
    }
}
