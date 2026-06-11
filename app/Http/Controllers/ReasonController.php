<?php

namespace App\Http\Controllers;

use App\Models\Reason;
use Illuminate\Http\Request;

class ReasonController extends Controller
{
    /** All reasons (readable by any authenticated user — needed to fill reprints). */
    public function index()
    {
        return response()->json(Reason::orderBy('name')->get());
    }

    /** Look up a reason by its scanned code (e.g. "RSN7K2A"). */
    public function byCode(string $code)
    {
        $reason = Reason::where('code', trim($code))->first();
        if (!$reason) {
            return response()->json(['message' => "No reason for code \"{$code}\""], 404);
        }
        return response()->json($reason);
    }

    /** Create a reason — code is auto-generated; qr_code stores the QR payload (= code). */
    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);
        $code = Reason::generateCode();

        $reason = Reason::create([
            'name'    => $data['name'],
            'code'    => $code,
            'qr_code' => $code,
        ]);

        return response()->json($reason, 201);
    }

    public function update(Request $request, Reason $reason)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);
        $reason->update(['name' => $data['name']]);
        return response()->json($reason);
    }

    public function destroy(Reason $reason)
    {
        $reason->delete();
        return response()->json(['ok' => true]);
    }
}
