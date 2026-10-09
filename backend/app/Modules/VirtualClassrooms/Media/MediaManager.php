<?php

namespace App\Modules\VirtualClassrooms\Media;

class MediaManager
{
    public static function make(): MediaProvider
    {
        return match (config('media.provider')) {
            'livekit' => new LiveKitProvider(config('media.livekit'), config('media.turn')),
            default => new NullMediaProvider,
        };
    }
}
