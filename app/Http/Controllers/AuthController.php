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

        session([
            'handlerUrl' => $handlerUrl,
            'provider' => $provider,
        ]);

        return Socialite::driver($provider)->redirect();
    }

    public function callback() {
        $handlerUrl = session('handlerUrl');
        $provider = session('provider');
        $now = time();
        $expiresAt = $now + 600;

        if (!$handlerUrl || !$provider) {
            abort(400, 'Missing session data');
        }
       
        $user = Socialite::driver($provider)->stateless()->user();

        $data = [
            'email' => $user->getEmail(),
            'id' => $user->getId(),
            'name' => $user->getName(),
            'avatar' => $user->getAvatar(),
            'provider' => $provider,
            'iat' => $now,
            'exp' => $expiresAt,
        ];

        $jwt = JWT::encode($data, config('jwt.secret'), 'HS256');

        session()->forget(['handlerUrl', 'provider']);

        return redirect("{$handlerUrl}/{$provider}/callback?token={$jwt}");
    }
}
