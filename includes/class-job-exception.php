<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Worker-pipeline failure with a stable internal service_code for telemetry.
 */
final class Rmmigrate_Job_Exception extends RuntimeException
{
    /** @var string */
    private $service_code;

    public function __construct(string $message, string $service_code)
    {
        parent::__construct($message);
        $this->service_code = sanitize_key($service_code);
    }

    /**
     * Escape exception message for safe throw sites (Plugin Check ExceptionNotEscaped).
     */
    public static function esc(string $message): string
    {
        return esc_html($message);
    }

    /**
     * Sanitize stable service_code for safe throw sites (Plugin Check ExceptionNotEscaped).
     */
    public static function code(string $service_code): string
    {
        return sanitize_key($service_code);
    }

    public static function raise(string $service_code, string $message): self
    {
        return new self($message, $service_code);
    }

    public function get_service_code(): string
    {
        return $this->service_code;
    }
}
