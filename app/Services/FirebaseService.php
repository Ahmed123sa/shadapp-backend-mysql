<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    private ?array $serviceAccount = null;

    private function loadServiceAccount(): array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }

        $path = config('services.fcm.service_account_path');
        if (!$path || !file_exists($path)) {
            throw new \RuntimeException('Firebase service account file not found at: ' . ($path ?? 'null'));
        }

        $this->serviceAccount = json_decode(file_get_contents($path), true);
        return $this->serviceAccount;
    }

    public function getAccessToken(): string
    {
        return Cache::remember('firebase_access_token', 3300, function () {
            $account = $this->loadServiceAccount();

            $now = time();
            $header = ['alg' => 'RS256', 'typ' => 'JWT'];
            $payload = [
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $account['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $jwt = $this->encodeJWT($header, $payload, $account['private_key']);

            $response = Http::asForm()->post($account['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (!$response->successful()) {
                Log::error('Firebase token exchange failed: ' . $response->body());
                throw new \RuntimeException('Failed to get Firebase access token');
            }

            return $response->json('access_token');
        });
    }

    public function sendMessage(string $token, array $notification, array $data = []): bool|string
    {
        $accessToken = $this->getAccessToken();
        $account = $this->loadServiceAccount();

        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $notification['title'] ?? '',
                    'body' => $notification['body'] ?? '',
                ],
            ],
        ];

        if (!empty($data)) {
            $message['message']['data'] = [];
            foreach ($data as $key => $value) {
                $message['message']['data'][$key] = (string) $value;
            }
        }

        $response = Http::withToken($accessToken)->post(
            "https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send",
            $message
        );

        if ($response->successful()) {
            return true;
        }

        $responseBody = $response->json();
        $isUnregistered = ($response->status() === 404 && ($responseBody['error']['status'] ?? '') === 'UNREGISTERED')
            || ($response->status() === 400 && str_contains($response->body(), 'UNREGISTERED'));

        return $isUnregistered ? 'unregistered' : false;
    }

    private function encodeJWT(array $header, array $payload, string $privateKey): string
    {
        $segments = [];
        $segments[] = $this->base64UrlEncode(json_encode($header));
        $segments[] = $this->base64UrlEncode(json_encode($payload));

        $signInput = implode('.', $segments);
        $signature = '';
        $privateKeyResource = openssl_get_privatekey($privateKey);

        if (!$privateKeyResource) {
            throw new \RuntimeException('Failed to parse private key');
        }

        openssl_sign($signInput, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKeyResource);

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function sendToUser(int $userId, string $userType, array $notification, array $data = []): void
    {
        $deviceTokens = \App\Models\MobileNotificationToken::where('tokenable_id', $userId)
            ->where('tokenable_type', $userType)
            ->get();

        foreach ($deviceTokens as $deviceToken) {
            try {
                $result = $this->sendMessage($deviceToken->token, $notification, $data);
                if ($result === 'unregistered') {
                    $deviceToken->delete();
                    Log::info('Removed unregistered FCM token for user ' . $userId);
                }
            } catch (\Exception $e) {
                Log::warning('FCM send to token failed: ' . $e->getMessage());
            }
        }
    }
}
