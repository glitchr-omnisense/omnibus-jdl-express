<?php

namespace Omnibus\JdlExpress\Tests;

use Omnibus\Exception\CarrierException;
use Omnibus\JdlExpress\Api;
use Omnibus\JdlExpress\JdlExpressGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JdlExpressGatewayTest extends TestCase
{
    private array $calls = [];

    private static function shipment(): Shipment
    {
        return new Shipment(new Address('张三', ['福田区深南大道1000号', '', '广东省'], '518000', '深圳市', 'CN', phone: '13800000000'), new Address('李四', ['朝阳区建国路88号', '', '北京市'], '100022', '北京市', 'CN', phone: '13900000000'), [new Parcel(1500, 30, 20, 10)], reference: 'ORDER-1042');
    }

    private function gateway(): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://uat-api.jdl.com/', $url);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = [$path, $query, (string) $options['body'], json_decode((string) $options['body'], true)[0] ?? []];

            return match ($path) {
                '/ecap/v1/orders/create' => new MockResponse(json_encode(['code' => 0, 'data' => ['orderId' => 'ORDER-1042', 'waybillCode' => 'JDV000123456789', 'statusCode' => 0]])),
                '/ecap/v1/orders/print' => new MockResponse(json_encode(['code' => 0, 'data' => ['pdfInfoList' => [['waybillCode' => 'JDV000123456789', 'pdfUrl' => 'https://uat-api.jdl.com/print/JDV000123456789.pdf', 'pdfBase64' => base64_encode('%PDF-1.4 jd')]]]])),
                '/ecap/v1/orders/trace/query' => new MockResponse(json_encode(['code' => 0, 'data' => ['waybillCode' => 'JDV000123456789', 'traceDetails' => [['operateTime' => '2026-10-02 11:30:00', 'operateState' => 150, 'operateRemark' => '您的快件已签收', 'operateSite' => '北京朝阳站'], ['operateTime' => '2026-10-01 17:00:00', 'operateState' => 30, 'operateRemark' => '您的快件已揽收', 'operateSite' => '深圳福田站']]]])),
                '/ecap/v1/orders/cancel' => new MockResponse(json_encode(['code' => 0, 'data' => ['cancelResult' => true]])),
                default => new MockResponse(json_encode(['code' => 10001, 'msg' => 'unknown api'])),
            };
        });

        return (new JdlExpressGatewayFactory($http))->create(['app_key' => 'ak', 'app_secret' => 'as', 'access_token' => 'tok', 'customer_code' => '010K123456', 'sandbox' => true]);
    }

    public function testAnOrderIsCreatedSignedAndLabelled(): void
    {
        $label = $this->gateway()->ship(self::shipment());
        self::assertSame('JDV000123456789', $label->trackingNumber);
        self::assertSame('%PDF-1.4 jd', $label->content);
        [$path, $query, $body, $sent] = $this->calls[0];
        self::assertSame('ak', $query['app_key']);
        self::assertSame(Api::sign('as', 'tok', 'ak', $path, $body, $query['timestamp'], '2.0'), $query['sign']);
        self::assertSame('010K123456', $sent['customerCode']);
        self::assertSame('ed-m-0001', $sent['productsReq']['productCode']);
        self::assertSame('北京市北京市朝阳区建国路88号', $sent['receiverContact']['fullAddress']);
    }

    public function testTrackingAndCancel(): void
    {
        $gateway = $this->gateway();
        $tracking = $gateway->track('JDV000123456789', 'zh');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame(TrackingStatus::IN_TRANSIT, $tracking->events[0]->status);
        self::assertSame('北京朝阳站', $tracking->latest()->location);
        self::assertTrue($gateway->cancel('JDV000123456789'));
    }

    public function testARefusedCallIsRaisedWithItsCode(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['code' => 10002, 'msg' => '签名错误'])));
        try {
            (new JdlExpressGatewayFactory($http))->create(['app_key' => 'a', 'app_secret' => 'b', 'access_token' => 'c', 'customer_code' => 'd'])->track('JDV1');
            self::fail('raised');
        } catch (CarrierException $e) {
            self::assertSame('10002', $e->carrierCode);
        }
    }

    /** JD's open platform signs a timestamp in Beijing time (yyyy-MM-dd HH:mm:ss, GMT+8), whatever PHP's default time zone. */
    public function testTheTimestampIsBeijingTime(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $this->gateway()->ship(self::shipment());
        } finally {
            date_default_timezone_set($zone);
        }
        $sent = new \DateTimeImmutable($this->calls[0][1]['timestamp'], new \DateTimeZone('Asia/Shanghai'));
        self::assertLessThan(120, abs($sent->getTimestamp() - time()));
    }
}
