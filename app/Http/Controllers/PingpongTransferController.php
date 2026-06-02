<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\WalletTransaction;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PingPong (USD wire) top-up — mirrors the BankTransferController flow:
 *
 *   init()    — pure preview. Returns a unique reference + the PingPong
 *               account snapshot (already configured in Settings →
 *               Invoice Payment under the `invoice_pingpong` key). NO DB
 *               write, NO Telegram. Seller wires the money themselves.
 *
 *   confirm() — called once the seller's bank confirms the wire went out.
 *               Body: { reference, amount_usd, pingpong_ref?, note?,
 *               screenshot_url? }. Persists a pending wallet_transaction
 *               (method=pingpong, transaction_id=reference) and fires the
 *               single Telegram ping so admin can verify + approve from
 *               the Wallet UI.
 *
 * PingPong settles in USD so there's no rate conversion — amount_usd is
 * what hits the wallet on approval.
 */
class PingpongTransferController extends Controller
{
    /**
     * Public account info pulled from the invoice_pingpong setting.
     * Returns the same shape regardless of which seller calls.
     */
    public function rate()
    {
        $pp = AppSetting::getJson('invoice_pingpong', [
            'account_name' => '', 'account_number' => '', 'bank_name' => '',
            'swift_code' => '', 'bank_address' => '', 'notes' => '',
        ]);
        return response()->json([
            'enabled'        => !empty($pp['account_number']),
            'min_usd'        => 1.0,
            'account_name'   => $pp['account_name'] ?? '',
            'account_number' => $pp['account_number'] ?? '',
            'bank_name'      => $pp['bank_name'] ?? '',
            'swift_code'     => $pp['swift_code'] ?? '',
            'bank_address'   => $pp['bank_address'] ?? '',
            'notes'          => $pp['notes'] ?? '',
        ]);
    }

    public function init(Request $request)
    {
        $pp = AppSetting::getJson('invoice_pingpong', []);
        if (empty($pp['account_number'])) {
            return response()->json(['message' => 'PingPong account is not configured. Ask admin to set it in Settings → Invoice Payment.'], 422);
        }

        $data = $request->validate([
            'amount_usd' => 'required|numeric|min:1',
        ]);
        $amountUsd = round((float) $data['amount_usd'], 2);

        do {
            $ref = 'PP' . strtoupper(Str::random(6));
        } while (WalletTransaction::where('transaction_id', $ref)->exists());

        return response()->json([
            'reference'      => $ref,
            'amount_usd'     => $amountUsd,
            // Seller will write this in the wire memo so admin can match.
            'content'        => "BS {$ref}",
            'account_name'   => $pp['account_name']   ?? '',
            'account_number' => $pp['account_number'] ?? '',
            'bank_name'      => $pp['bank_name']      ?? '',
            'swift_code'     => $pp['swift_code']     ?? '',
            'bank_address'   => $pp['bank_address']   ?? '',
            'notes'          => $pp['notes']          ?? '',
        ]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate([
            'reference'      => 'required|string|max:32',
            'amount_usd'     => 'required|numeric|min:1',
            'pingpong_ref'   => 'nullable|string|max:120',
            'note'           => 'nullable|string|max:500',
            'screenshot_url' => 'nullable|url|max:1024',
        ]);

        if (WalletTransaction::where('transaction_id', $data['reference'])->exists()) {
            return response()->json(['message' => 'Reference đã được dùng, vui lòng tạo lại mới.'], 409);
        }

        $user      = $request->user();
        $amountUsd = round((float) $data['amount_usd'], 2);

        $noteParts = ["PingPong wire · BS {$data['reference']}"];
        if (!empty($data['pingpong_ref']))   $noteParts[] = 'pingpong_ref=' . $data['pingpong_ref'];
        if (!empty($data['screenshot_url'])) $noteParts[] = 'screenshot=' . $data['screenshot_url'];
        if (!empty($data['note']))           $noteParts[] = 'seller_note=' . $data['note'];

        $tx = WalletTransaction::create([
            'user_id'        => $user->id,
            'type'           => 'deposit',
            'status'         => WalletTransaction::STATUS_PENDING,
            'method'         => WalletTransaction::METHOD_PINGPONG,
            'transaction_id' => $data['reference'],
            'amount'         => $amountUsd,
            'note'           => implode(' | ', $noteParts),
        ]);

        $pp = AppSetting::getJson('invoice_pingpong', []);
        app(TelegramService::class)->notifyTopup(implode("\n", [
            '🔔 <b>Seller confirmed PingPong wire</b>',
            sprintf('User: %s (#%s)', e($user->name), $user->id),
            sprintf('Amount: <b>$%s</b>', number_format($amountUsd, 2)),
            sprintf('Reference: <code>%s</code>', e($data['reference'])),
            sprintf('Account: %s · %s', e($pp['account_name'] ?? ''), e($pp['account_number'] ?? '')),
            !empty($data['pingpong_ref'])   ? 'PingPong ref: <code>' . e($data['pingpong_ref']) . '</code>' : null,
            !empty($data['screenshot_url']) ? 'Screenshot: ' . e($data['screenshot_url'])                   : null,
            !empty($data['note'])           ? 'Note: ' . e($data['note'])                                  : null,
            sprintf('Tx id: %s — chờ admin approve qua Wallet.', $tx->id),
        ]));

        return response()->json([
            'message'     => 'Confirmation recorded — admin sẽ duyệt khi nhận được tiền.',
            'transaction' => $tx,
        ], 201);
    }
}
