<?php
/**
 * Immutable HTTP response value object.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support\Http;

final class Response {

    /**
     * @param array<string,string> $headers lower-cased header names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $url,
        public readonly string $finalUrl,
        public readonly float $elapsed,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        /** @var array<string,string> cookie name => value */
        public readonly array $cookies = []
    ) {}

    public static function error( string $url, string $message, string $code = 'http_error', float $elapsed = 0.0 ): self {
        return new self( 0, [], '', $url, $url, $elapsed, $message, $code );
    }

    public function ok(): bool {
        return null === $this->error && $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool {
        return ! $this->ok();
    }

    public function header( string $name ): string {
        return $this->headers[ strtolower( $name ) ] ?? '';
    }

    public function contentType(): string {
        return strtolower( trim( explode( ';', $this->header( 'content-type' ) )[0] ) );
    }

    public function isHtml(): bool {
        $ct = $this->contentType();
        return '' === $ct || str_contains( $ct, 'html' ) || str_contains( $ct, 'xhtml' );
    }

    /** @return array<mixed> */
    public function json(): array {
        $decoded = json_decode( $this->body, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    public function redirected(): bool {
        return $this->finalUrl !== '' && $this->finalUrl !== $this->url;
    }
}
