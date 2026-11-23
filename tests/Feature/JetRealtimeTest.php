<?php

use App\Domain\Jet\EventPublisher;
use App\Events\JetV1Event;
use App\Models\Customer;
use App\Models\Game;
use App\Services\PlayerTokenService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    setUpJet($this);
    // Channel auth signs locally with the app secret; nothing is sent to a Reverb server
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb', 'key' => 'jet-key', 'secret' => 'jet-secret', 'app_id' => '1',
        'options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
    ]]);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');
    $this->player = Customer::factory()->create(['status' => 1]);
});

function jetChannelAuth(object $test, string $channel, string $token): TestResponse
{
    return $test->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel], ['Authorization' => "Bearer {$token}"]);
}

it('authorises a player only for their own private channel', function () {
    $token = app(PlayerTokenService::class)->issue($this->player, Game::where('code', 'jet')->sole())->plainTextToken;
    $other = Customer::factory()->create(['status' => 1]);

    jetChannelAuth($this, "private-jet.player.{$this->player->id}", $token)->assertOk()->assertJsonStructure(['auth']);
    jetChannelAuth($this, "private-jet.player.{$other->id}", $token)->assertForbidden();
});

it('refuses channel auth for a token of another game or none', function () {
    $slots = Game::factory()->create(['code' => 'slots']);
    $token = app(PlayerTokenService::class)->issue($this->player, $slots)->plainTextToken;

    jetChannelAuth($this, "private-jet.player.{$this->player->id}", $token)->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-jet.player.{$this->player->id}"])->assertUnauthorized();
});

it('broadcasts a player invalidation as jet.v1 on that player\'s private channel', function () {
    Event::fake([JetV1Event::class]);
    EventPublisher::changed($this->player->id);

    app(EventPublisher::class)->flush();

    Event::assertDispatched(JetV1Event::class, function (JetV1Event $event) {
        $channel = $event->broadcastOn()[0];

        return $channel instanceof PrivateChannel && $channel->name === "private-jet.player.{$this->player->id}"
            && $event->broadcastAs() === 'jet.v1' && $event->broadcastWith()['type'] === 'snapshot.invalidated';
    });
});
