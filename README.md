# Taxmod DHL

DHL tracking for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin), over
DHL's [Shipment Tracking – Unified API](https://developer.dhl.com/api-reference/shipment-tracking).

**Status 0.0.1: API key in the common «Access» part of the modeller's «DHL» tab (sealed in the modeller's vault),
server switch sandbox / production, «Test connection», a read-only probe that tracks one number and lists its events,
and the filter `taxmod_dhl_tracking` for other modules. Not yet tested against DHL. Nothing is written to the model.**

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

## AI disclosure

This repository is created and maintained with the help of AI (Claude by Anthropic). All changes are reviewed by the owner.
