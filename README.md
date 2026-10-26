# omnibus/jdl-express

JD Logistics (京东物流) for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus):
orders with their waybills and labels, tracking and cancellation - the open platform's signed
ECAP APIs. Prices come from configuration (`rates`): JD quotes by contract.

```php
$gateway = (new JdlExpressGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibus:
    gateways:
        jdl_express:
            factory: jdl_express
            options:
                app_key: '%env(JDL_APP_KEY)%'
                app_secret: '%env(JDL_APP_SECRET)%'
                access_token: '%env(JDL_ACCESS_TOKEN)%'
                customer_code: '%env(JDL_CUSTOMER)%'     # 商家编码
                sandbox: true
                rates: [...]
```

The service is the product code (ed-m-0001 特惠送 by default, ed-m-0002 特快送...). Addresses: the
third street line carries the province. Shipment options: `description`, `instructions`,
`template`. No pickup points.

Credentials: an app on [JD Logistics' open platform](https://open.jdl.com) gives the app key and
secret; the merchant's authorisation gives the access token and the 商家编码 (UAT first).

Built from JD Logistics' published open platform documentation and tested on recorded answers;
**unverified** against UAT until an account's keys are at hand.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by JD Logistics, Inc. It reaches their service on behalf of whoever runs it, and it does
not redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with JD Logistics' terms (those
of its open platform, accepted on registration: [open.jdl.com](https://open.jdl.com/)), for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or a
citation, a rate limit to respect), and for any use made of what it returns. The authors publish it
free of charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
