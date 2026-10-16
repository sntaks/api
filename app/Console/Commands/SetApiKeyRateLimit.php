<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SetApiKeyRateLimit extends Command
{
    /**
     * @var string
     */
    protected $signature = 'apikey:rate-limit {key : The API key\'s id or name} {multiplier : 1 to 20; every per-minute limit is multiplied by it}';

    /**
     * @var string
     */
    protected $description = 'Give a server-side API key (such as the GMS) a bigger rate-limit budget';

    public function handle(): int
    {
        $multiplier = (int) $this->argument('multiplier');

        if ($multiplier < 1 || $multiplier > 20) {
            $this->error('The multiplier must be between 1 and 20.');

            return self::FAILURE;
        }

        $identifier = (string) $this->argument('key');
        $keys = ApiKey::where('id', ctype_digit($identifier) ? (int) $identifier : 0)->orWhere('name', $identifier)->get();

        if ($keys->count() !== 1) {
            $this->error($keys->isEmpty() ? 'No API key has that id or name.' : 'More than one key has that name; use its id.');

            return self::FAILURE;
        }

        $key = $keys->first();
        $key->update(['rate_limit_multiplier' => $multiplier]);
        Cache::forget(ApiKey::multiplierCacheKey($key->key));

        $this->info("API key #{$key->id} ({$key->name}) now gets {$multiplier}x the standard rate limits.");

        return self::SUCCESS;
    }
}
