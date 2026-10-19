<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Support;

/**
 * One item of `horizon-observability:check` (Arch Q7): what was checked, the outcome, and for a warning or an error
 * exactly one actionable fix line (owner decision 12). Never carries a secret.
 *
 * @internal
 */
final readonly class CheckResult
{
    public function __construct(
        public string $section,
        public string $name,
        public CheckStatus $status,
        public string $message,
        public ?string $fix = null,
    ) {}

    public static function ok(string $section, string $name, string $message): self
    {
        return new self($section, $name, CheckStatus::Ok, $message);
    }

    public static function info(string $section, string $name, string $message, ?string $tip = null): self
    {
        return new self($section, $name, CheckStatus::Info, $message, $tip);
    }

    public static function warning(string $section, string $name, string $message, string $fix): self
    {
        return new self($section, $name, CheckStatus::Warning, $message, $fix);
    }

    public static function error(string $section, string $name, string $message, string $fix): self
    {
        return new self($section, $name, CheckStatus::Error, $message, $fix);
    }

    /**
     * @return array{section: string, name: string, status: string, message: string, fix: string|null}
     */
    public function toArray(): array
    {
        return [
            'section' => $this->section,
            'name' => $this->name,
            'status' => $this->status->value,
            'message' => $this->message,
            'fix' => $this->fix,
        ];
    }
}
