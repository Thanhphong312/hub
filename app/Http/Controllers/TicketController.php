<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Order support threads, shared by all three apps: sellers open them from
 * web-bullstart, partners from partner-bullstart, staff answer from
 * bullstart-app.
 *
 * Every read goes through Ticket::scopeVisibleTo() — the platform/ownership
 * rules live there and nowhere else. Writes re-check the same way: a reply is
 * allowed exactly when the thread is readable.
 */
class TicketController extends Controller
{
    /**
     * Orders are exposed with a deliberately small column set: partners must
     * not see prices or the seller's name, and no ticket screen needs them.
     * Staff open the full order from the Orders page instead.
     */
    private const ORDER_COLUMNS = 'order:id,system_id,ref_id,status';

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Ticket::visibleTo($user)
            ->with([self::ORDER_COLUMNS, 'creator:id,name', 'solver:id,name'])
            ->withCount('items');

        if ($request->filled('order_id')) {
            $query->where('order_id', (int) $request->order_id);
        }
        if ($request->filled('platform')) {
            $query->where('platform', (int) $request->platform);
        }
        if ($request->filled('status')) {
            $query->where('status', (int) $request->status);
        }
        // Everything still needing attention — open OR replied — in one filter,
        // which is what a support queue actually wants.
        if ($request->boolean('unresolved')) {
            $query->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_NEW_MESSAGE]);
        }
        // Look a ticket up by the order it hangs off, which is how operators
        // actually search: they have a system_id, not a ticket id.
        if ($request->filled('system_id')) {
            $sid = $request->system_id;
            $query->whereHas('order', fn ($q) => $q->where('system_id', 'like', "%{$sid}%"));
        }
        if ($request->filled('subject')) {
            $query->where('subject', 'like', '%' . $request->subject . '%');
        }

        return response()->json(
            $query->orderByDesc('id')->paginate(min(100, max(1, (int) $request->get('per_page', 20))))
        );
    }

    public function show(Request $request, Ticket $ticket)
    {
        $this->authorizeRead($request->user(), $ticket);

        return response()->json([
            'ticket' => $ticket->load([
                self::ORDER_COLUMNS,
                'creator:id,name',
                'solver:id,name',
                'items.sender:id,name',
            ]),
        ]);
    }

    /**
     * Open a thread on an order. The platform comes from the caller's role, so
     * a seller can never file a partner-only ticket (or vice versa); staff must
     * name the platform explicitly since they belong to neither side.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'subject'  => 'required|string|max:255',
            'content'  => 'required|string|max:20000',
            'platform' => 'nullable|integer|in:' . implode(',', Ticket::PLATFORMS),
        ]);

        $platform = Ticket::platformFor($user) ?? $data['platform'] ?? null;
        if ($platform === null) {
            return response()->json([
                'message' => 'Chọn platform (1 = seller, 2 = partner) cho ticket này.',
            ], 422);
        }

        $order = Order::find($data['order_id']);
        if (!$this->canOpenOn($user, $order, $platform)) {
            return response()->json(['message' => 'Không có quyền tạo ticket cho đơn này'], 403);
        }

        // Opening message lives on the ticket; ticket_items holds replies only.
        $ticket = Ticket::create([
            'order_id'   => $data['order_id'],
            'created_by' => $user->id,
            'subject'    => $data['subject'],
            'content'    => $data['content'],
            'platform'   => $platform,
            'status'     => Ticket::STATUS_OPEN,
        ]);

        return response()->json([
            'message' => 'Ticket created',
            'ticket'  => $ticket->load([self::ORDER_COLUMNS, 'creator:id,name']),
        ], 201);
    }

    /**
     * Post a reply. Readable ⇒ replyable.
     *
     * A reply moves the thread to `new_message`, including one that was closed:
     * an answer arriving after someone hit Solve would otherwise sit unseen in
     * a thread nobody looks at again. Re-opening also clears solved_by/at, so
     * "who solved this" never points at a thread that is live again.
     */
    public function reply(Request $request, Ticket $ticket)
    {
        $user = $request->user();
        $this->authorizeRead($user, $ticket);

        $data = $request->validate(['content' => 'required|string|max:20000']);

        $item = DB::transaction(function () use ($ticket, $user, $data) {
            $item = TicketItem::create([
                'ticket_id' => $ticket->id,
                'send_by'   => $user->id,
                'content'   => $data['content'],
            ]);
            $ticket->update([
                'status'    => Ticket::STATUS_NEW_MESSAGE,
                'solved_by' => null,
                'solved_at' => null,
            ]);

            return $item;
        });

        return response()->json([
            'message' => 'Reply sent',
            'item'    => $item->load('sender:id,name'),
            'ticket'  => $ticket->fresh(),
        ], 201);
    }

    /**
     * Mark solved / re-open. Anyone who can read the thread can close it — the
     * person who raised it is usually the one who knows it is done — and
     * re-opening is the undo, so a mis-click costs nothing.
     */
    public function solve(Request $request, Ticket $ticket)
    {
        $user = $request->user();
        $this->authorizeRead($user, $ticket);

        $solved = $request->boolean('solved', true);
        $ticket->update([
            'status'    => $solved ? Ticket::STATUS_CLOSE : Ticket::STATUS_OPEN,
            'solved_at' => $solved ? now() : null,
            'solved_by' => $solved ? $user->id : null,
        ]);

        return response()->json([
            'message' => $solved ? 'Ticket solved' : 'Ticket re-opened',
            'ticket'  => $ticket->fresh()->load('solver:id,name'),
        ]);
    }

    /** 404 rather than 403 for threads the user may not read — their existence is not public. */
    private function authorizeRead(?User $user, Ticket $ticket): void
    {
        $ok = Ticket::visibleTo($user)->whereKey($ticket->id)->exists();
        abort_unless($ok, 404, 'Ticket not found');
    }

    /**
     * May `$user` open a `$platform` thread on `$order`? Mirrors the read rules
     * in Ticket::scopeVisibleTo — a ticket you could not read afterwards is not
     * one you may create.
     */
    private function canOpenOn(?User $user, ?Order $order, int $platform): bool
    {
        if (!$user || !$order) return false;

        if ($user->hasRole('admin') || $user->hasRole('support')) return true;

        if ($user->hasRole('seller')) {
            return $platform === Ticket::PLATFORM_SELLER && $order->user_id === $user->id;
        }

        if ($user->hasRole('partner')) {
            return $platform === Ticket::PLATFORM_PARTNER && $order->partner_id === $user->id;
        }

        return false;
    }
}
