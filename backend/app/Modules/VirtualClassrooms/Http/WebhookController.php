<?php

namespace App\Modules\VirtualClassrooms\Http;

use App\Http\Controllers\Controller;
use App\Models\LessonSession;
use App\Models\SessionParticipant;
use App\Modules\VirtualClassrooms\LessonSessionService;
use App\Modules\VirtualClassrooms\Media\MediaProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /** SFU events keep our records truthful when clients vanish (crash, network loss). Signature required. */
    public function livekit(Request $request, MediaProvider $media): JsonResponse
    {
        $ev = $media->parseWebhook($request->getContent(), $request->header('Authorization'));
        abort_unless($ev, 401);
        $ev += ['room' => null, 'identity' => null, 'event' => ''];
        $session = $ev['room'] ? LessonSession::withoutGlobalScopes()->where('room_name', $ev['room'])->first() : null;
        if (! $session) {
            return response()->json(['ok' => true]);        // unknown room: ack so the SFU does not retry forever
        }
        app(\App\Modules\Tenancy\CurrentSchool::class)->run(\App\Models\School::findOrFail($session->school_id), function () use ($ev, $session) {
            $uid = $ev['identity'] && str_starts_with($ev['identity'], 'u') ? (int) substr($ev['identity'], 1) : null;
            match ($ev['event']) {
                'participant_left' => $uid && SessionParticipant::where('lesson_session_id', $session->id)->where('user_id', $uid)->whereNull('left_at')->update(['left_at' => now()]),
                'room_finished' => app(LessonSessionService::class)->finish($session->refresh(), 'ended'),
                default => null,
            };
        });

        return response()->json(['ok' => true]);
    }
}
