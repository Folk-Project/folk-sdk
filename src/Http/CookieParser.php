<?php declare(strict_types=1);

namespace Folk\Sdk\Http;

/**
 * Parses an HTTP `Cookie` request header into a name→value map.
 *
 * Folk delivers request headers to the worker, but the framework request
 * builders (`SymfonyRequest::create()`, PSR-7 `ServerRequestFactory`) do NOT
 * derive their cookie bag from the `Cookie` header — they only read an explicit
 * cookies array / `withCookieParams()`. Without feeding them the parsed cookies,
 * anything cookie-based (sessions, auth, CSRF) silently breaks on a persistent
 * worker (folk-releases #86). Adapters use this to bridge that gap.
 */
final class CookieParser
{
    /**
     * @param array<string, string> $headers Request headers (any casing).
     * @return array<string, string> Decoded cookie name→value pairs.
     */
    public static function fromHeaders(array $headers): array
    {
        $header = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'cookie') === 0) {
                $header = $value;
                break;
            }
        }

        return self::parse($header);
    }

    /**
     * @return array<string, string> Decoded cookie name→value pairs.
     */
    public static function parse(string $header): array
    {
        if ($header === '') {
            return [];
        }

        $cookies = [];
        foreach (explode(';', $header) as $pair) {
            $pair = trim($pair);
            $eq = strpos($pair, '=');
            // Skip malformed segments and cookies with an empty name.
            if ($eq === false || $eq === 0) {
                continue;
            }
            $cookies[substr($pair, 0, $eq)] = urldecode(substr($pair, $eq + 1));
        }

        return $cookies;
    }
}
