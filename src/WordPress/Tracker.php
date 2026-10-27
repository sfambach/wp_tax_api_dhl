<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\TrackingClient;
use TaxApiDhl\Core\Tracking\Event;
use TaxApiDhl\Core\Tracking\Shipment;
use TaxApiDhl\Core\Tracking\Stage;

/**
 * Answers the filter `taxmod_dhl_tracking` for other modules: one tracking number in, its stage out. By default it only
 * hands back what was loaded last and never calls DHL; `refresh` asks DHL now and keeps that answer (Stefan,
 * 2026-10-27: the shipping page shows the last data and updates only on a click). All calls of a day stay under
 * DAILY_BUDGET, below the app's quota of 250.
 */
final class Tracker
{
    public const FILTER       = 'taxmod_dhl_tracking';
    public const DAILY_BUDGET = 200;
    public const STORED       = 'stored';
    public const REFRESH      = 'refresh';

    private const LAST    = 'taxmod_dhl_last_answers';
    private const KEPT      = 500;
    private const BUDGET    = 'taxmod_dhl_calls';
    private const OLD_CACHE = 'taxdhl_track_';
    private const LAST_CALL = 'taxmod_dhl_last_call';
    // DHL allows one call every 5 seconds per app; faster calls are refused (developer.dhl.com/tracking, 2026-10-27).
    private const GAP_SECONDS = 5;

    public function __construct(private readonly StoredAccess $access = new StoredAccess())
    {
    }

    /**
     * @param mixed $previous what an earlier callback returned; `mixed` because filters pass anything along
     * @param mixed $mode `stored` (default: the last answer, no call) or `refresh` (ask DHL now)
     * @return array{tracking_number: string, known: bool, stage: string, status_code: string, status: string, at: string, shipped_at: string, estimated: string, events: list<array{at: string, status_code: string, status: string, description: string, place: string}>, checked_at: int}|null
     *         null when nothing was loaded yet (stored), or no key is set, the budget is used up or DHL failed
     *         (refresh); a number DHL has not scanned yet comes back as `known` false, stage `announced` — like
     *         eBay's «tracking number entered»; `checked_at` is when DHL was asked (Unix time)
     */
    public function filter(mixed $previous, mixed $trackingNumber, mixed $mode = self::STORED): ?array
    {
        if (is_array($previous)) {
            return $previous;
        }

        $number = is_string($trackingNumber) ? (string) preg_replace('/[^A-Za-z0-9]/', '', $trackingNumber) : '';

        if ($number === '') {
            return null;
        }

        $last = self::lastAnswers()[$number] ?? self::fromBefore09($number);

        if ($mode !== self::REFRESH) {
            return $last;
        }

        try {
            $shipment = $this->ask($number);
        } catch (DhlApiException) {
            return null;
        }

        // DHL has forgotten numbers it once knew (letters, measured 2026-10-27): a 404 does not erase a known answer.
        if ($shipment === null && is_array($last) && ($last['known'] ?? false) === true) {
            return $last;
        }

        return self::remember($shipment === null ? self::notScannedYet($number) : self::summary($shipment));
    }

    /**
     * Keeps a fresh answer as the number's last one; the oldest go once more than KEPT numbers are known.
     *
     * @param array<string, mixed> $answer `mixed`: the summary array
     * @return array<string, mixed>
     */
    public static function remember(array $answer): array
    {
        $answer['checked_at'] = time();
        $all = self::lastAnswers();
        unset($all[$answer['tracking_number']]);
        $all[$answer['tracking_number']] = $answer;
        update_option(self::LAST, array_slice($all, -self::KEPT, null, true), false);

        return $answer;
    }

    /**
     * Answers up to 0.0.8 sat in a transient per number; read once so they are not lost with the update.
     *
     * @return array<string, mixed>|null `mixed`: the summary array
     */
    private static function fromBefore09(string $number): ?array
    {
        $cached = get_transient(self::OLD_CACHE . md5($number));

        if (! is_array($cached) || ! is_array($cached['result'] ?? null)) {
            return null;
        }

        $answer = $cached['result'] + ['known' => true, 'events' => [], 'checked_at' => 0];
        $all = self::lastAnswers();
        $all[$number] = $answer;
        update_option(self::LAST, array_slice($all, -self::KEPT, null, true), false);

        return $answer;
    }

    /** @return array<string, array<string, mixed>> number → last answer, oldest first; `mixed`: the summary array */
    private static function lastAnswers(): array
    {
        $stored = get_option(self::LAST, []);

        return is_array($stored) ? $stored : [];
    }

    /** One call to DHL, counted against the day's budget. */
    public function ask(string $number, string $service = '', string $recipientPostalCode = ''): ?Shipment
    {
        $key = $this->access->apiKey();

        if ($key === null) {
            throw new DhlApiException('No DHL API key is set.');
        }

        $this->spend();
        self::keepGap();

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

    /** @return array{tracking_number: string, known: bool, stage: string, status_code: string, status: string, at: string, shipped_at: string, estimated: string, events: list<array{at: string, status_code: string, status: string, description: string, place: string}>} */
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
            'events'          => array_map(static fn (Event $event): array => [
                'at'          => $event->timestamp,
                'status_code' => $event->statusCode,
                'status'      => $event->status,
                'description' => $event->description,
                'place'       => $event->locality,
            ], $shipment->events),
        ];
    }

    /** @return array{tracking_number: string, known: bool, stage: string, status_code: string, status: string, at: string, shipped_at: string, estimated: string, events: list<array{at: string, status_code: string, status: string, description: string, place: string}>} */
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
            'events'          => [],
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

    /** Waits until GAP_SECONDS have passed since the last call to DHL, so a refresh of several numbers is not refused. */
    private static function keepGap(): void
    {
        $wait = (float) get_option(self::LAST_CALL, 0) + self::GAP_SECONDS - microtime(true);

        if ($wait > 0) {
            usleep((int) (min($wait, self::GAP_SECONDS) * 1_000_000));
        }

        update_option(self::LAST_CALL, microtime(true), false);
    }

    private static function language(): string
    {
        return str_starts_with(get_locale(), 'de') ? 'de' : 'en';
    }
}
