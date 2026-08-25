<?php

declare(strict_types=1);

namespace RuntimeLab\Http;

/**
 * The status codes this lab answers with, by name.
 *
 * A backed enum rather than integer constants: a Response can then only be
 * built with a real status code, and the call site reads without anyone
 * remembering what 502 was. Use `->value` for the integer a runtime adapter
 * needs.
 *
 * Only the codes actually served are listed. Adding one is two lines — a case
 * and its phrase — and the smoke test fails if the phrase is missing.
 */
enum HttpStatusCode: int
{
    case OK = 200;
    case NOT_FOUND = 404;
    case INTERNAL_SERVER_ERROR = 500;
    case BAD_GATEWAY = 502;

    /** The IANA-registered reason phrase, e.g. "Not Found" for 404. */
    public function reasonPhrase(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::NOT_FOUND => 'Not Found',
            self::INTERNAL_SERVER_ERROR => 'Internal Server Error',
            self::BAD_GATEWAY => 'Bad Gateway',
        };
    }

    /** Compares the class digit, so these stay correct as cases are added. */
    public function isSuccessful(): bool
    {
        return intdiv($this->value, 100) === 2;
    }

    public function isError(): bool
    {
        return intdiv($this->value, 100) >= 4;
    }
}
