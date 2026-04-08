<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Firebase\JWT\JWT;

class TelegramBotAuthController extends Controller {
    private const TOKEN_TTL    = 300;
    private const TICKET_TTL   = 60;
    private const CACHE_PREFIX = 'tg_bot_auth:';

    public function init(Request $request)
    {
        $token = Str::random(48);
        $deepLink = 'https://t.me/' . config('services.telegram.bot') . '?start=' . $token;

        Cache::put(self::CACHE_PREFIX . $token, [
            'status'    => 'pending',
            'tg_user'   => null,
            'ticket'    => null,
            'expires_at' => now()->addSeconds(self::TOKEN_TTL)->timestamp,
        ], self::TOKEN_TTL);

        return response()->json([
            'token'     => $token,
            'deep_link' => $deepLink,
        ]);
    }

    public function poll(Request $request)
    {
        $token = $request->query('token');
        $data  = Cache::get(self::CACHE_PREFIX . $token);

        if (!$data) {
            return response()->json(['status' => 'expired']);
        }

        if (now()->timestamp > $data['expires_at']) {
            Cache::forget(self::CACHE_PREFIX . $token);
            return response()->json(['status' => 'expired']);
        }

        if ($data['status'] === 'ready' && $data['ticket']) {
            return response()->json([
                'status' => 'ready',
                'ticket' => $data['ticket'],
            ]);
        }

        return response()->json(['status' => 'pending']);
    }

    public function session(Request $request)
    {
        $ticket = $request->query('t');
        $cacheKey = 'tg_ticket:' . $ticket;
        $tgUser = Cache::get($cacheKey);

        if (!$tgUser) {
            return redirect(config('services.telegram.redirect'))->with('error', 'Telegram session expired.');
        }

        Cache::forget($cacheKey);

        $now = time();
        $expiresAt = $now + 600;

        $jwtData = [
            'email' => $tgUser['email'] ?? null,
            'id' => $tgUser['id'],
            'name' => trim(($tgUser['first_name'] ?? '') . ' ' . ($tgUser['last_name'] ?? '')),
            'avatar' => $tgUser['photo_url'] ?? null,
            'provider' => 'telegram_bot',
            'iat' => $now,
            'exp' => $expiresAt,
        ];

        $jwt = JWT::encode($jwtData, config('jwt.secret'), 'HS256');
        $handlerUrl = config('services.telegram.redirect');

        return redirect("{$handlerUrl}?token={$jwt}");
    }

    public function webhook(Request $request)
    {
        $update = $request->all();
        Log::debug('TG webhook', $update);

        $message = $update['message'] ?? null;
        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $text   = $message['text'] ?? '';
        $tgUser = $message['from'] ?? [];
        $chatId = $message['chat']['id'] ?? null;

        if (!str_starts_with($text, '/start ')) {
            $this->sendMessage($chatId, 'Привет! Нажмите кнопку входа на сайте.');
            return response()->json(['ok' => true]);
        }

        $token = trim(substr($text, 7));
        $cacheKey = self::CACHE_PREFIX . $token;
        $data = Cache::get($cacheKey);

        if (!$data || now()->timestamp > $data['expires_at']) {
            $this->sendMessage($chatId, '❌ Ссылка устарела. Попробуй снова на сайте.');
            return response()->json(['ok' => true]);
        }

        if ($data['status'] === 'ready') {
            $this->sendMessage($chatId, '✅ Ты уже вошел!');
            return response()->json(['ok' => true]);
        }

        $ticket = Str::random(64);

        Cache::put('tg_ticket:' . $ticket, $tgUser, self::TICKET_TTL);

        $data['status'] = 'ready';
        $data['ticket'] = $ticket;
        Cache::put($cacheKey, $data, $data['expires_at'] - now()->timestamp);

        $this->sendMessage($chatId, '✅ Авторизация успешна! Возвращайся на сайт.');

        return response()->json(['ok' => true]);
    }

    private function sendMessage(int|string $chatId, string $text): void
    {
        $botToken = config('telegram.bot_token');
        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text'    => $text,
        ]);
    }
}
