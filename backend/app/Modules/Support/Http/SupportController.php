<?php

namespace App\Modules\Support\Http;

use App\Http\Controllers\Controller;
use App\Models\SupportAccessGrant;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportController extends Controller
{
    // ---- school users: open and follow own tickets (school-scoped, but table is not tenant-scoped on purpose:
    // platform support must see tickets of all schools).
    public function mine(Request $request): JsonResponse
    {
        return response()->json(SupportTicket::where('user_id', $request->user()->id)->orderByDesc('id')->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['subject' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:5000'],
            'category' => ['sometimes', Rule::in(['technical', 'billing', 'abuse', 'meeting', 'other'])], 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])]]);
        $t = SupportTicket::create(['school_id' => app(CurrentSchool::class)->id(), 'user_id' => $request->user()->id, 'subject' => $d['subject'],
            'category' => $d['category'] ?? 'technical', 'priority' => $d['priority'] ?? 'normal']);
        SupportTicketMessage::create(['support_ticket_id' => $t->id, 'user_id' => $request->user()->id, 'body' => $d['body']]);

        return response()->json(['data' => $t], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $t = SupportTicket::where('user_id', $request->user()->id)->findOrFail($id);

        return response()->json(['data' => $t, 'messages' => $t->messages()->where('internal', false)->orderBy('id')->get()]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $t = SupportTicket::where('user_id', $request->user()->id)->findOrFail($id);
        abort_if($t->status === 'closed', 422, 'این درخواست بسته شده است.');
        $d = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $m = SupportTicketMessage::create(['support_ticket_id' => $t->id, 'user_id' => $request->user()->id, 'body' => $d['body']]);
        $t->update(['status' => 'open']);

        return response()->json(['data' => $m], 201);
    }

    // ---- platform side (super_admin / support)
    public function platformIndex(Request $request): JsonResponse
    {
        $q = SupportTicket::query()->orderByDesc('id');
        foreach (['status', 'school_id', 'category'] as $f) {
            $request->filled($f) && $q->where($f, $request->input($f));
        }

        return response()->json($q->paginate(25));
    }

    public function platformShow(int $id): JsonResponse
    {
        $t = SupportTicket::findOrFail($id);

        return response()->json(['data' => $t, 'messages' => $t->messages()->orderBy('id')->get()]);
    }

    public function platformReply(Request $request, int $id): JsonResponse
    {
        $t = SupportTicket::findOrFail($id);
        $d = $request->validate(['body' => ['required', 'string', 'max:5000'], 'internal' => ['sometimes', 'boolean'], 'status' => ['sometimes', Rule::in(['open', 'pending', 'resolved', 'closed'])]]);
        $m = SupportTicketMessage::create(['support_ticket_id' => $t->id, 'user_id' => $request->user()->id, 'body' => $d['body'], 'internal' => $d['internal'] ?? false]);
        $t->update(['status' => $d['status'] ?? 'pending', 'assigned_to' => $t->assigned_to ?? $request->user()->id]);
        Audit::record('support.ticket_reply', $t, null, ['status' => $t->status], $t->school_id);

        return response()->json(['data' => $m], 201);
    }

    // ---- consent-based access to a school's data for support staff
    public function grant(Request $request): JsonResponse
    {
        $d = $request->validate(['support_user_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:255'], 'hours' => ['required', 'integer', 'between:1,72']]);
        abort_unless(User::whereKey($d['support_user_id'])->where('platform_role', 'support')->exists(), 422, 'کاربر پشتیبان معتبر نیست.');
        $g = SupportAccessGrant::create(['support_user_id' => $d['support_user_id'], 'granted_by' => $request->user()->id, 'reason' => $d['reason'], 'expires_at' => now()->addHours($d['hours'])]);
        Audit::record('support.access_granted', $g, null, ['support_user_id' => $d['support_user_id'], 'hours' => $d['hours']]);

        return response()->json(['data' => $g], 201);
    }

    public function revoke(int $id): JsonResponse
    {
        $g = SupportAccessGrant::findOrFail($id);
        $g->update(['revoked_at' => now()]);
        Audit::record('support.access_revoked', $g);

        return response()->json(['data' => $g]);
    }

    public function grants(): JsonResponse
    {
        return response()->json(['data' => SupportAccessGrant::orderByDesc('id')->limit(50)->get()]);
    }
}
