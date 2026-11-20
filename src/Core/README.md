# src/Core

The clients of DHL's Shipment Tracking – Unified API, Internetmarke and Online Frankierung (`OnlineFranking\`, the
shipping module's filters), the tracking result and the stage a
shipping page derives from it. Plain PHP 8.2, **no WordPress call** — HTTP goes through `Api\HttpTransport`, which the
boundary implements. API docs: [developer.dhl.com](https://developer.dhl.com/api-reference/shipment-tracking).
