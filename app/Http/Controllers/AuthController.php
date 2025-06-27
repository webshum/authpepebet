<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthController extends Controller
{
    public function redirect(Request $request) {
        $handlerUrl = $request->input('handler_url');
        $provider = $request->input('provider');

        $state = base64_encode(json_encode([
            'handler_url' => $handlerUrl,
            'provider' => $provider,
        ]));

        return Socialite::driver($provider)
            ->with(['state' => $state])
            ->redirect();
    }

    public function callback(Request $request) {
        $stateRaw = $request->input('state');
        $state = json_decode(base64_decode($stateRaw), true);
        $handlerUrl = $state['handler_url'];
        $provider = $state['provider'];

        $user = Socialite::driver($provider)->stateless()->user();

        $data = [
            'email' => $user->getEmail(),
            'id' => $user->getId(),
            'name' => $user->getName(),
            'avatar' => $user->getAvatar(),
            'provider' => $provider,
        ];

        $jwt = JWT::encode($data, env('JWT_SECRET'), 'HS256');

        return redirect("{$handlerUrl}/{$provider}/callback?token={$jwt}");
    }
}
