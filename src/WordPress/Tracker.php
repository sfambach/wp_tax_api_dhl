<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\TrackingClient;
use TaxApiDhl\Core\Tracking\Shipment;
use TaxApiDhl\Core\Tracking\Stage;

/**
 * Answers the filter `taxmod_dhl_tracking` for other modules: one tracking number in, its stage out. Each number is
 * asked at most once per CACHE_SECONDS, and all calls of a day stay under DAILY_BUDGET, below the app's quota of 250.
 */
final class Tracker
{
    public const FILTER        = 'taxmod_dhl_tracking';
    public const DAILY_BUDGET  = 200;
    public const CACHE_SECONDS = 2 * HOUR_IN_SECONDS;

    private const CACHE  = 'taxdhl_track_';
    private const BUDGET = 'taxmod_dhl_calls';

    public function __construct(private readonly StoredAccess $access = new StoredAccess())
    {
    }

    /**
     * @param mixed $previous what an earlier callback returned; `mixed` because filters pass anything along
     * @return array{tracking_number: string, known: bool, stage: string, status_code: string, status: string, at: string, shipped_at: string, estimated: string}|null
     *         null when no key is set, the budget is used up or DHL failed; a number DHL has not scanned yet comes back
     *         as `known` false, stage `announced` — like eBay's «tracking number entered»
     */
    public function filter(mixed $previous, mixed $trackingNumber): ?array
    {
        if (is_array($previous)) {
            return $previous;
        }

        $number = is_string($trackingNumber) ? (string) preg_replace('/[^A-Za-z0-9]/', '', $trackingNumber) : '';

        if ($number === '') {
            return null;
        }

        $cached = get_transient(self::CACHE . md5($number));

        if (is_array($cached) && is_array($cached['result'] ?? null)) {
            return $cached['result'];
        }

        try {
            $shipment = $this->ask($number);
        } catch (DhlApiException) {
            return null;
        }

        $result = $shipment === null ? self::notScannedYet($number) : self::summary($shipment);
        set_transient(self::CACHE . md5($number), ['result' => $result], self::CACHE_SECONDS);

        return $result;
    }

    /** One call to DHL, counted against the day's budget. */
    public function ask(string $number, string $service = '', string $recipientPostalCode = ''): ?Shipment
    {
        $key = $this->access->apiKey();

        if ($key === null) {
            throw new DhlApiException('No DHL API key is set.');
        }

        $this->spend();

        // No second call with the hint `post-de` for a letter's matrix code: the production app answers HTTP 400
        // «Provided service 'post-de' is unknown» (measured 2026-10-27).
        return (new TrackingClient(new WpHttpTransport(), $key, $this->access->environment()))->shipment($number, self::language(), $service, $recipientPostalCode);
    }

    /** @return array{day: string, calls: int} */
    public static function callsToday(): array
    {
        $stored = get_option(self::BUDGET);
        $today = current_time('Y-m-d');

        return is_array($stored) && ($stored['day'] ?? '') === $today ? ['day' => $today, 'calls' => (int) ($stored['calls'] ?? 0)] : ['day' => $today, 'calls' => 0];
    }

    /** @return array{tracking_number: string, known: bool, stage: string, status_code: string, status: string, at: string, shipped_at: string, estimated: string} */
    public static function summary(Shipment $shipment): array
    {
        return [
            'tracking_number' => $shipment->trackingNumber,
            'known'           => true,
            'stage'           => $shipment->stage()->value,
            'status_code'     => $shipment->statusCode,
            'status'          => $shipment->status,
            'at'              => $shipment->timestamp,
            'shipped_at'      => $shipment->firstMovement(),
            'estimated'       => $shipment->estimatedDelivery,
        ];
    }

    /** @return array{tracking_number: string, known: bool, stage: string, status_code: string, status: string, at: string, shipped_at: string, estimated: string} */
    public static function notScannedYet(string $number): array
    {
        return [
            'tracking_number' => $number,
            'known'           => false,
            'stage'           => Stage::Announced->value,
            'status_code'     => '',
            'status'          => '',
            'at'              => '',
            'shipped_at'      => '',
            'estimated'       => '',
        ];
    }

    private function spend(): void
    {
        $today = self::callsToday();

        if ($today['calls'] >= self::DAILY_BUDGET) {
            throw new DhlApiException(sprintf('Daily budget of %d DHL calls used up.', self::DAILY_BUDGET));
        }

        update_option(self::BUDGET, ['day' => $today['day'], 'calls' => $today['calls'] + 1], false);
    }

    private static function language(): string
    {
        return str_starts_with(get_locale(), 'de') ? 'de' : 'en';
    }
}
