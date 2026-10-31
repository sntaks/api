<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateProduct extends Command
{
    /**
     * @var string
     */
    protected $signature = 'product:create {code : Lower-case code, e.g. sportsbook_jackpot} {game : The game code it belongs to} {name : Name shown in finance reports}
        {--model=house_banked : rake or house_banked}
        {--min-stake= : Smallest cash stake allowed, in KES (a scratch card sets min and max to its price)}
        {--max-stake= : Largest stake allowed, in KES}
        {--max-payout= : Largest payout allowed, in KES}';

    /**
     * @var string
     */
    protected $description = 'Register a product (a revenue line finance reports on) under a game';

    public function handle(): int
    {
        $input = [
            'code' => $this->argument('code'),
            'game' => $this->argument('game'),
            'name' => $this->argument('name'),
            'model' => $this->option('model'),
            'min_stake' => $this->option('min-stake'),
            'max_stake' => $this->option('max-stake'),
            'max_payout' => $this->option('max-payout'),
        ];

        $validator = Validator::make($input, [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:products,code'],
            'game' => ['required', 'string', 'exists:games,code'],
            'name' => ['required', 'string', 'max:255'],
            'model' => ['required', 'in:'.implode(',', Product::REVENUE_MODELS)],
            'min_stake' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', ...($input['max_stake'] === null ? [] : ['lte:max_stake'])],
            'max_stake' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'max_payout' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $product = Product::create([
            'code' => $input['code'],
            'game_id' => Game::idFor($input['game']),
            'name' => $input['name'],
            'revenue_model' => $input['model'],
            'status' => Product::STATUS_ACTIVE,
            'min_stake' => $input['min_stake'],
            'max_stake' => $input['max_stake'],
            'max_payout' => $input['max_payout'],
        ]);

        $this->info("Product {$product->code} created (#{$product->id}) under game {$input['game']}.");

        return self::SUCCESS;
    }
}
