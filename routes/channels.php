<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Broadcast;

/*
 * Kadi Jet: the public jet.rounds channel needs no auth. A player's private stream is theirs alone; the user is
 * the customer behind the game:jet bearer (AuthenticateJetBroadcast), never a web session.
 */
Broadcast::channel('jet.player.{customerId}', fn (Customer $customer, string $customerId) => ctype_digit($customerId) && (int) $customerId === $customer->id);
