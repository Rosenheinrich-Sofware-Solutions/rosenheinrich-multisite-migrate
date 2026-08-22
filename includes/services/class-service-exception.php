<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Service_Exception extends RuntimeException
{
    public const CODE_NOT_FOUND = 'not_found';
    public const CODE_FORBIDDEN = 'forbidden';
    public const CODE_VALIDATION = 'validation';
    public const CODE_CONFLICT = 'conflict';

    /** @var string */
    private $code_key;

    /** @var array<string,mixed> */
    private $context;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(string $message, array $context = array(), string $code_key = self::CODE_VALIDATION)
    {
        parent::__construct($message);
        $this->code_key = sanitize_key($code_key);
        $this->context = $context;
    }

    /**
     * Sanitize stable code_key for safe throw sites (Plugin Check ExceptionNotEscaped).
     */
    public static function code(string $code_key): string
    {
        return sanitize_key($code_key);
    }

    /**
     * Escape exception message for safe throw sites (Plugin Check ExceptionNotEscaped).
     */
    public static function esc(string $message): string
    {
        return esc_html($message);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function not_found(string $message, array $context = array()): self
    {
        return new self(esc_html($message), $context, self::CODE_NOT_FOUND);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function forbidden(string $message, array $context = array()): self
    {
        return new self(esc_html($message), $context, self::CODE_FORBIDDEN);
    }

    public function get_code_key(): string
    {
        return $this->code_key;
    }

    public function get_http_status(): int
    {
        switch ($this->code_key) {
            case self::CODE_NOT_FOUND:
                return 404;
            case self::CODE_FORBIDDEN:
                return 403;
            case self::CODE_CONFLICT:
                return 409;
            default:
                return 400;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function get_context(): array
    {
        return $this->context;
    }
}
