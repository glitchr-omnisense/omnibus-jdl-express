# omnibus/jdl-express

JD Logistics (京东物流) for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus):
orders with their waybills and PDF labels, tracking and cancellation, through the ECAP APIs of
JD Logistics' open platform, every call signed. JD quotes by contract: prices come from the
`rates` option. The gateway calls:

| Operation | Path |
|---|---|
| ship | `POST /ecap/v1/orders/create`, then `POST /ecap/v1/orders/print` |
| track | `POST /ecap/v1/orders/trace/query` |
| cancel | `POST /ecap/v1/orders/cancel` |

on `https://api.jdl.com` (live) or `https://uat-api.jdl.com` (UAT, `sandbox: true`), each with a
20-second timeout.

## Installation

```sh
composer require omnibus/jdl-express
```

Requires PHP 8.2, `omnisense/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

- An app on [JD Logistics' open platform](https://open.jdl.com): its **app key** and **app
  secret**.
- The merchant's authorisation: an **access token** and the **customer code** (商家编码). UAT
  first.

Every call posts its payload as a JSON list of one object, with the query `LOP-DN=omnibus`,
`access_token`, `app_key`, `timestamp` (`Y-m-d H:i:s`, Beijing time, GMT+8, whatever PHP's default
time zone), `v=2.0`,
`algorithm=md-5` and `sign` = the MD5, in capitals, of `secret + "access_token" + token +
"app_key" + key + "method" + path + "param_json" + body + "timestamp" + timestamp + "v" + "2.0" +
secret` (`Omnibus\JdlExpress\Api::sign()`).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `app_key` | string | - | yes | the app key |
| `app_secret` | string | - | yes | the app secret that signs every call |
| `access_token` | string | - | yes | the merchant's authorisation token |
| `customer_code` | string | - | yes | the 商家编码, sent with every request |
| `sandbox` | bool | `false` | no | `true`: UAT |
| `rates` | list | - | no | the only way this gateway rates ([the rates option](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/configuration.md#the-rates-option)) |

Shipment options read when booking (`Shipment::$options`):

| Option | Default | |
|---|---|---|
| `description` | `商品` | each cargo's name |
| `instructions` | `''` | the order's remark, 100 characters |
| `template` | `JD_SOP_TEMPLATE_100x150` | the print template |

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | only with `rates` (configured prices) |
| `Shipping` | yes |
| `Tracking` | yes |
| `Pickup` | no |
| `GetSlip` | no |
| `Cancel` | yes |
| `Paperless` | no |

## Behaviour

**Answers.** A `code` other than 0, or a `statusCode` other than 0, is an error; the answer's
`data` (else the answer) is read.

**Ship.** `/ecap/v1/orders/create`:

- the order id is the shipment's `reference`, else `omnibus-` and 12 random hexadecimal
  characters; order origin 1; the customer code;
- the product `Shipment::$service`, else `ed-m-0001` (特惠送; `ed-m-0002` is 特快送); settlement
  type 3;
- sender and receiver: name, company, the phone as mobile and phone, the full address written
  as the **third street line (the province)**, the city, then the first two street lines, joined
  without spaces (`北京市` + `北京市` + `朝阳区建国路88号`), postcode, country, email;
- one cargo per parcel: the `description`, quantity 1, the weight in kilograms rounded to two
  decimals (at least 0.1), the volume in cubic centimetres when all three dimensions are given;
- channel `omnibus`; the `instructions` as remark.

The tracking number is the `waybillCode`. Then `/ecap/v1/orders/print` for that waybill with the
`template` and print type 1: the first PDF's `pdfBase64` is the label's `content`, its `pdfUrl`
(else `url`) the label's `url`. When the print call fails, the booking still succeeds without a
document. The format is `application/pdf`; `trackingUrl` is
`https://www.jdl.com/orderSearch?waybillCodes=<number>`. A response without a waybill is a
`CarrierException` (`JD Logistics issued no waybill.`). `pickupPoint` is not sent.

**Track.** `/ecap/v1/orders/trace/query` for the waybill; the locale is not sent. The traces:
`operateTime` read in `Asia/Shanghai`, `operateRemark` (else `remark`) as description,
`operateSite` as location, `operateState` as `code`; sorted oldest first. The status is the latest
trace's, read in this order:

