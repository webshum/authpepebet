<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Firebase\JWT\JWT;

class TelegramBotAuthController extends Controller {
    private $botToken;
    private const TOKEN_TTL    = 300;
    private const TICKET_TTL   = 60;
    private const CACHE_PREFIX = 'tg_bot_auth:';

    public function __construct()
    {
        $this->botToken = config('services.telegram.client_secret');
    }

    public function init(Request $request): JsonResponse
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

    public function poll(Request $request): JsonResponse
    {
        $token = $request->query('token');
        $cacheKey = self::CACHE_PREFIX . $token;
        $data = Cache::get(self::CACHE_PREFIX . $token);

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

    public function webhook(Request $request): JsonResponse
    {
        $update = $request->all();
        Log::debug('TG webhook', $update);

        if ($callbackQuery = $update['callback_query'] ?? null) {
            return $this->handleCallback($callbackQuery);
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $text   = $message['text'] ?? '';
        $tgUser = $message['from'] ?? [];
        $chatId = $message['chat']['id'] ?? null;

        if (!str_starts_with($text, '/start ')) {
            $this->sendMessage($chatId, 'Привет! Щелкните кнопку входа на сайте.');
            return response()->json(['ok' => true]);
        }

        $token = trim(substr($text, 7));
        $cacheKey = self::CACHE_PREFIX . $token;
        $data = Cache::get($cacheKey);

        if (!$data || now()->timestamp > $data['expires_at']) {
            $this->sendMessage($chatId, '❌ Ссылка устарела. Попробуйте снова на сайте.');
            return response()->json(['ok' => true]);
        }

        if ($data['status'] === 'ready') {
            $this->sendMessage($chatId, '✅ Вы уже вошли!');
            return response()->json(['ok' => true]);
        }

        $this->sendMessageWithButtons($chatId, $token);

        return response()->json(['ok' => true]);
    }

    private function sendMessage(int|string $chatId, string $text): void
    {
        Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text'    => $text,
        ]);
    }

    private function sendMessageWithButtons(int|string $chatId, string $token): void
    {
        Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
            'chat_id'      => $chatId,
            'text'         =>
                "⚠️ *Вход с нового устройства*\n\n" .
                "Нажимая «Подтвердить», вы подтверждаете вход в свой аккаунт.\n\n" .
                "Если вы не пытались войти, нажмите «Отменить».",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '✅ Подтвердить', 'callback_data' => 'confirm:' . $token],
                    ['text' => '❌ Отменить',  'callback_data' => 'cancel:'  . $token],
                ]],
            ]),
        ]);
    }

    private function handleCallback(array $callbackQuery): JsonResponse
    {
        $chatId     = $callbackQuery['message']['chat']['id'];
        $messageId  = $callbackQuery['message']['message_id'];
        $callbackId = $callbackQuery['id'];
        $data       = $callbackQuery['data'] ?? '';

        [$action, $token] = explode(':', $data, 2) + ['', ''];

        $cacheKey  = self::CACHE_PREFIX . $token;
        $cacheData = Cache::get($cacheKey);

        Http::post("https://api.telegram.org/bot{$this->botToken}/editMessageReplyMarkup", [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => []]),
        ]);

        if ($action === 'confirm') {
            if (!$cacheData || now()->timestamp > $cacheData['expires_at']) {
                Http::post("https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery", [
                    'callback_query_id' => $callbackId,
                    'text'              => '❌ Ссылка устарела.',
                    'show_alert'        => true,
                ]);
                return response()->json(['ok' => true]);
            }

            $tgUser = $callbackQuery['from'];
            $ticket = Str::random(64);

            Cache::put('tg_ticket:' . $ticket, $tgUser, self::TICKET_TTL);

            $cacheData['status'] = 'ready';
            $cacheData['ticket'] = $ticket;
            Cache::put($cacheKey, $cacheData, $cacheData['expires_at'] - now()->timestamp);

            Http::post("https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery", [
                'callback_query_id' => $callbackId,
            ]);

            $this->sendMessage($chatId, '✅ Авторизация подтверждена! Возвращайся на сайт.');

        } elseif ($action === 'cancel') {
            if ($cacheData) {
                Cache::forget($cacheKey);
            }

            Http::post("https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery", [
                'callback_query_id' => $callbackId,
                'text'              => '❌ Авторизация отменена.',
                'show_alert'        => true,
            ]);

            $this->sendMessage($chatId, '❌ Авторизация отменена.');
        }

        return response()->json(['ok' => true]);
    }
}
