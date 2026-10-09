<?php

namespace App\Modules\Scheduling\Http;

use App\Http\Controllers\Controller;
use App\Models\OutboxNotification;
use App\Modules\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Incremental sync: after reconnecting, the client passes the last id it has seen. */
    public function index(Request $request, NotificationService $svc): JsonResponse
    {
        $items = $svc->forUser($request->user()->id, $request->integer('since_id') ?: null);

        return response()->json(['data' => $items, 'server_time' => now()->toIso8601String()]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        OutboxNotification::where('user_id', $request->user()->id)->whereKey($id)->update(['read_at' => now()]);

        return response()->json(null, 204);
    }
}
