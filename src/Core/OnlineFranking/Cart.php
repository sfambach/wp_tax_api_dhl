<?php declare(strict_types=1);

namespace TaxApiDhl\Core\OnlineFranking;

/**
 * A shopping cart at DHL: created unpaid (PREPAID), paid by the owner on DHL's own page (PAYED). Only a paid cart
 * carries shipment numbers and labels.
 *
 * @see https://github.com/sfambach/wp_tax_shipping/blob/main/docs/konzept.md
 */
final class Cart
{
    /** @param list<string> $shipmentNumbers */
    public function __construct(
        public readonly string $id,
        public readonly string $state,
        public readonly string $payUrl,
        public readonly int $totalCents,
        public readonly array $shipmentNumbers,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->state === 'PAYED';
    }

    /** @param array<string, mixed> $body */
    public static function fromApi(array $body, string $knownId = ''): self
    {
        $cart = is_array($body['shoppingcart'] ?? null) ? $body['shoppingcart'] : [];
        $total = 0;
        $numbers = [];

        foreach (is_array($cart['items'] ?? null) ? $cart['items'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (is_array($item['prices'] ?? null) ? $item['prices'] : [] as $price) {
                $total += is_array($price) && is_numeric($price['amount'] ?? null) ? (int) round(((float) $price['amount']) * 100) : 0;
            }

            if (is_string($item['shipmentNumber'] ?? null) && $item['shipmentNumber'] !== '') {
                $numbers[] = $item['shipmentNumber'];
            }
        }

        $id = $cart['shoppingCartId'] ?? $knownId;

        return new self(
            is_scalar($id) ? (string) $id : $knownId,
            is_string($cart['state'] ?? null) ? $cart['state'] : '',
            is_string($cart['download']['entryUrl'] ?? null) ? $cart['download']['entryUrl'] : '',
            $total,
            $numbers,
        );
    }
}
