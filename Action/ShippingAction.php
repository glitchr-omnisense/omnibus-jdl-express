<?php

namespace Omnibus\JdlExpress\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Exception\CarrierException;
use Omnibus\Exception\UsageLimitReachedException;
use Omnibus\JdlExpress\Api;
use Omnibus\Model\Address;
use Omnibus\Model\Label;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;

/** /ecap/v1/orders/create: the order (service: the product code, ed-m-0001 standard by default), its waybill number; the label from /ecap/v1/orders/print. */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $s = $request->shipment;
        $data = $this->api->call('/ecap/v1/orders/create', array_filter([
            'orderId' => $s->reference ?? 'omnibus-'.bin2hex(random_bytes(6)),
            'orderOrigin' => 1,
            'customerCode' => $this->api->customerCode,
            'productsReq' => ['productCode' => $s->service ?? 'ed-m-0001'],
            'settleType' => 3,
            'senderContact' => self::contact($s->sender),
            'receiverContact' => self::contact($s->recipient),
            'cargoes' => array_map(static fn ($p) => array_filter(['name' => $s->option('description', '商品'), 'quantity' => 1, 'weight' => round(max(0.1, $p->weight / 1000), 2), 'volume' => $p->length && $p->width && $p->height ? round($p->length * $p->width * $p->height, 0) : null]), $s->parcels),
            'commonChannelInfo' => ['channelCode' => 'omnibus'],
            'remark' => mb_substr((string) $s->option('instructions', ''), 0, 100),
        ], static fn ($v) => null !== $v && '' !== $v));
        $number = (string) ($data['waybillCode'] ?? '');
        if ('' === $number) {
            throw new CarrierException('jdl_express', 'JD Logistics issued no waybill.');
        }
        $content = null;
        $url = null;
        try {
            $print = $this->api->call('/ecap/v1/orders/print', ['customerCode' => $this->api->customerCode, 'waybillCodes' => [$number], 'templateType' => (string) $s->option('template', 'JD_SOP_TEMPLATE_100x150'), 'printType' => 1]);
            $file = $print['pdfInfoList'][0] ?? $print[0] ?? [];
            $url = $file['pdfUrl'] ?? $file['url'] ?? null;
            if (isset($file['pdfBase64']) && \is_string($file['pdfBase64'])) {
                $content = base64_decode($file['pdfBase64']);
            }
        } catch (CarrierException|UsageLimitReachedException) {
            // The shipment is booked: a label held by the carrier's wait is fetched later, as a failed one.
        }
        $request->setResult(new Label('jdl_express', $number, $content, Label::PDF, $url, 'https://www.jdl.com/orderSearch?waybillCodes='.rawurlencode($number)));
    }

    private static function contact(Address $a): array
    {
        return array_filter(['name' => $a->name, 'company' => $a->company, 'mobile' => $a->phone, 'phone' => $a->phone, 'fullAddress' => trim(implode('', array_filter([$a->line(2), $a->city, $a->line(0), $a->line(1)]))), 'postCode' => $a->postcode, 'countryCode' => strtoupper($a->country), 'email' => $a->email], static fn ($v) => null !== $v && '' !== $v);
    }
}
