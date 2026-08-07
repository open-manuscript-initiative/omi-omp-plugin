<?php
namespace APP\plugins\generic\studioIntegration\classes\Core;

final class LaunchToken
{
    public static function issue(array $claims, string $secret): array
    {
        $json = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode launch claims.');
        }
        $payload = Base64Url::encode($json);
        $signature = Base64Url::encode(hash_hmac('sha256', $payload, $secret, true));
        return ['payload' => $payload, 'signature' => $signature];
    }

    public static function verify(string $payload, string $signature, string $secret, int $contextId): ?array
    {
        $expected = Base64Url::encode(hash_hmac('sha256', $payload, $secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $json = Base64Url::decode($payload);
        $claims = $json === null ? null : json_decode($json, true);
        if (!is_array($claims) || ($claims['protocol'] ?? null) !== 'omi-integration/1') {
            return null;
        }
        if ((int)($claims['context']['externalId'] ?? 0) !== $contextId) {
            return null;
        }
        $now = time();
        $iat = (int)($claims['iat'] ?? 0);
        $exp = (int)($claims['exp'] ?? 0);
        if ($iat < 1 || $exp < 1 || $iat > $now + 60 || $exp < $now || ($exp - $iat) > 3600) {
            return null;
        }
        return $claims;
    }
}
