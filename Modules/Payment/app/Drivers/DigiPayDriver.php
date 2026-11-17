<?php

namespace Modules\Payment\Drivers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Orders\Models\Order;
use Modules\Payment\Contracts\GatewayInterface;
use Modules\Payment\Exceptions\PaymentFailedException;
use Modules\Payment\Services\MoneyService;

class DigiPayDriver implements GatewayInterface
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $username;
    protected string $password;

    public function __construct(
        protected MoneyService $money
    ) {
        $this->baseUrl = rtrim(config('payment.drivers.digipay.base_url'), '/');
        $this->clientId = config('payment.drivers.digipay.client_id');
        $this->clientSecret = config('payment.drivers.digipay.client_secret');
        $this->username = config('payment.drivers.digipay.username');
        $this->password = config('payment.drivers.digipay.password');
    }

    /**
     * دریافت توکن Bearer (OAuth Password Grant)
     */
    protected function getToken(): string
    {
        return Cache::remember('digipay_token', 3500, function () {
            // ۱. ساخت Basic Auth
            $basicAuth = base64_encode($this->clientId . ':' . $this->clientSecret);

            // ۲. لاگ کامل درخواست (بدون ماسک)
            $requestBody = [
                'grant_type' => 'password',
                'username'   => $this->username,
                'password'   => $this->password,
            ];

            Log::info('DigiPay Token Request', [
                'url'     => "{$this->baseUrl}/oauth/token",
                'headers' => [
                    'Authorization' => 'Basic ' . $basicAuth,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                'body'    => $requestBody,
            ]);

            // ۳. ارسال درخواست
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $basicAuth,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ])->asForm()->post("{$this->baseUrl}/oauth/token", $requestBody);

                // ۴. لاگ پاسخ کامل (حتی اگه خطا باشه)
                Log::info('DigiPay Token Response', [
                    'http_status' => $response->status(),
                    'body'        => $response->json(),
                ]);

                if ($response->failed()) {
                    throw new \RuntimeException(
                        'DigiPay token request failed: ' . $response->body()
                    );
                }

                return $response->json()['access_token'];
            } catch (\Throwable $e) {
                Log::error('DigiPay Token Exception', [
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        });
    }
    public function pay(GatewayTransaction $transaction): string
    {
        $token = $this->getToken();
        $amountInRial = $this->money->tomanToRial($transaction->amount);

        $payable = $transaction->payable;
        $order = $payable instanceof Order ? $payable : null;
        $user = $order?->user;

        // طبق مستندات، برای خرید Wallet و IPG این فیلدها کافیه
        // برای Credit/BNPL باید basketDetailsDto هم اضافه بشه
        // در متد pay() درایور DigiPayDriver، قبل از ساخت payload
        $basketItems = [];
        $type = 13;
        if ($order) {
            foreach ($order->items as $item) {
                $product = $item->product;
                $basketItems[] = [
                    'productCode' => (string) $item->product_variant_id,
                    'brand'       => $product->brand ?? 'نامشخص',
                    'productType' => 1,
                    'count'       => (int) $item->quantity,
                    'categoryId'  => (string) ($product->categories->first()?->id ?? 'default'),
                ];
            }
        }

        $payload = [
            'amount'       => $amountInRial,
            'providerId'   => (string) $transaction->id,
            'callbackUrl'  => route('payment.callback', 'digipay'),
            'cellNumber'   => $user->mobile ?? '',
            'basketDetailsDto' => [
                'items'    => $basketItems,
                'basketId' => (string) $transaction->id,
            ],
        ];


        $response = Http::withToken($token)
            ->withHeaders([
                'Agent' => 'WEB',
                'Digipay-Version' => '2022-02-02',
            ])
            ->post("{$this->baseUrl}/tickets/business?type={$type}", $payload)
            ->throw()
            ->json();

        // پاسخ شامل ticket و redirectUrl هست
        $transaction->update([
            'authority' => $response['ticket'],
            'request_data' => $response,
        ]);

        return $response['redirectUrl'];
    }

    /**
     * Verify + Deliver (برای Credit/BNPL)
     */
    public function verify(GatewayTransaction $transaction, array $callback): array
    {
        $token = $this->getToken();

        // پارامترهای callback را از دیجی‌پی بگیر
        $trackingCode = $callback['trackingCode'] ?? null;
        $providerId   = $callback['providerId'] ?? null;
        $type         = (int) ($callback['type'] ?? 0); // نوع واقعی خرید از callback

        if (!$trackingCode || !$providerId) {
            throw new PaymentFailedException('دیجی‌پی: پارامترهای ضروری در کال‌بک وجود ندارند.');
        }

        // تطبیق مبلغ و شناسه سفارش
        if ((int)$callback['amount'] !== $this->money->tomanToRial($transaction->amount) || (string)$providerId !== (string)$transaction->id) {
            throw new PaymentFailedException('دیجی‌پی: مبلغ یا شناسه سفارش با کال‌بک مطابقت ندارد.');
        }

        // مرحله ۱: Verify
        $verifyResponse = Http::withToken($token)
            ->withHeaders(['Agent' => 'WEB', 'Digipay-Version' => '2022-02-02'])
            ->post("{$this->baseUrl}/purchases/verify", [
                'trackingCode' => $trackingCode,
                'providerId'   => $providerId,
                'type'         => $type,
            ])->throw()->json();

        // بررسی موفقیت (طبق مستندات، پاسخ شامل result.status است)
        if (($verifyResponse['result']['status'] ?? -1) !== 0) {
            throw new PaymentFailedException('دیجی‌پی: تایید پرداخت ناموفق بود.', $verifyResponse);
        }

        // مرحله ۲: Deliver (فقط برای BNPL و Credit)
        $deliverResponse = null;
        if (in_array($type, [5, 13])) {
            $deliverResponse = Http::withToken($token)
                ->withHeaders(['Agent' => 'WEB', 'Digipay-Version' => '2022-02-02'])
                ->post("{$this->baseUrl}/purchases/deliver", [
                    'trackingCode' => $trackingCode,
                    'deliveryDate' => now()->timestamp * 1000,
                    'invoiceNumber' => (string) $transaction->id,
                    'products' => [], // می‌توانی SKUها را اینجا بگذاری
                    'type' => $type,
                ])->throw()->json();

            if (($deliverResponse['result']['status'] ?? -1) !== 0) {
                throw new PaymentFailedException('دیجی‌پی: تحویل خرید (Deliver) ناموفق بود.', $deliverResponse);
            }
        }

        $transaction->update([
            'verify_data' => ['verify' => $verifyResponse, 'deliver' => $deliverResponse],
        ]);

        return [
            'success' => true,
            'ref_id'  => $trackingCode,
            'response' => $verifyResponse,
        ];
    }
}
