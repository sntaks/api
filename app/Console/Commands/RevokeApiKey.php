<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class RevokeApiKey extends Command
{
    /**
     * @var string
     */
    protected $signature = 'apikey:revoke {key : The API key\'s id or name}';

    /**
     * @var string
     */
    protected $description = 'Stop an API key of any scope working';

    public function handle(ApiKeyService $keys): int
    {
        $identifier = (string) $this->argument('key');
        $matches = ApiKey::where('id', ctype_digit($identifier) ? (int) $identifier : 0)->orWhere('name', $identifier)->get();

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty() ? 'No API key has that id or name.' : 'More than one key has that name; use its id.');

            return self::FAILURE;
        }

        $apiKey = $keys->revoke($matches->first(), 'console');

        $this->info("API key #{$apiKey->id} ({$apiKey->name}, scope {$apiKey->scope}) is revoked.");

        return self::SUCCESS;
    }
}
