<?php

namespace Omnibus\JdlExpress\Tests;

use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\JdlExpress\JdlExpressGatewayFactory;
use Omnibus\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on JD Logistics' recorded answers (UAT; unverified API shape); no rating API, so no rate without the "rates" option. */
final class JdlExpressContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new JdlExpressGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['app_key' => 'ak', 'app_secret' => 'as', 'access_token' => 'tok', 'customer_code' => '010K123456', 'sandbox' => true];
    }

    protected function contractShipment(): Shipment
    {
        return new Shipment(new Address('张三', ['福田区深南大道1000号', '', '广东省'], '518000', '深圳市', 'CN', phone: '13800000000'), new Address('李四', ['朝阳区建国路88号', '', '北京市'], '100022', '北京市', 'CN', phone: '13900000000'), [new Parcel(1500, 30, 20, 10)], reference: 'ORDER-1042');
    }

    protected function contractTrackingNumber(): string
    {
        return 'JDV000123456789';
    }

    protected function recordedAnswers(): array
    {
        return [
            '/ecap/v1/orders/create' => ['code' => 0, 'data' => ['orderId' => 'ORDER-1042', 'waybillCode' => 'JDV000123456789', 'statusCode' => 0]],
            '/ecap/v1/orders/print' => ['code' => 0, 'data' => ['pdfInfoList' => [['waybillCode' => 'JDV000123456789', 'pdfUrl' => 'https://uat-api.jdl.com/print/JDV000123456789.pdf', 'pdfBase64' => base64_encode('%PDF-1.4 jd')]]]],
            '/ecap/v1/orders/trace/query' => ['code' => 0, 'data' => ['waybillCode' => 'JDV000123456789', 'traceDetails' => [['operateTime' => '2026-10-02 11:30:00', 'operateState' => 150, 'operateRemark' => '您的快件已签收', 'operateSite' => '北京朝阳站'], ['operateTime' => '2026-10-01 17:00:00', 'operateState' => 30, 'operateRemark' => '您的快件已揽收', 'operateSite' => '深圳福田站']]]],
            '/ecap/v1/orders/cancel' => ['code' => 0, 'data' => ['cancelResult' => true]],
        ];
    }
}
