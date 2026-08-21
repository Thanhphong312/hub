<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PartnerPayout;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Paying partners for the printing. Admin-only — see routes/api.php.
 *
 * The counterpart of WalletController, which handles money coming IN from
 * sellers. What a partner is owed is never a stored balance: it is what they
 * have earned on shipped orders minus what has been paid out, so neither
 * figure can drift from the rows it is summed from.
 */
class PartnerPayoutController extends Controller
{
    /** Payout history, newest first. Optionally scoped to one partner. */
    public function index(Request $request)
    {
        $query = PartnerPayout::with('partner:id,name', 'creator:id,name')
            ->orderByDesc('id');

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }

        return response()->json($query->paginate(min(100, (int) $request->get('per_page', 25))));
    }

    /** Earned, paid, and the difference. */
    public function summary(Request $request, User $partner)
    {
        abort_unless($partner->hasRole('partner'), 404, 'Not a partner');

        $earned = $this->earnedTotal($partner);
        $paid = $this->paidTotal($partner);

        return response()->json([
            'partner' => ['id' => $partner->id, 'name' => $partner->name],
            'earned' => $earned,
            'paid' => $paid,
            'owed' => round($earned - $paid, 2),
            'orders' => Order::where('partner_id', $partner->id)
                ->where('status', Order::STATUS_SHIPPED)
                ->whereNotNull('partner_revenue')
                ->count(),
            // Shipped with no amount yet: something is owed on them, but how
            // much is unknown until Tính tiền runs, so they are missing from
            // `earned` and must not be silently absent from the screen either.
            'unpriced' => Order::where('partner_id', $partner->id)
                ->where('status', Order::STATUS_SHIPPED)
                ->whereNull('partner_revenue')
                ->count(),
        ]);
    }

    /** Record a payment to a partner. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|in:'.implode(',', PartnerPayout::METHODS),
            'transaction_id' => 'nullable|string|max:255',
            'period_from' => 'nullable|date',
            'period_to' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
        ]);

        $partner = User::findOrFail($data['user_id']);
        abort_unless($partner->hasRole('partner'), 422, 'User này không phải partner');

        $payout = DB::transaction(function () use ($data, $partner, $request) {
            $payout = PartnerPayout::create([
                'user_id' => $partner->id,
                'amount' => round((float) $data['amount'], 2),
                'method' => $data['method'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'period_from' => $data['period_from'] ?? null,
                'period_to' => $data['period_to'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            // Stamped after the row exists so it counts itself.
            $payout->update(['balance_after' => $this->owedTotal($partner)]);

            return $payout->fresh();
        });

        return response()->json([
            'message' => 'Đã ghi nhận trả $'.number_format($payout->amount, 2)." cho {$partner->name}"
                .' · còn nợ $'.number_format($payout->balance_after, 2),
            'payout' => $payout,
            'owed' => (float) $payout->balance_after,
        ], 201);
    }

    /**
     * Undo a payout — the amount goes back onto what is owed.
     *
     * A payout the partner has confirmed receiving is a receipt both sides
     * agreed on; deleting it would erase their acknowledgement along with the
     * row. Still possible with ?force=1, so a genuine mistake can be fixed, but
     * never as a side effect of a normal cancel.
     */
    public function destroy(Request $request, PartnerPayout $payout)
    {
        if ($payout->confirmed_at && ! $request->boolean('force')) {
            return response()->json([
                'message' => 'Partner đã xác nhận nhận được khoản này ('
                    .$payout->confirmed_at->format('d/m/Y H:i').'). Huỷ sẽ xoá luôn xác nhận đó.',
                'needs_force' => true,
            ], 409);
        }

        $partner = $payout->partner;
        $amount = (float) $payout->amount;
        $payout->delete();

        return response()->json([
            'message' => 'Đã huỷ giao dịch $'.number_format($amount, 2)
                .($partner ? ' · còn nợ $'.number_format($this->owedTotal($partner), 2) : ''),
            'owed' => $partner ? $this->owedTotal($partner) : null,
        ]);
    }

    /**
     * What the partner has earned: their share of the orders they shipped.
     *
     * Orders with no amount are simply absent — an unknown is not a zero, and
     * counting it as one would understate the debt.
     */
    private function earnedTotal(User $partner): float
    {
        return round((float) Order::where('partner_id', $partner->id)
            ->where('status', Order::STATUS_SHIPPED)
            ->sum('partner_revenue'), 2);
    }

    private function paidTotal(User $partner): float
    {
        return round((float) PartnerPayout::where('user_id', $partner->id)->sum('amount'), 2);
    }

    private function owedTotal(User $partner): float
    {
        return round($this->earnedTotal($partner) - $this->paidTotal($partner), 2);
    }
}
