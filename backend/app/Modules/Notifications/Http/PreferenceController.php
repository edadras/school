<?php

namespace App\Modules\Notifications\Http;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => NotificationPreference::where('user_id', $request->user()->id)->get(['type', 'channel', 'enabled']),
            'defaults' => ['realtime' => true, 'push' => true, 'email' => config('notifications.email_types')]]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate(['preferences' => ['required', 'array', 'max:100'], 'preferences.*.type' => ['required', 'string', 'max:40'],
            'preferences.*.channel' => ['required', Rule::in(['realtime', 'push', 'email'])], 'preferences.*.enabled' => ['required', 'boolean']]);
        foreach ($d['preferences'] as $p) {
            NotificationPreference::updateOrCreate(['user_id' => $request->user()->id, 'type' => $p['type'], 'channel' => $p['channel']], ['enabled' => $p['enabled']]);
        }

        return $this->index($request);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $d = $request->validate(['platform' => ['required', Rule::in(['android', 'ios', 'web'])], 'token' => ['required', 'string', 'max:512']]);
        // A device token belongs to exactly one user: re-registering on a shared device moves it.
        DeviceToken::where('token', $d['token'])->where('user_id', '!=', $request->user()->id)->delete();
        $t = DeviceToken::updateOrCreate(['user_id' => $request->user()->id, 'token' => $d['token']], ['platform' => $d['platform'], 'last_seen_at' => now()]);

        return response()->json(['data' => $t->only(['id', 'platform'])], 201);
    }

    public function removeDevice(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string']]);
        DeviceToken::where('user_id', $request->user()->id)->where('token', $d['token'])->delete();

        return response()->json(null, 204);
    }
}
