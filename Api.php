<?php

namespace Omnibus\JdlExpress;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * JD Logistics' open platform (api.jdl.com): each API is a path, the
 * payload a JSON body, signed with the app key and secret
 * (sign = md5(secret + "access_token" + token + "app_key" + key + "method" + path + "param_json" + body + "timestamp" + ts + "v" + v + secret), in capitals).
 */
final class Api
{
    public const LIVE = 'https://api.jdl.com';
    public const TEST = 'https://uat-api.jdl.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $appKey,
        private readonly string $appSecret,
        private readonly string $accessToken,
        public readonly string $customerCode,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    /** @return array<string, mixed> */
    public function call(string $path, array $body): array
    {
        $json = json_encode([$body], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'); // JD's open platform reads it as Beijing time (GMT+8)
        $query = ['LOP-DN' => 'omnibus', 'access_token' => $this->accessToken, 'app_key' => $this->appKey, 'timestamp' => $timestamp, 'v' => '2.0', 'algorithm' => 'md-5'];
        $query['sign'] = self::sign($this->appSecret, $this->accessToken, $this->appKey, $path, $json, $timestamp, '2.0');
        try {
            $response = $this->http->request('POST', ($this->sandbox ? self::TEST : self::LIVE).$path, [
                'headers' => ['Content-Type' => 'application/json;charset=UTF-8'],
                'query' => $query,
                'body' => $json,
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface|\JsonException $e) {
            $secrets = $this->secrets($query['sign']);

            throw Redaction::withoutArguments(new CarrierException('jdl_express', 'JD Logistics request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if ($status >= 400 || !\is_array($data)) {
            throw Redaction::withoutArguments(new CarrierException('jdl_express', sprintf('JD Logistics answered HTTP %d.', $status)));
        }
        if (isset($data['code']) && 0 !== (int) $data['code']) {
            throw Redaction::withoutArguments(new CarrierException('jdl_express', (string) ($data['msg'] ?? $data['message'] ?? 'JD Logistics refused the call.'), (string) $data['code']));
        }
        if (isset($data['statusCode']) && 0 !== (int) $data['statusCode']) {
            throw Redaction::withoutArguments(new CarrierException('jdl_express', (string) ($data['statusMessage'] ?? 'JD Logistics refused the request.'), (string) $data['statusCode']));
        }

        return $data['data'] ?? $data;
    }

    public static function sign(string $secret, string $token, string $key, string $method, string $json, string $timestamp, string $v): string
    {
        return strtoupper(md5($secret.'access_token'.$token.'app_key'.$key.'method'.$method.'param_json'.$json.'timestamp'.$timestamp.'v'.$v.$secret));
    }

    /**
     * What a failure must not carry: the credentials and the token, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->appKey, $this->appSecret, $this->accessToken, ...$more];
    }
}
