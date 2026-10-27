<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Tracking;

/**
 * What the Unified API knows about one tracking number: current status and the events behind it. Addresses are not
 * kept beyond the locality of an event.
 */
final class Shipment
{
    /** @param list<Event> $events newest first, as DHL sends them */
    public function __construct(
        public readonly string $trackingNumber,
        public readonly string $service,
        public readonly string $statusCode,
        public readonly string $status,
        public readonly string $timestamp,
        public readonly string $estimatedDelivery,
        public readonly array $events,
    ) {
    }

    /**
     * @param array<string, mixed> $data one entry of `shipments`; `mixed` because DHL leaves fields out freely
     * @param string $askedNumber the number asked for; DHL's `id` is an internal UUID for some services (svb, letters)
     */
    public static function fromApi(array $data, string $askedNumber = ''): self
    {
        $text = static fn (mixed $value): string => is_string($value) ? trim($value) : '';
        $status = is_array($data['status'] ?? null) ? Event::fromApi($data['status']) : Event::fromApi([]);
        $events = array_values(array_map(
            static fn (array $event): Event => Event::fromApi($event),
            array_filter(is_array($data['events'] ?? null) ? $data['events'] : [], 'is_array')
        ));

        return new self(
            $askedNumber !== '' ? $askedNumber : $text($data['id'] ?? null),
            $text($data['service'] ?? null),
            $status->statusCode,
            $status->description !== '' ? $status->description : $status->status,
            $status->timestamp,
            $text($data['estimatedTimeOfDelivery'] ?? null),
            $events,
        );
    }

    public function stage(): Stage
    {
        return Stage::of($this);
    }

    /** The oldest event that shows the parcel in DHL's hands, or '' — the moment it counts as shipped. */
    public function firstMovement(): string
    {
        foreach (array_reverse($this->events) as $event) {
            if (in_array($event->statusCode, ['transit', 'delivered', 'failure'], true)) {
                return $event->timestamp;
            }
        }

        return '';
    }
}
