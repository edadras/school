<?php

use App\Modules\Realtime\ChannelAuth;
use Illuminate\Support\Facades\Broadcast;

// Every channel is school-scoped and re-verified server-side against memberships; a user id in the
// channel name proves nothing by itself.
Broadcast::channel('school.{schoolId}.user.{userId}', fn ($user, $schoolId, $userId) => ChannelAuth::user($user, (int) $schoolId, (int) $userId));
Broadcast::channel('school.{schoolId}.session.{sessionId}', fn ($user, $schoolId, $sessionId) => ChannelAuth::session($user, (int) $schoolId, (int) $sessionId));
Broadcast::channel('school.{schoolId}.conversation.{conversationId}', fn ($user, $schoolId, $cid) => ChannelAuth::conversation($user, (int) $schoolId, (int) $cid));
