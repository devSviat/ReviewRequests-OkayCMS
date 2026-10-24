<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

/**
 * Токен посилання з листа. У базі — лише SHA-256 від нього: витік таблиці не
 * дає робочих посилань. Config::salt тут не годиться: він рахується зі stat()
 * конфіга й міняється з кожним деплоєм.
 */
final class ReviewRequestToken
{
    /** 32 байти в base64url без доповнення. */
    public const string PATTERN = '[A-Za-z0-9_-]{43}';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed($token): bool
    {
        return is_string($token) && (bool) preg_match('/^' . self::PATTERN . '$/', $token);
    }
}
