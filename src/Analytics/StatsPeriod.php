<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Analytics;

use MattLeads\AgentPublishingBundle\Model\ValidationException;

/**
 * Inclusive day range for the stats endpoints and tools: defaults to the last 30 days, capped at 366.
 */
final class StatsPeriod
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;

    public function __construct(public readonly \DateTimeImmutable $from, public readonly \DateTimeImmutable $to) {}

    /** @throws ValidationException */
    public static function parse(?string $from, ?string $to, \DateTimeZone $timezone, \DateTimeImmutable $now): self
    {
        $today = $now->setTimezone($timezone)->setTime(0, 0);
        $toDay = self::day($to, 'to', $timezone) ?? $today;
        $fromDay = self::day($from, 'from', $timezone) ?? $toDay->modify(\sprintf('-%d days', self::DEFAULT_DAYS - 1));
        if ($fromDay > $toDay) {
            throw new ValidationException(['"from" must not be after "to".']);
        }
        if ($fromDay->diff($toDay)->days >= self::MAX_DAYS) {
            throw new ValidationException([\sprintf('The period must not exceed %d days.', self::MAX_DAYS)]);
        }

        return new self($fromDay, $toDay);
    }

    /** @throws ValidationException */
    private static function day(?string $value, string $name, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || false === $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone)) {
            throw new ValidationException([\sprintf('"%s" must be a date formatted YYYY-MM-DD.', $name)]);
        }

        return $day;
    }
}
