<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateGame extends Command
{
    /**
     * @var string
     */
    protected $signature = 'game:create {code : Lower-case code, e.g. crash} {name : Name shown in the lobby} {launch_url : Where kadi sends a launched player}';

    /**
     * @var string
     */
    protected $description = 'Register a game on the platform (an integration with its own launch URL and keys)';

    public function handle(): int
    {
        $validator = Validator::make([
            'code' => $this->argument('code'),
            'name' => $this->argument('name'),
            'launch_url' => $this->argument('launch_url'),
        ], [
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:games,code'],
            'name' => ['required', 'string', 'max:255'],
            'launch_url' => ['required', 'url', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $game = Game::create([
            'code' => $this->argument('code'),
            'name' => $this->argument('name'),
            'launch_url' => $this->argument('launch_url'),
            'status' => Game::STATUS_ACTIVE,
            'sort_order' => (int) Game::max('sort_order') + 1,
        ]);

        $this->info("Game {$game->code} created (#{$game->id}). Add its products with product:create.");

        return self::SUCCESS;
    }
}
