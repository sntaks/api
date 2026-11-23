<?php

namespace App\Http\Controllers;

use App\Domain\Jet\JetContext;
use App\Domain\Jet\SessionCodeService;
use App\Exceptions\InvalidLaunchToken;
use App\Exceptions\LaunchRefused;
use App\Services\LaunchTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /jet/launch (https://jet.kadi.online/launch through the proxy): the Kadi launcher posts the launch token in
 * its iframe. The token is redeemed once, in-process, never retried. On success the page holds only a one-time
 * code (never the player token) and hands it to the client through sessionStorage; on failure it tells the
 * parent window and offers a way back.
 */
class JetLaunchController extends Controller
{
    public function __invoke(Request $request, LaunchTokenService $launchTokens, SessionCodeService $codes, JetContext $context): Response
    {
        $token = $request->input('token');

        if (! is_string($token) || $token === '' || strlen($token) > 100) {
            return $this->failed('invalid_launch');
        }

        try {
            $session = $launchTokens->redeem($token, $context->wagerKey());
        } catch (InvalidLaunchToken) {
            return $this->failed('invalid_launch');
        } catch (LaunchRefused $e) {
            return $this->failed($e->errorCode);
        }

        $code = $codes->issue($session['customer'], $session['player_token'], $session['expires_at']);

        return $this->page(response()->view('jet.launch', ['code' => $code]));
    }

    private function failed(string $code): Response
    {
        return $this->page(response()->view('jet.launch-error', ['code' => $code], 403));
    }

    private function page(Response $response): Response
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