| State | Or the remark contains | `TrackingStatus` |
|---|---|---|
| `150`, `160`, `170` | `已签收`, `妥投`, `delivered` | `DELIVERED` |
| `140` | `派送中`, `正在派送`, `out for delivery` | `OUT_FOR_DELIVERY` |
| `180`, `190` | `拒收`, `退回`, `return` | `RETURNED` |
| | `异常`, `exception` | `EXCEPTION` |
| `10`, `20` | `已下单`, `等待揽收` | `PENDING` |
| no state and no remark | | `UNKNOWN` |
| anything else | | `IN_TRANSIT` |

**Cancel.** `/ecap/v1/orders/cancel` with the customer code, the waybill, the reason `cancelled by
the shipper` and order origin 1: `true` when `cancelResult` is absent, `true`, `1` or `SUCCESS`.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\JdlExpress\JdlExpressGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Symfony\Component\HttpClient\HttpClient;

$jdl = (new JdlExpressGatewayFactory(HttpClient::create()))->create([
    'app_key' => getenv('JDL_APP_KEY'),
    'app_secret' => getenv('JDL_APP_SECRET'),
    'access_token' => getenv('JDL_ACCESS_TOKEN'),
    'customer_code' => getenv('JDL_CUSTOMER'),
    'sandbox' => true,
]);

$shipment = new Shipment(
    new Address('张三', ['科技园南区1号', '', '广东省'], '518057', '深圳市', 'CN', phone: '13800138000'),
    new Address('李四', ['朝阳区建国路88号', '', '北京市'], '100022', '北京市', 'CN', phone: '13900139000'),
    [new Parcel(1200, 30, 20, 10)],
    service: 'ed-m-0001',
    reference: 'CMD-1042',
    options: ['description' => '印刷品'],
);

$label = $jdl->ship($shipment);
file_put_contents($label->trackingNumber.'.pdf', (string) $label->content);
echo $label->trackingNumber, ' ', $label->trackingUrl, "\n";

$tracking = $jdl->track($label->trackingNumber);
echo $tracking->status->value, ' ', $tracking->latest()?->location, "\n";

var_dump($jdl->cancel($label->trackingNumber));
```

In Symfony:

```yaml
omnibus:
    gateways:
        jdl_express:
            factory: jdl_express
            options:
                app_key: '%env(JDL_APP_KEY)%'
                app_secret: '%env(JDL_APP_SECRET)%'
                access_token: '%env(JDL_ACCESS_TOKEN)%'
                customer_code: '%env(JDL_CUSTOMER)%'
                sandbox: '%env(bool:JDL_SANDBOX)%'
                rates:
                    - { service: ed-m-0001, label: '京东特惠送', currency: CNY, countries: [CN], bands: { 1000: 1000, 5000: 1800 } }
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `jdl_express`:

- a `code` other than 0: `msg` (else `message`, else `JD Logistics refused the call.`), the code
  in `carrierCode`;
- a `statusCode` other than 0: `statusMessage` (else `JD Logistics refused the request.`), the
  code in `carrierCode`;
- an HTTP status of 400 or more, or a body that is not JSON: `JD Logistics answered HTTP
  <status>.`; a network error: `JD Logistics request failed: ...`;
- `JD Logistics issued no waybill.` when no waybill comes back.

A failed print call after the order is created is not raised (above). A missing option among the
four credentials is an `InvalidConfigException` when the gateway is built; `Pickup`, `GetSlip`,
`Paperless`, and `Rating` without `rates`, raise `RequestNotSupportedException` before any call.

No failure carries the app key and secret, the access token and the signature (in the query): a
network error's message has them masked as `***` (the key parameters of a URL too), its previous is
a copy of the HTTP client's exception (the same class and code), and no trace of the chain keeps its
arguments, where the request's options stood ([No secret in a
failure](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/errors.md#no-secret-in-a-failure)).

## Verified, and not

Tested offline: `Tests/JdlExpressGatewayTest.php` (an order created, signed and labelled: the app
key, the signature, the customer code, the product, the full address; tracking with its statuses
and location; the cancellation; a refused call raised with its code) and
`Tests/JdlExpressContractTest.php`, the family's conformance suite on recorded answers. The example
above was run in the core's harness on those recorded answers.

Not verified: the README and the factory mark the package **unverified** against UAT until an
account's keys are at hand; it is built from JD Logistics' published open platform documentation.
The signature's timestamp is written in Beijing time (GMT+8), as JD's open platform documents it;
unchecked against UAT.

## Responsibility

An independent, unofficial client, not affiliated with JD Logistics, Inc. Whoever runs it
complies with JD Logistics' terms (those of its open platform, accepted on registration:
[open.jdl.com](https://open.jdl.com/)): see the README's Responsibility section.
