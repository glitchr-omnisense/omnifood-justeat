# omnifood/justeat

Just Eat Takeaway.com (Just Eat, Takeaway, Skip The Dishes, Menulog) for
[omnisense/omnifood](https://github.com/glitchr-omnisense/omnifood): the orders JET Connect pushes
to the restaurant read and confirmed in the till, the cancellations and the drivers' progress, the
menu pushed and its items out of stock, the restaurant set offline and online and its service
times - through **JET Connect**, Just Eat's public point-of-sale API (formerly Flyt), at
`https://api.flytplatform.com`, documented at
https://developers.just-eat.com/documentation/jet-connect and
https://uk.api.just-eat.io/docs/jetconnect/openapi.yaml.

| Operation | JET Connect |
|---|---|
| `notify($body, $headers)` | Receive Order, Cancel Order, Driver Status, Restaurant Temporarily Offline, Failed Order (backup flow) |
| `accept($id)` | `POST /order/{id}/sent-to-pos-success` |
| `deny($id, $reason, $note)` | `POST /order/{id}/sent-to-pos-failed` |
| `pushMenu($menu)` | `POST /menus` |
| `setAvailability($ref, ...)` | `POST /item-availability` |
| `pause($until)` | `PUT /restaurants/{ref}/offline` |
| `resume()` | `PUT /restaurants/{ref}/online` |
| `setHours($hours)` | `PUT /restaurants/{ref}/servicetimes` |

How JET Connect works: an order is accepted on JET's **Orderpad** (the tablet), then pushed to
the restaurant's endpoint (Receive Order), which `notify()` reads whole. The endpoint answers 200
`{"OrderId": "..."}` when the order is in the till; or 202, and then `accept()`
(sent-to-pos-success) or `deny()` (sent-to-pos-failed) within 5 minutes. A failed injection is not
a refusal: JET sends the order to the tablet, to be keyed in by hand or refused there.

## Installation

```sh
composer require omnifood/justeat
```

Requires PHP 8.2, `omnisense/omnifood` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnifood\Bridge\Symfony\OmnifoodBundle`, the factory is registered as soon as
the package is installed.

## Credentials

- A **commercial agreement** with Just Eat Takeaway.com (integrations@justeattakeaway.com).
- Self-serve onboarding: a one-time link to the brand's **API key** (valid 7 days), the
  configuration `PUT /partners/onboarding/{session}/configuration` (its fields come from JET's
  integration contact), the menu and service times pushed, then `go-live` per location. These
  onboarding calls are not made by this package.
- The **restaurant references** as configured at JET Connect; the **endpoint URLs** given to JET
  (receive order, cancel, driver status, backup flow, temporarily offline), with a **key** and/or
  an **HMAC secret** for them; static IPs on request.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | for every call | JET Connect's key for the brand, sent as `X-Flyt-Api-Key` |
| `restaurant` | string | - | for the menu and the restaurant | the restaurant's reference at JET Connect (`posLocationId`) |
| `webhook_key` | string | - | for `notify()`, or `webhook_secret` | the key given to JET for its webhooks, sent back as `Authorization` |
| `webhook_secret` | string | - | for `notify()`, or `webhook_key` | the HMAC secret given to JET (`X-JET-Connect-Hash`) |
| `currency` | string | `GBP` | no | the orders' amounts come without one |
| `timezone` | string | `Europe/London` | no | the restaurant's: the service times, the end of a pause |
| `menu_type` | string | `DELIVERY` | no | or `COLLECTION` (upper-cased) |
| `standard_vat_rate` | float | `20.0` | no | an item's VAT rate at or above it is `STANDARD_RATE`, above 0 and under it `REDUCED_RATE`, 0 `NO_TAX` |
| `base_uri` | string | `https://api.flytplatform.com` | no | another API address |

No option is checked when the platform is built, except `timezone`, read by `new
\DateTimeZone()` there: one it cannot read is an `InvalidConfigException` (`The "justeat"
platform's timezone "Mars/Olympus" is not a time zone.`). A call that needs a key and finds it empty throws `InvalidConfigException`
(`The "justeat" platform needs: api_key.`, `... needs: restaurant.`).

## Capabilities

| Interface | Supported |
|---|---|
| `OrdersInterface` | `accept()`, `deny()` (after a 202); `order()`, `orders()`, `ready()`, `cancel()` refused |
| `MenuInterface` | yes |
| `StoreInterface` | `pause()`, `resume()`, `setHours()`; `status()` refused |
| `ReservationsInterface` | no |
| `NotifiableInterface` | yes |
| Tokens | none: a static key |

`capabilities()`: order types delivery and pickup; no `acceptanceDelay` (the acceptance is the
Orderpad's); `readyAt` false; `modifierDepth` 2; `allergens`, `photos` and `pauseUntil` true;
`vatRates` false (a VAT category); `modifiersAreItems` false.

Refused, because JET Connect publishes no such call for the restaurant (the older Order API with
`TimeAcceptedFor` is no longer public): reading or listing orders (they are pushed), marking one
ready, cancelling one (done on the Orderpad; JET sends the Cancel Order Notification), reading the
restaurant's state (the Restaurant Temporarily Offline webhook says it).

## Behaviour

**Every call** sends `X-Flyt-Api-Key` and `Accept: application/json`. An answer with
`"success": false` is a failure whatever its status.

**Receive Order.** A body with `third_party_order_reference` and `items` is an order:

- `reference` `id`, `displayId` `third_party_order_reference`, `store` `posLocationId`, `status`
  always `NEW`;
- `type`: `collection-by-customer` is pickup, anything else delivery;
- times are Unix timestamps: `placedAt` `created_at`, `pickupAt` `collect_at`, `deliverAt`
  `deliver_at`;
- amounts are minor units in the configured `currency`: `total` `payment.final.inc_tax` (else
  `total`), `subtotal` `payment.items_in_cart.inc_tax` (else `total`), `tax` `payment.final.tax`;
  the adjustments named `deliveryFee`, `serviceCharge`, `bagfee` and `smallOrderFee` are summed as
  `fees`, `discount` as the discount (positive);
- the lines: `name`, `quantity`, `unitPrice` (else `price`), `totalPrice` (else price times
  quantity), `plu` as `posRef`, `reference` as `platformRef`, `notes`; their `children` and `items`
  as modifiers, nested;
- the customer from `delivery` (else `collector`): first and last names, phone, phone masking
  code, e-mail, and the address in one line for `delivery-by-merchant`;
- the driver (name, phone) as the courier; `kitchen_notes`, `delivery_notes` and
  `collection_notes` joined as the note.

**The other webhooks**, told apart by their body: `orderID` and `reason` is a cancellation
(`order.cancelled`, `CANCELLED`); `orderID` and `driverStatus` the driver (`driver.<code>`,
`onItsWay` `PICKED_UP`, `delivered` `DELIVERED`); `restaurantId` and `lastChangedTimeStampUtc`
the restaurant set offline by JET (`restaurant.temp_offline`, subject store); `validationError`
and `order` the backup flow (`order.backup_flow`); anything else `unknown`, subject other. JET
gives no event id: `id` is composed from the order and the event, the same on a retry. Each is
answered 200 with the same body; a 500 makes JET retry, up to 5 times.

**The check.** With `webhook_key`, the `Authorization` header must equal it (constant time). With
`webhook_secret`, `X-JET-Connect-Hash` (`HMAC-SHA256 t=...,signature=<base64>`) must hold the
base64 HMAC-SHA256 of the body; it is checked on an order body, on any body that carries the
header, and, when `webhook_secret` is set without `webhook_key`, on every body: JET documents the
hash on the order webhooks only, so a cancellation, driver, offline or backup-flow body without it
is then refused (`InvalidSignatureException`) - configure `webhook_key` to receive those. Neither
configured: `InvalidConfigException`.

**Accept and deny.** `accept()` posts an empty object to sent-to-pos-success; `$readyAt` is not
sent. `deny()` posts `happenedAt` (now, ATOM), `errorCode` (`MENU_ERROR` for `ITEM_UNAVAILABLE`
and `PRICING`, `STORE_CLOSED` for `CLOSED`, `UNKNOWN` otherwise) and `errorMessage` (the note, else
the reason's value).

**Menu.** Checked by the core's validator, then `{"restaurants": [restaurant], "menus": [...]}`:
the menu's `name`, `reference` (its `ref`, else its name), `type` (`menu_type`), `description`,
`availability` per day (`"12:00 - 14:30"`) from `Menu::$hours`, its categories (type `root`).
Each item: `name`, `description`, `plu` (the reference), `price` (minor units), `out_of_stock`
(not `available`), `tax_category` from the VAT rate, `allergens` in JET's list (`GLUTEN` and
`NUTS` as every cereal and nut unless the labels name some: `CEREAL_WHEAT`, `NUTS_ALMONDS`...;
`SESAME_SEEDS`, `SULPHUR_DIOXIDE_SULPHITES`), `dietary_restrictions` (`VEGAN` for the label
`vegan`, else `VEGETARIAN` for `vegetarian`), `gallery` (the photo). A group is a modifier with
`pick` `exactly` when `min` equals `max`, else a `range` from `min` to `max` (or the number of
options), and its options as items, nested. Allergens are shown in DE, NL, AT, BE, BG, DK, LU, PL
and SK only.

**Out of stock.** `AVAILABLE` or `UNAVAILABLE` for the item reference and the restaurant, with
`happenedAt` and, when unavailable until a time, `nextAvailableAt` (ATOM).

**The restaurant.** `pause($until)` sends `onlineAt` in the restaurant's local time
(`Y-m-d\TH:i:s`), or an empty body (until `resume()`); the reason is not sent. In AT, BE, BG, CH,
DE, LU, NL, PL and SK, JET brings a restaurant back online at 07:00 Amsterdam time the next day
whatever is asked. `setHours()` sends the same week for `Delivery` and `Collection`
(`openingTime`, `closingTime`) with the `timezone`; JET applies the overlap of these, the menu's
availability and its delivery pool's hours. `Hours::$exceptions` are not sent (no call for dates
apart: a closed day is a `pause()`).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifood\Exception\InvalidSignatureException;
use Omnifood\JustEat\JustEatPlatformFactory;
use Omnifood\Model\DenyReason;
use Symfony\Component\HttpClient\HttpClient;

$justeat = (new JustEatPlatformFactory(HttpClient::create()))->create([
    'api_key' => getenv('JUSTEAT_API_KEY') ?: null,
    'restaurant' => getenv('JUSTEAT_RESTAURANT') ?: null,
    'webhook_key' => getenv('JUSTEAT_WEBHOOK_KEY') ?: null,
    'webhook_secret' => getenv('JUSTEAT_WEBHOOK_SECRET') ?: null,
    'currency' => 'EUR',
    'timezone' => 'Europe/Dublin',
]);

$body = (string) file_get_contents('php://input');
try {
    $notification = $justeat->notify($body, getallheaders());
} catch (InvalidSignatureException) {
    http_response_code(401);
    exit;
}
header('Content-Type: application/json');
if ('order.received' === $notification->event) {
    http_response_code(202);                                  // taken in later
    echo json_encode(['OrderId' => $notification->order->reference]);
} else {
    echo $body;                                               // the other webhooks: the same body back
}

// Within 5 minutes, in a worker:
// $justeat->accept($reference);
// $justeat->deny($reference, DenyReason::ITEM_UNAVAILABLE, 'No more gyoza');

$justeat->pause(new \DateTimeImmutable('+30 minutes'));      // offline until then, local time
```

```yaml
# config/packages/omnifood.yaml
omnifood:
    platforms:
        justeat:
            factory: justeat
            options:
                api_key: '%env(default::JUSTEAT_API_KEY)%'
                restaurant: '%env(default::JUSTEAT_RESTAURANT)%'
                webhook_key: '%env(default::JUSTEAT_WEBHOOK_KEY)%'
                webhook_secret: '%env(default::JUSTEAT_WEBHOOK_SECRET)%'
                currency: EUR
                timezone: Europe/Dublin
```

Not run live (no key).

## Errors

- 401 and 403: `UnauthorizedException`; any other status of 400 or more, and any answer with
  `"success": false`: `ProviderException`, the message from `error_message`, `message`,
  `errorMessage` or the first fault's `description` (else `HTTP <status>.`), the fault's
  `errorCode` as `providerCode`. A 429 has no message of its own here.
- A network failure: `ProviderException` `JET Connect could not be reached: ...`.
- A 2xx answer whose body is not JSON (a proxy's or a maintenance page): `ProviderException`
  `An answer that is not JSON (HTTP 200).`, with the status.
- `order()`, `orders()`, `ready()`, `cancel()`, `status()`: `NotSupportedException`.
- A webhook whose key or hash does not hold, or whose body is not JSON:
  `InvalidSignatureException`.

No failure carries the API key: a network failure's message has it masked as `***` (the key
parameters of a URL too), its previous is a copy of the HTTP client's exception (the same class and
code), and no trace of the chain keeps its arguments, where the request's options stood ([No secret
in a
failure](https://github.com/glitchr-omnisense/omnifood/blob/1.x/docs/errors.md#no-secret-in-a-failure)).

## Verified, and not

`Tests/JustEatPlatformTest.php` checks, on JET's documented examples (read on 2026-10-04): the
signature against JET's own example, an order pushed to the restaurant read whole, a pickup and a
delivery by the restaurant, a webhook not signed or not ours refused, the other webhooks, an order
taken in later or not, what JET Connect does not publish, the menu in JET's shape, a menu the
validator refuses sending nothing, an item out of stock until tomorrow, the restaurant offline,
online and its hours, the errors, and what works without keys. Green in the family's harness on
2026-10-26. Not run against JET Connect: JET gives its key only under a commercial agreement.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Just Eat Takeaway.com. Whoever runs it complies with Just Eat Takeaway.com's terms
(those of the commercial agreement; [developers.just-eat.com](https://developers.just-eat.com/));
see the README's Responsibility section.
