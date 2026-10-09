<?php

namespace Tests\Feature;

use App\Modules\VirtualClassrooms\Media\LiveKitProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveKitEgressAdapterTest extends TestCase
{
    private function provider(array $egress = ['bucket' => 'rec', 'access_key' => 'ak', 'secret' => 'sk', 'region' => 'us-east-1', 'endpoint' => 'https://s3.test', 'path_style' => true]): LiveKitProvider
    {
        return new LiveKitProvider(['url' => 'wss://sfu.test', 'api_url' => 'https://sfu.test', 'key' => 'devkey', 'secret' => str_repeat('s', 32), 'egress' => $egress]);
    }

    public function test_recording_needs_egress_bucket_and_credentials(): void
    {
        $this->assertTrue($this->provider()->recordingConfigured());
        $this->assertFalse($this->provider(['bucket' => 'rec'])->recordingConfigured());
        $this->assertFalse($this->provider([])->recordingConfigured());
    }

    public function test_start_recording_posts_a_room_composite_egress_with_s3_output(): void
    {
        Http::fake(['https://sfu.test/twirp/livekit.Egress/StartRoomCompositeEgress' => Http::response(['egress_id' => 'EG_1'])]);
        $this->assertSame('EG_1', $this->provider()->startRecording('room-1', 'schools/1/recordings/x.mp4'));
        Http::assertSent(function ($r) {
            $b = $r->data();

            return str_contains($r->url(), 'livekit.Egress/StartRoomCompositeEgress') && $b['room_name'] === 'room-1'
                && $b['file_outputs'][0]['filepath'] === 'schools/1/recordings/x.mp4' && $b['file_outputs'][0]['s3']['bucket'] === 'rec'
                && $b['file_outputs'][0]['s3']['force_path_style'] === true && str_starts_with($r->header('Authorization')[0], 'Bearer ');
        });
    }

    public function test_stopping_an_already_finished_recording_is_not_an_error(): void
    {
        Http::fake(['*' => Http::response(['msg' => 'egress with status EGRESS_COMPLETE cannot be stopped'], 412)]);
        $this->provider()->stopRecording('EG_1');
        $this->assertTrue(true);
    }
}
