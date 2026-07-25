<?php

namespace Tests\Feature;

use App\Services\Providers\ChaturbateProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChaturbateProviderTest extends TestCase
{
    private function fakeRoom(array $overrides = []): array
    {
        return array_merge([
            'username' => 'foxfilms',
            'gender' => 'f',
            'age' => 24,
            'tags' => ['feet', 'blonde'],
            'current_show' => 'public',
            'num_users' => 1200,
            'image_url_360x270' => 'https://thumb.example.com/foxfilms.jpg',
            'chat_room_url_revshare' => 'https://chaturbate.com/in/?tour=LQps&campaign=Vg4Qi&track=default&room=foxfilms',
            'iframe_embed_revshare' => "<iframe src='https://chaturbate.com/in/?tour=9oGW&amp;campaign=Vg4Qi&amp;track=embed&amp;room=foxfilms&amp;bgcolor=white' height=528 width=850 style='border: none;'></iframe>",
        ], $overrides);
    }

    private function fakeApi(array $rooms): void
    {
        config([
            'cam-providers.chaturbate.affiliate_id' => 'Vg4Qi',
            'cam-providers.chaturbate.campaign' => 'default',
        ]);

        Http::fake([
            'chaturbate.com/api/public/affiliates/onlinerooms/*' => Http::response([
                'count' => count($rooms),
                'results' => $rooms,
            ]),
        ]);
    }

    public function test_it_extracts_a_playable_embed_url_and_forces_silent_playback(): void
    {
        $this->fakeApi([$this->fakeRoom()]);

        $cams = app(ChaturbateProvider::class)->fetchCams();

        $this->assertCount(1, $cams);
        $embedUrl = $cams[0]['embed_url'];

        $this->assertNotNull($embedUrl);
        $this->assertStringStartsWith('https://chaturbate.com/in/', $embedUrl);
        $this->assertStringContainsString('room=foxfilms', $embedUrl);
        $this->assertStringContainsString('disable_sound=1', $embedUrl);
    }

    public function test_it_returns_a_null_embed_url_when_the_api_provides_no_iframe_fields(): void
    {
        $this->fakeApi([$this->fakeRoom([
            'iframe_embed_revshare' => null,
            'iframe_embed' => null,
        ])]);

        $cams = app(ChaturbateProvider::class)->fetchCams();

        $this->assertNull($cams[0]['embed_url']);
    }

    public function test_it_falls_back_to_the_non_revshare_iframe_field(): void
    {
        $this->fakeApi([$this->fakeRoom([
            'iframe_embed_revshare' => null,
            'iframe_embed' => "<iframe src='https://chaturbate.com/in/?tour=Jrvi&amp;campaign=Vg4Qi&amp;track=embed&amp;room=foxfilms&amp;bgcolor=white'></iframe>",
        ])]);

        $cams = app(ChaturbateProvider::class)->fetchCams();

        $this->assertStringContainsString('room=foxfilms', $cams[0]['embed_url']);
        $this->assertStringContainsString('disable_sound=1', $cams[0]['embed_url']);
    }
}
