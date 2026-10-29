<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\Game;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class GenerateApiKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'apiKey:generate {name}
        {--scope=full : full (GMS, the kadi game server), site (the kadi site) or game (a game server)}
        {--game= : The game code a game key is bound to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new full, site or game API key (shop keys are issued through the bet shop routes)';

    public function handle(ApiKeyService $keys): int
    {
        $scope = (string) $this->option('scope');
        $gameCode = $this->option('game');

        if (! in_array($scope, [ApiKey::SCOPE_FULL, ApiKey::SCOPE_SITE, ApiKey::SCOPE_GAME], true)) {
            $this->error('The scope must be full, site or game. Shop keys are issued with POST /bet-shops/{id}/keys.');

            return self::FAILURE;
        }

        if ($scope !== ApiKey::SCOPE_GAME && $gameCode !== null) {
            $this->error('Only a game key is bound to a game.');

            return self::FAILURE;
        }

        $game = null;

        if ($scope === ApiKey::SCOPE_GAME) {
            $game = $gameCode === null ? null : Game::where('code', $gameCode)->first();

            if (! $game) {
                $this->error('A game key needs --game set to an existing game code.');

                return self::FAILURE;
            }
        }

        ['api_key' => $apiKey, 'plain_key' => $plainKey] = $keys->issue((string) $this->argument('name'), $scope, $game);

        $this->info("API key generated: {$plainKey}");
        $this->line("Key #{$apiKey->id}, scope {$scope}".($game ? ", game {$game->code}" : '').'. It is not shown again.');

        return self::SUCCESS;
    }
}
