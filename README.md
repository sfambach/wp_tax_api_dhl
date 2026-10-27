# Taxmod DHL

DHL tracking for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin), over
DHL's [Shipment Tracking – Unified API](https://developer.dhl.com/api-reference/shipment-tracking).

**Status 0.0.6: API key in the common «Access» part of the modeller's «DHL» tab (sealed in the modeller's vault),
server switch sandbox / production, «Test connection», a read-only probe that tracks one number and lists its events,
and the filter `taxmod_dhl_tracking` for other modules. New in 0.0.2: Internetmarke access (app secret + Portokasse
login, sealed) and a probe «Check Portokasse» that shows the balance and the print formats, label printers first —
step 1 towards printing letter postage on the 62 mm label printer. It buys nothing. Deutsche Post letters with a matrix code (e.g. the Maxibrief of an eBay label) are found without a hint; DHL answers them as service `svb` with an internal id, so the result keeps the number asked for (0.0.6). The probe «Track» takes an optional service hint and recipient postcode. Tracking tested against DHL production on 2026-10-27; Internetmarke not yet tested against
the Post. Nothing is written to the model.**

## Idea in short

- The eBay shipping page (and later every source) knows tracking numbers but not where the parcel is.
- This module asks DHL per tracking number and turns DHL's `statusCode` into a stage: `announced` (label only),
  `shipped`, `delivered`, `problem`.
- One place holds the DHL key and the daily quota; other modules only ask the filter.
- Addresses DHL sends are not stored, only the place of an event.

## Setup

1. Sign in at [developer.dhl.com](https://developer.dhl.com), create an app with *Shipment Tracking – Unified*.
   Start with the sandbox: it is granted at once and proves that the web server can reach DHL at all.
2. Enter the app's API key on *Taxonomy Modeller → Configuration → DHL*, server «Sandbox», save, «Test connection».
   If the test says the web server cannot reach DHL, the host blocks outgoing calls and production access is pointless.
   Or define the key in `wp-config.php`, which takes precedence. Never post it in a chat:

   ```php
   define('TAXDHL_API_KEY', '…');
   define('TAXDHL_ENVIRONMENT', 'production'); // or 'sandbox'
   ```

3. Request production access for the app, switch the server to «Production», test again, then «Track» one real number.

## Internetmarke (letter postage, optional)

The Internetmarke API sells Deutsche Post letter postage from a Portokasse and returns each stamp as PNG or PDF, so a
label can be laid out pixel-exact for a label printer. Access = client id and secret of the DHL app (with the API
«Post DE Internetmarke») plus the Portokasse login; production needs a one-time approval per Portokasse. For tests,
register a second Portokasse and ask it-csp@deutschepost.de to turn it into a developer Portokasse (stamps print
«Muster», no charge).

## For other modules

```php
$state = apply_filters('taxmod_dhl_tracking', null, $trackingNumber);
// null when unknown, no key or budget used up; otherwise
// ['tracking_number', 'stage' => 'announced|shipped|delivered|problem', 'status_code', 'status', 'at', 'shipped_at', 'estimated']
```

Each number is asked at most every two hours; all calls of a day stay under 200 (the app's quota is 250).

## Development

`composer install`, then `vendor/bin/phpunit` (core suite, no WordPress). The fixture is the example of DHL's
OpenAPI file 1.5.8.

## License

Copyright (C) 2026 Stefan Fambach (SteFam), https://www.fambach.net — [PolyForm Noncommercial 1.0.0](LICENSE.md).
Commercial use needs the owner's prior consent.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
