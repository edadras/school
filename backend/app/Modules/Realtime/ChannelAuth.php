<?php

namespace App\Modules\Realtime;

use App\Models\ConversationParticipant;
use App\Models\LessonSession;
use App\Models\School;
use App\Models\SchoolUserMembership;
use App\Models\User;
use App\Modules\Tenancy\Access;
use App\Modules\Tenancy\CurrentSchool;

class ChannelAuth
{
    private static function member(User $u, int $schoolId): ?School
    {
        $school = School::where('id', $schoolId)->where('status', 'active')->first();
        $ok = $school && SchoolUserMembership::where('school_id', $schoolId)->where('user_id', $u->id)->where('status', 'active')->exists();

        return $ok ? $school : null;
    }

    public static function user(User $u, int $schoolId, int $userId): bool
    {
        return $u->id === $userId && self::member($u, $schoolId) !== null;
    }

    public static function session(User $u, int $schoolId, int $sessionId): bool
    {
        $school = self::member($u, $schoolId);

        return $school && app(CurrentSchool::class)->run($school, function () use ($u, $sessionId) {
            $s = LessonSession::find($sessionId);
            $access = app(Access::class);

            return $s && ($access->canViewSection($u, $s->section_id) || $access->can($u, 'sessions.monitor'));
        });
    }

    public static function conversation(User $u, int $schoolId, int $conversationId): bool
    {
        $school = self::member($u, $schoolId);

        return $school && app(CurrentSchool::class)->run($school, fn () => ConversationParticipant::where('conversation_id', $conversationId)->where('user_id', $u->id)->exists());
    }
}
