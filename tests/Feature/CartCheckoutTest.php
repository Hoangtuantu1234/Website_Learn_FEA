<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\ContentVersionService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $student;

    protected User $instructor;

    protected Course $course;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Tạo vai trò học viên
        $this->student = User::factory()->create([
            'role' => 'student',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Tạo giảng viên
        $this->instructor = User::factory()->create([
            'role' => 'instructor',
            'is_active' => true,
        ]);

        // Tạo danh mục
        $this->category = Category::create([
            'name' => 'Công nghệ thông tin',
            'slug' => 'cong-nghe-thong-tin',
        ]);

        $profile = $this->instructor->instructorProfile()->create([]);
        $profile->teachingFields()->create(['category_id' => $this->category->id, 'approval_status' => 'approved']);

        // Tạo khóa học mẫu đã xuất bản
        $this->course = Course::create([
            'instructor_id' => $this->instructor->id,
            'category_id' => $this->category->id,
            'title' => 'Lập trình PHP Laravel',
            'slug' => 'lap-trinh-php-laravel',
            'short_description' => 'Mô tả ngắn',
            'description' => 'Mô tả chi tiết',
            'price' => 100000,
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
        ]);
        app(ContentVersionService::class)->createInitialCourseVersion($this->course, $this->instructor);
    }

    /**
     * Test học viên có thể thêm khóa học vào giỏ hàng.
     */
    public function test_student_can_add_course_to_cart(): void
    {
        $response = $this->actingAs($this->student)
            ->post(route('student.cart.add', $this->course));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Đã thêm khóa học vào giỏ hàng thành công!');

        $cart = Cart::where('user_id', $this->student->id)->first();
        $this->assertNotNull($cart);
        $this->assertTrue($cart->courses->contains($this->course->id));
    }

    /**
     * Test học viên không thể thêm trùng khóa học vào giỏ hàng.
     */
    public function test_student_cannot_add_duplicate_course_to_cart(): void
    {
        // Thêm lần 1
        $this->actingAs($this->student)
            ->post(route('student.cart.add', $this->course));

        // Thêm lần 2
        $response = $this->actingAs($this->student)
            ->post(route('student.cart.add', $this->course));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Khóa học đã có sẵn trong giỏ hàng của bạn.');
    }

    /**
     * Test học viên không thể thêm khóa học đã mua/sở hữu vào giỏ hàng.
     */
    public function test_student_cannot_add_owned_course_to_cart(): void
    {
        // Giả lập ghi danh học viên trước
        Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $response = $this->actingAs($this->student)
            ->post(route('student.cart.add', $this->course));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Bạn đã sở hữu và đăng ký khóa học này rồi.');
    }

    /**
     * Test học viên có thể xóa khóa học khỏi giỏ hàng.
     */
    public function test_student_can_remove_course_from_cart(): void
    {
        // Thêm khóa học vào giỏ hàng trước
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach($this->course->id);

        $response = $this->actingAs($this->student)
            ->delete(route('student.cart.remove', $this->course->id));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Đã xóa khóa học khỏi giỏ hàng.');

        $cart->refresh();
        $this->assertFalse($cart->courses->contains($this->course->id));
    }

    /**
     * Test học viên có thể checkout tạo đơn hàng ở trạng thái pending.
     */
    public function test_student_can_checkout_creating_pending_order(): void
    {
        // Thêm vào giỏ hàng
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach($this->course->id);

        $response = $this->actingAs($this->student)
            ->post(route('student.cart.checkout'), [
                'payment_method' => 'bank_transfer',
                'course_ids' => [$this->course->id],
            ]);

        // Sẽ chuyển hướng đến trang thanh toán
        $order = Order::where('user_id', $this->student->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals('bank_transfer', $order->payment_method);
        $this->assertEquals(100000, $order->total_amount);

        $response->assertRedirect(route('student.checkout.pay', $order->order_code));

        // Kiểm tra Payment cũng được tạo ở trạng thái pending
        $payment = Payment::where('order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals('pending', $payment->status);
    }

    public function test_repeated_checkout_with_same_idempotency_key_reuses_order(): void
    {
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach($this->course->id);
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'payment_method' => 'bank_transfer',
            'course_ids' => [$this->course->id],
        ];

        $first = $this->actingAs($this->student)->post(route('student.cart.checkout'), $payload);
        $second = $this->actingAs($this->student)->post(route('student.cart.checkout'), $payload);

        $order = Order::where('user_id', $this->student->id)->where('idempotency_key', $key)->sole();
        $first->assertRedirect(route('student.checkout.pay', $order->order_code));
        $second->assertRedirect(route('student.checkout.pay', $order->order_code));
        $this->assertSame(1, Order::where('idempotency_key', $key)->count());
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    /**
     * Test học viên có thể giả lập thanh toán thành công.
     */
    public function test_student_can_simulate_successful_payment(): void
    {
        // Thêm vào giỏ hàng và checkout tạo đơn hàng trước
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach($this->course->id);

        $this->actingAs($this->student)
            ->post(route('student.cart.checkout'), [
                'payment_method' => 'bank_transfer',
                'course_ids' => [$this->course->id],
            ]);

        $order = Order::where('user_id', $this->student->id)->first();

        $this->actingAs($this->student)
            ->get(route('student.checkout.pay', $order->order_code))
            ->assertOk()
            ->assertSee('Thanh toán Test')
            ->assertSee('data-test-payment-button', false);

        // Giả lập gửi thông tin thanh toán thành công
        $response = $this->actingAs($this->student)
            ->post(route('student.checkout.simulate', $order->order_code), [
                'status' => 'success',
            ]);

        $response->assertRedirect(route('student.checkout.success', $order->order_code));

        $order->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertNotNull($order->transaction_id);

        $payment = Payment::where('order_id', $order->id)->first();
        $this->assertEquals('success', $payment->status);
        $this->assertNotNull($payment->paid_at);

        // Đảm bảo học viên đã được ghi danh
        $enrollment = Enrollment::where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->first();
        $this->assertNotNull($enrollment);
        $this->assertEquals('active', $enrollment->status);

        // Đảm bảo giảng viên nhận được thông báo học viên mới mua khóa học
        $this->assertDatabaseHas('push_notifications', [
            'user_id' => $this->instructor->id,
            'type' => 'new_enrollment',
        ]);

        // Giỏ hàng phải được làm sạch các khóa học đã mua
        $cart->refresh();
        $this->assertFalse($cart->courses->contains($this->course->id));
    }

    /**
     * Test học viên giả lập thanh toán thất bại.
     */
    public function test_student_can_simulate_failed_payment(): void
    {
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach($this->course->id);

        $this->actingAs($this->student)
            ->post(route('student.cart.checkout'), [
                'payment_method' => 'bank_transfer',
                'course_ids' => [$this->course->id],
            ]);

        $order = Order::where('user_id', $this->student->id)->first();

        $response = $this->actingAs($this->student)
            ->post(route('student.checkout.simulate', $order->order_code), [
                'status' => 'failed',
            ]);

        $response->assertRedirect(route('student.checkout.failed', $order->order_code));

        $order->refresh();
        $this->assertEquals('failed', $order->status);

        $payment = Payment::where('order_id', $order->id)->first();
        $this->assertEquals('failed', $payment->status);

        // Học viên không được ghi danh
        $enrollment = Enrollment::where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->first();
        $this->assertNull($enrollment);
    }

    public function test_payment_simulation_is_blocked_in_production(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $order = Order::create([
            'order_code' => 'ORD-PRODUCTION-GUARD',
            'user_id' => $this->student->id,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'total_amount' => 100000,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'items' => [[
                'course_id' => $this->course->id,
                'title' => $this->course->title,
                'price' => 100000,
            ]],
        ]);

        $originalEnvironment = app()->environment();

        try {
            app()->detectEnvironment(fn () => 'production');

            $this->actingAs($this->student)
                ->post(route('student.checkout.simulate', $order->order_code), ['status' => 'success'])
                ->assertNotFound();
        } finally {
            app()->detectEnvironment(fn () => $originalEnvironment);
        }

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
        ]);
    }

    public function test_repeated_success_callback_is_idempotent(): void
    {
        $coupon = Coupon::create([
            'code' => 'IDEMPOTENT',
            'type' => 'fixed',
            'value' => 10000,
            'min_order_amount' => 0,
            'max_uses' => 5,
            'used_count' => 0,
            'is_active' => true,
        ]);
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach($this->course->id);

        $this->actingAs($this->student)->post(route('student.cart.checkout'), [
            'payment_method' => 'bank_transfer',
            'coupon_code' => $coupon->code,
            'course_ids' => [$this->course->id],
        ]);

        $order = Order::where('user_id', $this->student->id)->firstOrFail();

        $this->actingAs($this->student)->post(
            route('student.checkout.simulate', $order->order_code),
            ['status' => 'success']
        )->assertRedirect(route('student.checkout.success', $order->order_code));

        $this->actingAs($this->student)->post(
            route('student.checkout.simulate', $order->order_code),
            ['status' => 'success']
        )->assertRedirect(route('student.checkout.success', $order->order_code));

        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1, Enrollment::where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->count());
    }

    public function test_coupon_limit_is_rechecked_when_payment_completes(): void
    {
        $coupon = Coupon::create([
            'code' => 'LASTUSE',
            'type' => 'fixed',
            'value' => 10000,
            'min_order_amount' => 0,
            'max_uses' => 1,
            'used_count' => 0,
            'is_active' => true,
        ]);
        $secondStudent = User::factory()->create([
            'role' => 'student',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        foreach ([$this->student, $secondStudent] as $student) {
            Cart::firstOrCreate(['user_id' => $student->id])
                ->courses()
                ->attach($this->course->id);

            $this->actingAs($student)->post(route('student.cart.checkout'), [
                'payment_method' => 'bank_transfer',
                'coupon_code' => $coupon->code,
                'course_ids' => [$this->course->id],
            ]);
        }

        $firstOrder = Order::where('user_id', $this->student->id)->firstOrFail();
        $secondOrder = Order::where('user_id', $secondStudent->id)->firstOrFail();

        $this->actingAs($this->student)->post(
            route('student.checkout.simulate', $firstOrder->order_code),
            ['status' => 'success']
        )->assertRedirect(route('student.checkout.success', $firstOrder->order_code));

        $this->actingAs($secondStudent)->post(
            route('student.checkout.simulate', $secondOrder->order_code),
            ['status' => 'success']
        )->assertRedirect(route('student.checkout.failed', $secondOrder->order_code));

        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame('paid', $firstOrder->fresh()->status);
        $this->assertSame('failed', $secondOrder->fresh()->status);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $secondStudent->id,
            'course_id' => $this->course->id,
        ]);
    }

    public function test_free_checkout_does_not_exceed_coupon_limit(): void
    {
        $coupon = Coupon::create([
            'code' => 'FREEONCE',
            'type' => 'percent',
            'value' => 100,
            'min_order_amount' => 0,
            'max_uses' => 1,
            'used_count' => 0,
            'is_active' => true,
        ]);
        $secondStudent = User::factory()->create([
            'role' => 'student',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        foreach ([$this->student, $secondStudent] as $student) {
            Cart::firstOrCreate(['user_id' => $student->id])
                ->courses()
                ->attach($this->course->id);

            $this->actingAs($student)->post(route('student.cart.checkout'), [
                'payment_method' => 'bank_transfer',
                'coupon_code' => $coupon->code,
                'course_ids' => [$this->course->id],
            ]);
        }

        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1, Order::where('coupon_id', $coupon->id)->where('status', 'paid')->count());
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $secondStudent->id,
            'course_id' => $this->course->id,
        ]);
    }

    /**
     * Test học viên có thể xem trang thanh toán thất bại.
     */
    public function test_student_can_view_failed_page(): void
    {
        // Tạo đơn hàng ở trạng thái failed
        $order = Order::create([
            'order_code' => 'ORD-FAILED',
            'user_id' => $this->student->id,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'total_amount' => 100000,
            'status' => 'failed',
            'payment_method' => 'bank_transfer',
            'items' => [
                [
                    'course_id' => $this->course->id,
                    'title' => $this->course->title,
                    'price' => 100000,
                ],
            ],
        ]);

        $response = $this->actingAs($this->student)
            ->get(route('student.checkout.failed', $order->order_code));

        $response->assertStatus(200);
        $response->assertSee('Thanh toán không thành công!');
        $response->assertSee($order->order_code);
    }

    /**
     * Test học viên tích chọn một số khóa học để thanh toán, khóa học còn lại giữ nguyên trong giỏ.
     */
    public function test_student_can_checkout_partial_cart_items(): void
    {
        // Tạo thêm khóa học thứ hai
        $course2 = Course::create([
            'instructor_id' => $this->instructor->id,
            'category_id' => $this->category->id,
            'title' => 'Lập trình Vue.js',
            'slug' => 'lap-trinh-vue-js',
            'short_description' => 'Mô tả ngắn Vue',
            'description' => 'Mô tả chi tiết Vue',
            'price' => 150000,
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
        ]);

        // Thêm cả 2 khóa học vào giỏ hàng
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach([$this->course->id, $course2->id]);

        // Học viên tiến hành checkout chỉ tích chọn khóa học thứ nhất
        $response = $this->actingAs($this->student)
            ->post(route('student.cart.checkout'), [
                'payment_method' => 'bank_transfer',
                'course_ids' => [$this->course->id],
            ]);

        $order = Order::where('user_id', $this->student->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals(100000, $order->total_amount); // Chỉ tính tiền khóa học thứ nhất

        // Giả lập thanh toán thành công
        $this->actingAs($this->student)
            ->post(route('student.checkout.simulate', $order->order_code), [
                'status' => 'success',
            ]);

        // Khóa học thứ nhất đã được ghi danh
        $enrollment = Enrollment::where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->first();
        $this->assertNotNull($enrollment);

        // Khóa học thứ hai KHÔNG được ghi danh
        $enrollment2 = Enrollment::where('user_id', $this->student->id)
            ->where('course_id', $course2->id)
            ->first();
        $this->assertNull($enrollment2);

        // Khóa học thứ nhất phải biến mất khỏi giỏ hàng
        $cart->refresh();
        $this->assertFalse($cart->courses->contains($this->course->id));

        // Khóa học thứ hai VẪN PHẢI NẰM trong giỏ hàng
        $this->assertTrue($cart->courses->contains($course2->id));
    }

    /**
     * Test học viên không thể sử dụng lại mã giảm giá đã thanh toán thành công trước đó.
     */
    public function test_student_cannot_reuse_coupon_already_used(): void
    {
        // 1. Tạo một coupon mẫu
        $coupon = Coupon::create([
            'code' => 'TESTVOUCHER',
            'type' => 'fixed',
            'value' => 20000,
            'min_order_amount' => 50000,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);

        // 2. Tạo một đơn hàng đã thanh toán thành công sử dụng coupon này
        Order::create([
            'order_code' => 'ORD-OLD-PAID',
            'user_id' => $this->student->id,
            'coupon_id' => $coupon->id,
            'subtotal' => 100000,
            'discount_amount' => 20000,
            'total_amount' => 80000,
            'status' => 'paid',
            'payment_method' => 'bank_transfer',
            'items' => [
                [
                    'course_id' => $this->course->id,
                    'title' => $this->course->title,
                    'price' => 100000,
                ],
            ],
        ]);

        // 3. Thêm khóa học vào giỏ hàng và thử áp dụng lại coupon vừa sử dụng
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach($this->course->id);

        // Thử áp dụng bằng AJAX (applyCoupon)
        $responseAjax = $this->actingAs($this->student)
            ->post(route('student.cart.coupon.apply'), [
                'coupon_code' => 'TESTVOUCHER',
                'course_ids' => [$this->course->id],
            ]);

        $responseAjax->assertJson([
            'success' => false,
            'message' => 'Bạn đã sử dụng mã giảm giá này cho một đơn hàng trước đó.',
        ]);

        // Thử thực hiện checkout với mã giảm giá đó
        $responseCheckout = $this->actingAs($this->student)
            ->post(route('student.cart.checkout'), [
                'payment_method' => 'bank_transfer',
                'coupon_code' => 'TESTVOUCHER',
                'course_ids' => [$this->course->id],
            ]);

        $responseCheckout->assertRedirect();
        $responseCheckout->assertSessionHas('error', 'Bạn đã sử dụng mã giảm giá này cho một đơn hàng trước đó.');
    }

    public function test_used_coupon_is_hidden_from_the_students_next_payment_page(): void
    {
        $usedCoupon = Coupon::create([
            'code' => 'USED-ONCE',
            'type' => 'fixed',
            'value' => 20000,
            'min_order_amount' => 0,
            'is_active' => true,
        ]);
        $availableCoupon = Coupon::create([
            'code' => 'STILL-AVAILABLE',
            'type' => 'fixed',
            'value' => 10000,
            'min_order_amount' => 0,
            'is_active' => true,
        ]);

        Order::create([
            'order_code' => 'ORD-USED-COUPON',
            'user_id' => $this->student->id,
            'coupon_id' => $usedCoupon->id,
            'subtotal' => 100000,
            'discount_amount' => 20000,
            'total_amount' => 80000,
            'status' => 'paid',
            'payment_method' => 'bank_transfer',
        ]);
        $pendingOrder = Order::create([
            'order_code' => 'ORD-NEXT-PAYMENT',
            'user_id' => $this->student->id,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'total_amount' => 100000,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
        ]);

        $response = $this->actingAs($this->student)
            ->get(route('student.checkout.pay', $pendingOrder->order_code));

        $response->assertOk()
            ->assertViewHas('activeCoupons', function ($coupons) use ($usedCoupon, $availableCoupon): bool {
                return ! $coupons->contains('id', $usedCoupon->id)
                    && $coupons->contains('id', $availableCoupon->id);
            })
            ->assertDontSee('USED-ONCE')
            ->assertSee('STILL-AVAILABLE');
    }

    /**
     * Test học viên có thể mua ngay một khóa học (Buy Now) tạo đơn hàng pending và chuyển sang trang thanh toán.
     */
    public function test_student_can_buy_now_course(): void
    {
        $response = $this->actingAs($this->student)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('student.cart.buy_now', $this->course->id));

        $order = Order::where('user_id', $this->student->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(100000, $order->total_amount);

        $response->assertRedirect(route('student.checkout.pay', $order->order_code));
    }

    /**
     * Test học viên có thể xóa sản phẩm khỏi giỏ hàng qua AJAX.
     */
    public function test_student_can_remove_course_via_ajax(): void
    {
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach($this->course->id);

        $response = $this->actingAs($this->student)
            ->delete(route('student.cart.remove_ajax', $this->course->id));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'cart_count' => 0,
        ]);

        $cart->refresh();
        $this->assertFalse($cart->courses->contains($this->course->id));
    }

    /**
     * Test học viên có thể chuyển khóa học từ giỏ hàng sang danh sách yêu thích.
     */
    public function test_student_can_move_course_from_cart_to_wishlist(): void
    {
        $cart = Cart::firstOrCreate(['user_id' => $this->student->id]);
        $cart->courses()->attach($this->course->id);

        $response = $this->actingAs($this->student)
            ->post(route('student.cart.move_to_wishlist', $this->course->id), [], ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Đã chuyển khóa học sang danh sách yêu thích.',
        ]);

        $cart->refresh();
        $this->assertFalse($cart->courses->contains($this->course->id));
        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
        ]);
    }

    /**
     * Test học viên có thể kiểm tra trạng thái đơn hàng qua endpoint JSON.
     */
    public function test_student_can_check_order_status(): void
    {
        $order = Order::create([
            'order_code' => 'ORD-STATUS-TEST',
            'user_id' => $this->student->id,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'total_amount' => 100000,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'items' => [],
        ]);

        $response = $this->actingAs($this->student)
            ->get(route('student.checkout.status', $order->order_code));

        $response->assertOk();
        $response->assertJson([
            'status' => 'pending',
            'order_code' => 'ORD-STATUS-TEST',
        ]);
    }

    /**
     * Test học viên có thể áp dụng mã giảm giá trực tiếp trên trang thanh toán / đơn hàng Buy Now pending.
     */
    public function test_student_can_apply_coupon_to_pending_order_or_buy_now(): void
    {
        $coupon = Coupon::create([
            'code' => 'BUYNOW20',
            'type' => 'percent',
            'value' => 20,
            'min_order_amount' => 50000,
            'is_active' => true,
        ]);

        // Mua ngay khóa học
        $this->actingAs($this->student)
            ->post(route('student.cart.buy_now', $this->course->id));

        $order = Order::where('user_id', $this->student->id)->latest()->first();

        // Áp dụng coupon trực tiếp tại trang thanh toán
        $response = $this->actingAs($this->student)
            ->post(route('student.checkout.apply_coupon', $order->order_code), [
                'coupon_code' => 'BUYNOW20',
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'discount_amount' => 20000,
            'new_total' => 80000,
        ]);

        $order->refresh();
        $this->assertEquals(20000, $order->discount_amount);
        $this->assertEquals(80000, $order->total_amount);
        $this->assertEquals($coupon->id, $order->coupon_id);
    }

    /**
     * Test học viên có thể gỡ mã giảm giá khỏi đơn hàng pending.
     */
    public function test_student_can_remove_coupon_from_pending_order(): void
    {
        $coupon = Coupon::create([
            'code' => 'REMOVEME',
            'type' => 'fixed',
            'value' => 10000,
            'min_order_amount' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->student)
            ->post(route('student.cart.buy_now', $this->course->id));

        $order = Order::where('user_id', $this->student->id)->latest()->first();

        $this->actingAs($this->student)
            ->post(route('student.checkout.apply_coupon', $order->order_code), ['coupon_code' => 'REMOVEME']);

        $response = $this->actingAs($this->student)
            ->delete(route('student.checkout.remove_coupon', $order->order_code));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'new_total' => 100000,
        ]);

        $order->refresh();
        $this->assertNull($order->coupon_id);
        $this->assertEquals(0, $order->discount_amount);
        $this->assertEquals(100000, $order->total_amount);
    }
}
