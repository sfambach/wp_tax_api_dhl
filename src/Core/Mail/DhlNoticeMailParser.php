<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Mail;

use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\ParcelNoticeMailParser;
use Taxmod\Core\Import\ProposalKind;

/**
 * DHL's parcel notices («Ihre AMAZON Sendung ist unterwegs», «… wurde zugestellt»): no shop order, only the tracking
 * number. The modeller gives them to the order that carries that number, whatever shop it is from (D-1020).
 * The label mails («Online Frankierung») are not notices; Taxmod Mail still reads those.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/module-schnitt.md
 */
final class DhlNoticeMailParser extends ParcelNoticeMailParser
{
    private const DOMAINS = ['dhl.de', 'dhl.com', 'deutschepost.de'];

    public function __construct(string $version)
    {
        parent::__construct('dhl', 'DHL', self::DOMAINS, $version);
    }

    public function recognises(MailMessage $mail): bool
    {
        return $this->fromDomain($mail) && preg_match('/Sendung|Paket/u', $mail->subject) === 1 && ! str_contains($mail->subject, 'Frankierung');
    }

    protected function kind(MailMessage $mail): ProposalKind
    {
        $subject = mb_strtolower($mail->subject);

        return match (true) {
            str_contains($subject, 'zugestellt') => ProposalKind::Delivered,
            str_contains($subject, 'unterwegs') || str_contains($subject, 'kommt heute') => ProposalKind::Shipped,
            default => ProposalKind::NoReceipt,
        };
    }

    /** The number stands on the line after «Ihre Sendungsnummer»; the tracking link names it too. */
    protected function parcelNumber(MailMessage $mail): ?string
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
