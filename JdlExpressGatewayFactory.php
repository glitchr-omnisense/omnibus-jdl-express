<?php

namespace Omnibus\JdlExpress;

use Omnibus\Config;
use Omnibus\GatewayFactory;
use Omnibus\JdlExpress\Action\CancelAction;
use Omnibus\JdlExpress\Action\ShippingAction;
use Omnibus\JdlExpress\Action\TrackingAction;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     app_key: '%env(JDL_APP_KEY)%'            # the open platform app
 *     app_secret: '%env(JDL_APP_SECRET)%'
 *     access_token: '%env(JDL_ACCESS_TOKEN)%'  # the merchant's authorisation token
 *     customer_code: '%env(JDL_CUSTOMER)%'     # the 商家编码
 *     sandbox: true
 *     rates: [...]                             # prices from configuration: JD quotes by contract
 *
 * No pickup points. Unverified until an account's keys are at hand.
 */
final class JdlExpressGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'jdl_express',
            'omnibus.factory_title' => 'JD Logistics',
            'omnibus.policy' => __DIR__.'/policy.json',
            'omnibus.required_options' => ['app_key', 'app_secret', 'access_token', 'customer_code'],
            'sandbox' => false,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, (string) $c['app_key'], (string) $c['app_secret'], (string) $c['access_token'], (string) $c['customer_code'], (bool) $c['sandbox'], backoff: $c->backoff());
            },
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.cancel' => new CancelAction(),
        ]);
    }
}
