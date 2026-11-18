<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Tracking;

/**
 * Whether the last answer about a number still holds, so DHL is not asked again (D-1435): a delivered parcel never
 * changes, any other answer holds for the set number of hours.
 *
 * @see docs in wp-taxonomy-modeler: D-1435
 */
final class Freshness
{
    public const DEFAULT_HOURS = 12;

    /** @param array<string, mixed>|null $last the stored summary; `mixed`: its values */
    public static function holds(?array $last, int $now, float $hours): bool
    {
        if ($last === null) {
            return false;
        }

        if (($last['stage'] ?? '') === Stage::Delivered->value) {
            return true;
        }

        return (int) ($last['checked_at'] ?? 0) > $now - (int) round(max(0.0, $hours) * 3600);
    }
}
