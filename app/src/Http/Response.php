<?php

declare(strict_types=1);

namespace RuntimeLab\Http;

/**
 * An immutable outbound response. Each runtime adapter converts this back into
 * its own native format; use ResponseEnvelope to build the body.
 */
final class Response
{
    // JSON_THROW_ON_ERROR so an unencodable body surfaces as an exception the
    // adapter can turn into a 500, instead of json_encode() returning false and
    // the client receiving an empty body.
    private const int JSON_ENCODING_FLAGS = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed> $body JSON-encoded by toJson().
     */
    public function __construct(
        public readonly HttpStatusCode $statusCode,
        public readonly array $body,
    ) {
    }

    public function toJson(): string
    {
        return json_encode($this->body, self::JSON_ENCODING_FLAGS);
    }
}
