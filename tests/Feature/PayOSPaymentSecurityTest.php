<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayOSPaymentSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private const FRIENDLY_CHECKOUT_ERROR = 'Không thể tạo liên kết thanh toán PayOS. Vui lòng thử lại sau.';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.payos.checksum_key', 'test-checksum-key');
        Config::set('services.payos.client_id', 'test-client');
        Config::set('services.payos.api_key', 'test-api-key');
        Config::set('services.payos.mode', 'live');
    }

    public function test_valid_payos_response_redirects_to_checkout_url_and_sends_contract_payload(): void
    {
        [$order, $payment, $user] = $this->pendingOrder();
        $checkoutUrl = 'https://pay.payos.vn/web/test-payment-link';
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response([
                'code' => '00',
                'desc' => 'success',
                'data' => ['checkoutUrl' => $checkoutUrl],
            ], 200),
        ]);

        $response = $this->actingAs($user)->post(
            route('student.checkout.process_payment', $order->order_code),
            ['payment_method' => 'payos']
        );

        $response->assertRedirect($checkoutUrl);
        Http::assertSent(function (HttpRequest $request) use ($order): bool {
            $data = $request->data();
            $signedData = [
                'amount' => $data['amount'],
                'cancelUrl' => $data['cancelUrl'],
                'description' => $data['description'],
                'orderCode' => $data['orderCode'],
                'returnUrl' => $data['returnUrl'],
            ];

            return $data['orderCode'] === $order->id
                && is_int($data['orderCode'])
                && $data['amount'] === 100000
                && is_int($data['amount'])
                && strlen($data['description']) <= 9
                && $data['signature'] === $this->signature($signedData);
        });

        $this->assertSame((string) $order->id, $payment->fresh()->gateway_order_code);
    }

    public function test_http_failure_is_redirected_with_a_friendly_message(): void
    {
        [$order, , $user] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response(['code' => '01', 'desc' => 'invalid request'], 400),
        ]);

        $response = $this->payOrder($order, $user);

        $response->assertRedirect(route('student.checkout.pay', $order->order_code));
        $response->assertSessionHas('error', self::FRIENDLY_CHECKOUT_ERROR);
    }

    public function test_http_200_with_payos_business_error_is_not_parsed_as_a_checkout_success(): void
    {
        [$order, , $user] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response([
                'code' => '20',
                'desc' => 'description is invalid',
                'data' => null,
            ], 200),
        ]);

        $response = $this->payOrder($order, $user);

        $response->assertRedirect(route('student.checkout.pay', $order->order_code));
        $response->assertSessionHas('error', self::FRIENDLY_CHECKOUT_ERROR);
    }

    public function test_missing_data_is_handled_without_a_raw_exception(): void
    {
        [$order, , $user] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response(['code' => '00', 'desc' => 'success'], 200),
        ]);

        $response = $this->payOrder($order, $user);

        $response->assertRedirect(route('student.checkout.pay', $order->order_code));
        $response->assertSessionHas('error', self::FRIENDLY_CHECKOUT_ERROR);
    }

    public function test_missing_checkout_url_is_handled(): void
    {
        [$order, , $user] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response([
                'code' => '00',
                'desc' => 'success',
                'data' => ['status' => 'PENDING'],
            ], 200),
        ]);

        $response = $this->payOrder($order, $user);

        $response->assertRedirect(route('student.checkout.pay', $order->order_code));
        $response->assertSessionHas('error', self::FRIENDLY_CHECKOUT_ERROR);
    }

    public function test_non_https_checkout_url_is_rejected(): void
    {
        [$order, , $user] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response([
                'code' => '00',
                'desc' => 'success',
                'data' => ['checkoutUrl' => 'http://pay.payos.vn/web/test-payment-link'],
            ], 200),
        ]);

        $response = $this->payOrder($order, $user);

        $response->assertRedirect(route('student.checkout.pay', $order->order_code));
        $response->assertSessionHas('error', self::FRIENDLY_CHECKOUT_ERROR);
    }

    public function test_malformed_json_is_handled_safely(): void
    {
        [$order, , $user] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response('{malformed-json', 200),
        ]);

        $response = $this->payOrder($order, $user);

        $response->assertRedirect(route('student.checkout.pay', $order->order_code));
        $response->assertSessionHas('error', self::FRIENDLY_CHECKOUT_ERROR);
    }

    public function test_browser_amount_cannot_override_the_persisted_order_amount(): void
    {
        [$order, , $user] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response([
                'code' => '00',
                'desc' => 'success',
                'data' => ['checkoutUrl' => 'https://pay.payos.vn/web/test-payment-link'],
            ], 200),
        ]);

        $response = $this->payOrder($order, $user, ['amount' => 1]);

        $response->assertRedirect('https://pay.payos.vn/web/test-payment-link');
        Http::assertSent(fn (HttpRequest $request): bool => $request->data()['amount'] === 100000);
    }

    public function test_secrets_are_not_exposed_in_the_student_response(): void
    {
        [$order, , $user] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response(['code' => '01', 'desc' => 'request rejected'], 400),
        ]);

        $response = $this->payOrder($order, $user);
        $content = $response->getContent();

        $this->assertStringNotContainsString('test-client', $content);
        $this->assertStringNotContainsString('test-api-key', $content);
        $this->assertStringNotContainsString('test-checksum-key', $content);
    }

    public function test_missing_payos_configuration_is_a_controlled_failure(): void
    {
        [$order, , $user] = $this->pendingOrder();
        Config::set('services.payos.api_key', null);
        Http::fake();

        $response = $this->payOrder($order, $user);

        $response->assertRedirect(route('student.checkout.pay', $order->order_code));
        $response->assertSessionHas('error', self::FRIENDLY_CHECKOUT_ERROR);
        Http::assertNothingSent();
    }

    public function test_unsigned_payos_webhook_is_rejected(): void
    {
        [$order] = $this->pendingOrder();

        $this->postJson(route('payments.payos.ipn'), [
            'data' => ['orderCode' => $order->id, 'amount' => 100000, 'code' => '00'],
            'signature' => 'forged',
        ])->assertStatus(400);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_payos_webhook_requires_exact_amount_and_completes_matching_order(): void
    {
        [$order, $payment] = $this->pendingOrder();
        $invalidData = ['orderCode' => (int) $payment->gateway_order_code, 'amount' => 1, 'code' => '00', 'reference' => 'PAYOS-REF-1'];

        $this->postJson(route('payments.payos.ipn'), [
            'data' => $invalidData,
            'signature' => $this->signature($invalidData),
        ])->assertStatus(400);
        $this->assertSame('pending', $order->fresh()->status);

        $validData = ['orderCode' => (int) $payment->gateway_order_code, 'amount' => 100000, 'code' => '00', 'reference' => 'PAYOS-REF-1'];
        $this->postJson(route('payments.payos.ipn'), [
            'data' => $validData,
            'signature' => $this->signature($validData),
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('PAYOS-REF-1', $order->fresh()->transaction_id);
    }

    public function test_browser_query_parameters_cannot_mark_order_paid(): void
    {
        [$order] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response(['data' => ['status' => 'PENDING']], 200),
        ]);

        $result = app(PaymentGatewayService::class)->checkAndUpdatePayOSStatus($order);

        $this->assertFalse($result);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_status_sync_rejects_an_http_200_payos_business_error(): void
    {
        [$order] = $this->pendingOrder();
        Http::fake([
            'api-merchant.payos.vn/*' => Http::response([
                'code' => '20',
                'desc' => 'request rejected',
                'data' => ['status' => 'PAID', 'amountPaid' => 100000],
            ], 200),
        ]);

        $result = app(PaymentGatewayService::class)->checkAndUpdatePayOSStatus($order);

        $this->assertFalse($result);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_live_mode_rejects_direct_mock_payment_tools(): void
    {
        Config::set('services.payos.mode', 'live');
        [$order, , $user] = $this->pendingOrder();

        $this->actingAs($user)
            ->get(route('student.checkout.pay', $order->order_code))
            ->assertOk()
            ->assertDontSee('Thanh toán Test');

        $this->actingAs($user)
            ->get(route('student.checkout.mock_gateway', $order->order_code))
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('student.checkout.simulate', $order->order_code), ['status' => 'success'])
            ->assertNotFound();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', $order->payment->fresh()->status);
    }

    public function test_existing_payos_request_reuses_its_checkout_url(): void
    {
        Config::set('services.payos.mode', 'live');
        [$order, , $user] = $this->pendingOrder();
        $checkoutUrl = 'https://pay.payos.vn/web/existing-link';

        Http::fake(function ($request) use ($checkoutUrl) {
            if ($request->method() === 'POST') {
                return Http::response(['code' => '231', 'desc' => 'Mã đơn hàng đã tồn tại', 'data' => null], 200);
            }

            return Http::response(['code' => '00', 'data' => ['checkoutUrl' => $checkoutUrl]], 200);
        });

        $this->actingAs($user)
            ->post(route('student.checkout.process_payment', $order->order_code), ['payment_method' => 'payos'])
            ->assertRedirect($checkoutUrl);
    }

    public function test_terminal_payos_request_keeps_reference_for_reconciliation(): void
    {
        Config::set('services.payos.mode', 'live');
        [$order, $payment, $user] = $this->pendingOrder();
        $oldGatewayOrderCode = $payment->gateway_order_code;
        $checkoutUrl = 'https://pay.payos.vn/web/recreated-link';
        $postCount = 0;

        Http::fake(function ($request) use (&$postCount, $checkoutUrl) {
            if ($request->method() === 'GET') {
                return Http::response(['code' => '00', 'data' => ['status' => 'CANCELLED']], 200);
            }

            $postCount++;

            return $postCount === 1
                ? Http::response(['code' => '231', 'desc' => 'Đơn thanh toán đã tồn tại', 'data' => null], 200)
                : Http::response(['code' => '00', 'data' => ['checkoutUrl' => $checkoutUrl]], 200);
        });

        $this->actingAs($user)
            ->post(route('student.checkout.process_payment', $order->order_code), ['payment_method' => 'payos'])
            ->assertRedirect(route('student.checkout.pay', $order->order_code))
            ->assertSessionHas('error');

        $this->assertSame($oldGatewayOrderCode, $payment->fresh()->gateway_order_code);
        $this->assertLessThanOrEqual(9007199254740991, (int) $payment->fresh()->gateway_order_code);
        $this->assertSame(1, $postCount);
    }

    public function test_payos_error_returns_to_payment_page_instead_of_500(): void
    {
        Config::set('services.payos.mode', 'live');
        [$order, , $user] = $this->pendingOrder();

        Http::fake([
            'api-merchant.payos.vn/*' => Http::response(['code' => '20', 'desc' => 'Thông tin tài khoản không hợp lệ'], 200),
        ]);

        $this->actingAs($user)
            ->post(route('student.checkout.process_payment', $order->order_code), ['payment_method' => 'payos'])
            ->assertRedirect(route('student.checkout.pay', $order->order_code))
            ->assertSessionHas('error', 'PayOS từ chối yêu cầu: Thông tin tài khoản không hợp lệ');
    }

    public function test_removed_payment_gateway_routes_are_not_registered(): void
    {
        $this->get('/payments/momo/callback')->assertNotFound();
        $this->get('/payments/vnpay/return')->assertNotFound();
        $this->post('/payments/vnpay/ipn')->assertNotFound();
    }

    public function test_new_payment_uses_independent_reference_and_reuses_it_on_retry(): void
    {
        [$order, $payment] = $this->pendingOrder();
        $payment->update(['gateway_order_code' => null]);
        Http::fake(['api-merchant.payos.vn/*' => Http::response(['code' => '00', 'data' => ['checkoutUrl' => 'https://pay.payos.vn/web/new-reference']], 200)]);
        $service = app(PaymentGatewayService::class);
        $service->getPaymentUrl($order);
        $reference = $payment->fresh()->gateway_order_code;
        $this->assertNotSame((string) $order->id, $reference);
        $this->assertLessThanOrEqual(9007199254740991, (int) $reference);
        $service->getPaymentUrl($order->fresh());
        $this->assertSame($reference, $payment->fresh()->gateway_order_code);
        Http::assertSentCount(2);
    }

    public function test_legacy_reference_with_confirmed_different_quote_is_replaced_without_marking_paid(): void
    {
        [$order, $payment, $user] = $this->pendingOrder();
        $oldReference = $payment->gateway_order_code;
        Http::fake(function ($request) use ($oldReference) {
            if ($request->method() === 'GET') {
                return Http::response(['code' => '00', 'data' => ['orderCode' => (int) $oldReference, 'amount' => 10000, 'amountPaid' => 10000, 'status' => 'PAID']], 200);
            }
            if ((string) $request['orderCode'] === $oldReference) {
                return Http::response(['code' => '231', 'desc' => 'Đơn thanh toán đã tồn tại'], 200);
            }
            return Http::response(['code' => '00', 'data' => ['checkoutUrl' => 'https://pay.payos.vn/web/recovered']], 200);
        });
        $this->payOrder($order, $user)->assertRedirect('https://pay.payos.vn/web/recovered');
        $this->assertNotSame($oldReference, $payment->fresh()->gateway_order_code);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(100000.0, (float) $payment->fresh()->amount);
        $this->assertSame($oldReference, $payment->fresh()->gateway_response['legacy_reference_collision']['reference']);
        Http::assertSentCount(3);
    }

    public function test_partial_payment_does_not_allow_reference_replacement(): void
    {
        [$order, $payment, $user] = $this->pendingOrder();
        $reference = $payment->gateway_order_code;
        Http::fake(function ($request) use ($reference) {
            return $request->method() === 'POST'
                ? Http::response(['code' => '231'], 200)
                : Http::response(['code' => '00', 'data' => ['orderCode' => (int) $reference, 'amount' => 100000, 'amountPaid' => 10000, 'status' => 'PENDING']], 200);
        });
        $this->payOrder($order, $user)->assertRedirect(route('student.checkout.pay', $order->order_code));
        $this->assertSame($reference, $payment->fresh()->gateway_order_code);
        Http::assertSentCount(2);
    }

    public function test_different_amount_on_unpaid_legacy_link_is_not_replaced_or_reused(): void
    {
        [$order, $payment, $user] = $this->pendingOrder();
        $reference = $payment->gateway_order_code;
        Http::fake(fn ($request) => $request->method() === 'POST'
            ? Http::response(['code' => '231'], 200)
            : Http::response(['code' => '00', 'data' => ['orderCode' => (int) $reference, 'amount' => 10000, 'amountPaid' => 0, 'status' => 'PENDING', 'checkoutUrl' => 'https://pay.payos.vn/web/wrong-amount']], 200));
        $this->payOrder($order, $user)->assertRedirect(route('student.checkout.pay', $order->order_code));
        $this->assertSame($reference, $payment->fresh()->gateway_order_code);
        Http::assertSentCount(2);
    }

    public function test_payos_cancel_return_renders_success_and_updates_order_once(): void
    {
        [$order, $payment, $user] = $this->pendingOrder();
        Http::fake(['api-merchant.payos.vn/*' => Http::response(['code' => '00', 'data' => [
            'orderCode' => (int) $payment->gateway_order_code, 'amount' => 100000, 'amountPaid' => 0, 'status' => 'CANCELLED',
        ]], 200)]);
        $url = route('student.checkout.failed', $order->order_code).'?cancel=true&status=CANCELLED';
        $this->actingAs($user)->get($url)->assertOk()->assertSee('Hủy đơn hàng thành công!');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('failed', $payment->fresh()->status);
        $this->get($url)->assertOk()->assertSee('Hủy đơn hàng thành công!');
        Http::assertSentCount(2);
    }

    public function test_cancel_query_string_does_not_override_remote_pending_or_other_owner(): void
    {
        [$order, $payment, $user] = $this->pendingOrder();
        Http::fake(['api-merchant.payos.vn/*' => Http::response(['code' => '00', 'data' => [
            'orderCode' => (int) $payment->gateway_order_code, 'amount' => 100000, 'amountPaid' => 0, 'status' => 'PENDING',
        ]], 200)]);
        $url = route('student.checkout.failed', $order->order_code).'?cancel=true&status=CANCELLED';
        $this->actingAs($user)->get($url)->assertRedirect(route('student.checkout.pay', $order->order_code));
        $this->assertSame('pending', $order->fresh()->status);
        $other = User::factory()->create(['role' => 'student']);
        $this->actingAs($other)->get($url)->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_cancel_return_never_replaces_an_already_paid_order(): void
    {
        [$order, , $user] = $this->pendingOrder();
        $order->update(['status' => 'paid']);
        Http::fake();
        $this->actingAs($user)->get(route('student.checkout.failed', $order->order_code))
            ->assertRedirect(route('student.checkout.success', $order->order_code));
        $this->assertSame('paid', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_signed_cancel_return_cancels_locally_when_payos_is_unavailable(): void
    {
        [$order, $payment, $user] = $this->pendingOrder();
        Http::fake(['api-merchant.payos.vn/*' => Http::response([], 503)]);
        $url = \Illuminate\Support\Facades\URL::signedRoute('student.checkout.failed', [
            'order_code' => $order->order_code,
            'cancel_reference' => $payment->gateway_order_code,
        ]).'&cancel=true&status=CANCELLED&code=00&orderCode='.$payment->gateway_order_code;
        $this->actingAs($user)->get($url)->assertOk()->assertSee('Hủy đơn hàng thành công!');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('failed', $payment->fresh()->status);
        $this->get($url)->assertOk()->assertSee('Hủy đơn hàng thành công!');
        Http::assertSentCount(2);
    }

    public function test_tampered_cancel_reference_cannot_cancel_an_order(): void
    {
        [$order, $payment, $user] = $this->pendingOrder();
        Http::fake(['api-merchant.payos.vn/*' => Http::response([], 503)]);
        $url = \Illuminate\Support\Facades\URL::signedRoute('student.checkout.failed', [
            'order_code' => $order->order_code,
            'cancel_reference' => $payment->gateway_order_code,
        ]);
        $url = str_replace('cancel_reference='.$payment->gateway_order_code, 'cancel_reference=999999', $url);
        $this->actingAs($user)->get($url)->assertRedirect(route('student.checkout.pay', $order->order_code));
        $this->assertSame('pending', $order->fresh()->status);
    }

    private function pendingOrder(): array
    {
        $user = User::factory()->create(['role' => 'student']);
        $order = Order::create([
            'order_code' => 'ORD-PAYOS-'.strtoupper(fake()->bothify('????####')),
            'user_id' => $user->id,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'total_amount' => 100000,
            'status' => 'pending',
            'payment_method' => 'payos',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway' => 'bank_transfer',
            'gateway_order_code' => (string) $order->id,
            'amount' => 100000,
            'status' => 'pending',
        ]);

        return [$order, $payment, $user];
    }

    private function payOrder(Order $order, User $user, array $extra = [])
    {
        return $this->actingAs($user)->post(
            route('student.checkout.process_payment', $order->order_code),
            ['payment_method' => 'payos'] + $extra
        );
    }

    private function signature(array $data): string
    {
        ksort($data);
        $payload = collect($data)->map(fn ($value, $key) => $key.'='.$value)->implode('&');

        return hash_hmac('sha256', $payload, 'test-checksum-key');
    }
}
