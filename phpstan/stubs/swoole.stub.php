<?php

/**
 * Minimal Swoole surface used by docker/swoole/server.php.
 *
 * The extension only exists inside the Swoole image, so without these
 * declarations the adapter cannot be analysed anywhere else — and the adapters
 * are exactly where a mistake distorts a benchmark silently, since they are the
 * only per-runtime code in the project.
 *
 * Deliberately narrow: only what the adapter touches. A fuller stub would drift
 * from the real extension without anyone noticing.
 */

declare(strict_types=1);

namespace {
    const SWOOLE_HOOK_ALL = 2147479551;

    // Server::__construct()'s $mode and $sockType defaults, verified against
    // swoole-src's Server::Mode enum and swSocketType enum (MODE_BASE = 1,
    // SW_SOCK_TCP = 1). Declared so the constructor's default-value expressions
    // below match the real extension instead of an arbitrary placeholder.
    const SWOOLE_BASE = 1;
    const SWOOLE_SOCK_TCP = 1;
}

namespace Swoole {
    class Runtime
    {
        public static function enableCoroutine(int $flags = SWOOLE_HOOK_ALL): bool
        {
        }
    }
}

namespace Swoole\Http {
    class Request
    {
        // Nullable because the real extension declares both as `?array`,
        // populated only once Swoole has finished parsing the request; a
        // non-nullable stub here would hide a real null-access possibility
        // from PHPStan instead of reporting it.
        /** @var array<string, string>|null */
        public ?array $server = null;

        /** @var array<string, string>|null */
        public ?array $header = null;
    }

    class Response
    {
        public function status(int $statusCode, string $reason = ''): bool
        {
        }

        /**
         * @param string|array<int, string> $value real signature accepts an
         *        array to set a repeated header; kept even though the adapter
         *        only ever passes a string, so a future caller that does pass
         *        one is not wrongly rejected.
         */
        public function header(string $key, string|array $value, bool $format = true): bool
        {
        }

        public function end(?string $content = null): bool
        {
        }
    }

    class Server
    {
        public function __construct(string $host = '0.0.0.0', int $port = 0, int $mode = SWOOLE_BASE, int $sockType = SWOOLE_SOCK_TCP)
        {
        }

        /**
         * @param array<string, mixed> $settings
         */
        public function set(array $settings): bool
        {
        }

        public function on(string $event, callable $callback): bool
        {
        }

        public function start(): bool
        {
        }
    }
}
