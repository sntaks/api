<?php

namespace App\Console\Commands;

use App\Domain\Jet\SessionCodeService;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Game;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\ApiKeyService;
use App\Services\PlayerTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class JetLocal extends Command
{
    /**
     * @var string
     */
    protected $signature = 'jet:local
        {--player= : An existing customer id to play as (default: a local test player, created once)}
        {--balance=10000 : Top the player\'s wallet up to at least this many KES}';

    /**
     * @var string
     */
    protected $description = 'LOCAL ONLY: set up Kadi Jet on this database and print a player token and a one-time session code for the Godot client';

    public function handle(ApiKeyService $keys, PlayerTokenService $playerTokens, SessionCodeService $codes): int
    {
        if (! app()->environment('local')) {
            $this->error('jet:local only runs with APP_ENV=local.');

            return self::FAILURE;
        }

        $game = Game::firstOrCreate(['code' => config('jet.game_code')], [
            'name' => 'Kadi Jet',
            'launch_url' => rtrim((string) config('app.url'), '/').'/jet/launch',
            'status' => Game::STATUS_ACTIVE,
        ]);

        if (! ApiKey::where('game_id', $game->id)->where('scope', ApiKey::SCOPE_GAME)->where('is_active', true)->exists()) {
            $keys->issue('jet-engine-local', ApiKey::SCOPE_GAME, $game);
        }

        $product = Product::firstOrCreate(['code' => config('jet.product_code')], [
            'game_id' => $game->id,
            'name' => 'Kadi Jet Classic',
            'revenue_model' => Product::REVENUE_HOUSE_BANKED,
            'status' => Product::STATUS_ACTIVE,
            'min_stake' => 10,
            'max_stake' => 2000,
            'max_payout' => 1000000,
            'winnings_tax_rate' => 0.2,
        ]);

        if (! Wallet::whereKey(config('wallets.house_wallet_id', 1))->exists()) {
            $this->warn('No house wallet #'.config('wallets.house_wallet_id', 1).': winning bets will fail to settle until it exists.');
        }

        $player = $this->option('player')
            ? Customer::findOrFail((int) $this->option('player'))
            : Customer::firstOrCreate(['email' => 'jet-local@example.test'], [
                'name' => 'Jet Tester',
                'phone_no' => $this->unused('phone_no', fn () => '2547'.random_int(10000000, 99999999)),
                'id_no' => $this->unused('id_no', fn () => (string) random_int(10000000, 99999999)),
                'status' => 1,
                'email_verified_at' => now(),
            ]);

        $wallet = Wallet::firstOrCreate(['customer_id' => $player->id], ['balance' => 0]);
        $balance = (float) $this->option('balance');

        if ((float) $wallet->balance < $balance) {
            // Local test money only: no ledger entry behind it
            $wallet->forceFill(['balance' => $balance])->save();
        }

        $token = $playerTokens->issue($player, $game);
        $expiresAt = CarbonImmutable::parse($token->accessToken->expires_at);
        $code = $codes->issue($player, $token->plainTextToken, $expiresAt);

        $this->info("Kadi Jet is set up: game {$game->code} ({$game->status}), product {$product->code} ({$product->status}).");
        $this->line("Player #{$player->id} ({$player->displayName()}), balance KES ".number_format((float) $wallet->fresh()->balance, 2));
        $this->newLine();
        $this->line('Player token (Authorization: Bearer, game:jet, until '.$expiresAt->toDateTimeString().'):');
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->line('One-time session code (POST /api/v1/jet/session {"code": …}, single use, '.config('jet.session_code_ttl_seconds').' s):');
        $this->line($code);

        return self::SUCCESS;
    }

    /**
     * A value for a unique customer column that no customer has yet.
     *
     * @param  callable(): string  $candidate
     */
    private function unused(string $column, callable $candidate): string
    {
        do {
            $value = $candidate();
        } while (Customer::withTrashed()->where($column, $value)->exists());

        return $value;
    }
}
