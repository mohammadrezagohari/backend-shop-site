<?php

namespace App\Modules\Marketplaces\Application;

use Illuminate\Http\Request;
use Throwable;

final class TorobToken
{
    public function validate(Request $request): bool
    {
        try {
            if ($request->header('X-Torob-Token-Version') !== '1' || ! function_exists('sodium_crypto_sign_verify_detached')) { return false; }
            $token = $request->header('X-Torob-Token', '');
            if (strlen($token) > 8192 || count($parts = explode('.', $token)) !== 3) { return false; }
            $decode = function (string $value): string {
                if (! preg_match('/^[A-Za-z0-9_-]+$/', $value)) { throw new \RuntimeException; }
                $decoded = base64_decode(strtr($value, '-_', '+/'), true);
                if ($decoded === false) { throw new \RuntimeException; }
                return $decoded;
            };
            $header = json_decode($decode($parts[0]), true, flags: JSON_THROW_ON_ERROR);
            $claims = json_decode($decode($parts[1]), true, flags: JSON_THROW_ON_ERROR);
            if (($header['alg'] ?? '') !== 'EdDSA' || isset($header['crit'])) { return false; }
            $key = base64_decode(config('marketplaces.torob.public_key'), true);
            $signature = $decode($parts[2]);
            if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || ! sodium_crypto_sign_verify_detached($signature, $parts[0].'.'.$parts[1], $key)) { return false; }
            $url = parse_url(config('app.url'));
            $audience = ($url['host'] ?? '').(isset($url['port']) ? ':'.$url['port'] : '');

            return $audience !== '' && ($claims['aud'] ?? null) === $audience
                && is_int($claims['exp'] ?? null) && $claims['exp'] > time()
                && is_int($claims['nbf'] ?? null) && $claims['nbf'] <= time();
        } catch (Throwable) { return false; }
    }
}
