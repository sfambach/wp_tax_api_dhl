<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Mail;

use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\OrderMailParser;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * DHL's parcel notices («Ihre AMAZON Sendung ist unterwegs», «… wurde zugestellt»): no shop order, only the tracking
 * number. The modeller gives them to the order that carries that number, whatever shop it is from (D-1020).
 * The label mails («Online Frankierung») are not notices; Taxmod Mail still reads those.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/module-schnitt.md
 */
final class DhlNoticeMailParser implements OrderMailParser
{
    private const DOMAINS = ['dhl.de', 'dhl.com', 'deutschepost.de'];

    public function __construct(private readonly string $version)
    {
    }

    public function key(): string
    {
        return 'dhl';
    }

    public function shopName(): string
    {
        return 'DHL';
    }

    public function recognises(MailMessage $mail): bool
    {
        $domain = $mail->senderDomain();
        $fromDhl = array_filter(self::DOMAINS, static fn (string $dhl): bool => $domain === $dhl || str_ends_with($domain, '.' . $dhl)) !== [];

        return $fromDhl && preg_match('/Sendung|Paket/u', $mail->subject) === 1 && ! str_contains($mail->subject, 'Frankierung');
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        $subject = mb_strtolower($mail->subject);
        $kind = match (true) {
            str_contains($subject, 'zugestellt') => ProposalKind::Delivered,
            str_contains($subject, 'unterwegs') || str_contains($subject, 'kommt heute') => ProposalKind::Shipped,
            default => ProposalKind::NoReceipt,
        };
        $number = $this->trackingNumber($mail);

        if ($kind === ProposalKind::NoReceipt || $number === null) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        return new OrderProposal($this->shopName(), 'sendung:' . $number, $kind, trackingNumbers: [$number], numberMissing: true);
    }

    public function kinds(): array
    {
        return [ProposalKind::Shipped, ProposalKind::Delivered];
    }

    public function version(): string
    {
        return $this->version;
    }

    /** The number stands on the line after «Ihre Sendungsnummer»; the tracking link names it too. */
    private function trackingNumber(MailMessage $mail): ?string
    {
        $lines = $mail->lines();

        foreach ($lines as $k => $line) {
            if (str_contains($line, 'Sendungsnummer') && preg_match('/^([0-9A-Z]{10,40})$/', $lines[$k + 1] ?? '', $found) === 1) {
                return $found[1];
            }
        }

        return preg_match('/[?&](?:piececode|idc)=([0-9A-Z]{10,40})/i', $mail->html, $found) === 1 ? $found[1] : null;
    }
}
