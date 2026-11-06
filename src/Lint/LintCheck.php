<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint;

final class LintCheck
{
    public function __construct(
        public readonly string $rule,
        public readonly LintLevel $level,
        public readonly string $message,
        public readonly ?string $hint = null,
    ) {}

    public static function pass(string $rule, string $message): self
    {
        return new self($rule, LintLevel::Pass, $message);
    }

    public static function warn(string $rule, string $message, ?string $hint = null): self
    {
        return new self($rule, LintLevel::Warn, $message, $hint);
    }

    public static function fail(string $rule, string $message, ?string $hint = null): self
    {
        return new self($rule, LintLevel::Fail, $message, $hint);
    }

    /** @return array{rule: string, level: string, message: string, hint?: string} */
    public function toArray(): array
    {
        $data = ['rule' => $this->rule, 'level' => $this->level->value, 'message' => $this->message];
        if (null !== $this->hint) {
            $data['hint'] = $this->hint;
        }

        return $data;
    }
}
