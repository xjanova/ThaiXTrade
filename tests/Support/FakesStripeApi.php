<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stripe จำลองระดับ HTTP — โค้ดจริงเรียก Session/Refund แบบ static จึง mock คลาสไม่ได้.
 *
 * เส้นทางจับด้วย "METHOD /v1/path" ตรงตัว ค่าเป็น array (คำตอบ) หรือ callable(array $params): array
 * ไม่พบเส้นทาง = 404 ของ Stripe เพื่อให้ทางที่ไม่ได้ตั้งไว้ล้มดัง ๆ แทนที่จะผ่านเงียบ ๆ
 */
trait FakesStripeApi
{
    /** @var array<int, array{route: string, params: array}> */
    protected array $stripeCalls = [];

    /** @var array<string, array|callable> */
    protected array $stripeRoutes = [];

    protected function fakeStripe(array $routes = []): void
    {
        config([
            'services.stripe.secret' => 'sk_test_unit',
            'services.stripe.webhook_secret' => 'whsec_unit_test',
        ]);
        Cache::flush();

        $this->stripeRoutes = $routes;

        ApiRequestor::setHttpClient(new class($this) implements ClientInterface
        {
            public function __construct(private readonly object $test) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return $this->test->answerStripe(strtoupper($method), (string) parse_url($absUrl, PHP_URL_PATH), (array) $params);
            }
        });
    }

    /** @return array{string, int, array} */
    public function answerStripe(string $method, string $path, array $params): array
    {
        $route = "{$method} {$path}";
        $this->stripeCalls[] = ['route' => $route, 'params' => $params];

        if (! array_key_exists($route, $this->stripeRoutes)) {
            return [json_encode(['error' => ['message' => "no fake for {$route}", 'type' => 'invalid_request_error']]), 404, []];
        }

        $answer = $this->stripeRoutes[$route];

        return [json_encode(is_callable($answer) ? $answer($params) : $answer), 200, []];
    }

    /** @return array<int, array{route: string, params: array}> */
    protected function stripeCallsTo(string $route): array
    {
        return array_values(array_filter($this->stripeCalls, fn (array $call) => $call['route'] === $route));
    }

    /** ลายเซ็นจริงของ webhook — ใช้ secret เดียวกับ fakeStripe() */
    protected function signedStripeEvent(string $id, string $type, array $object): array
    {
        $payload = json_encode([
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'data' => ['object' => $object],
        ]);

        $t = time();

        return [$payload, "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_unit_test')];
    }

    protected function tearDownStripe(): void
    {
        ApiRequestor::setHttpClient(null);
    }
}
