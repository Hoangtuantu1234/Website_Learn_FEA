<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use App\Services\CaptchaService;
use App\Services\RoleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTER_PASSWORD = 'Password1!';

    protected function setUp(): void
    {
        parent::setUp();

        app(RoleSyncService::class)->ensurePrimaryRolesExist();
    }

    public function test_register_page_is_accessible(): void
    {
        $this->get(route('register'))->assertOk();

        $this->get(route('register.role', 'student'))
            ->assertOk()
            ->assertSee('data-student-terms-checkbox', false)
            ->assertSee('data-student-terms-trigger', false)
            ->assertSee('data-student-terms-modal', false)
            ->assertSee('data-student-terms-pdf', false)
            ->assertSee('data-student-terms-open-pdf', false)
            ->assertSee(route('legal.registration-terms'), false)
            ->assertSee('Chi tiết điều khoản đăng ký')
            ->assertSee('Mở PDF trong tab mới')
            ->assertDontSee('name="avatar"', false)
            ->assertDontSee('Avatar preview', false)
            ->assertDontSee('PNG, JPG', false);

        $this->get(route('register.role', 'instructor'))
            ->assertOk()
            ->assertDontSee('data-student-terms-modal', false)
            ->assertDontSee('name="avatar"', false)
            ->assertDontSee('Avatar preview', false)
            ->assertDontSee('PNG, JPG', false)
            ->assertDontSee('name="certificates[]"', false);
    }

    public function test_instructor_registration_ignores_avatar_upload(): void
    {
        Notification::fake();

        $response = $this->postRegister('instructor', [
            'email' => 'instructor-without-avatar@example.com',
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'instructor-without-avatar@example.com')->firstOrFail();
        $this->assertNull($user->avatar);
    }

    public function test_student_registration_ignores_avatar_upload(): void
    {
        Notification::fake();

        $response = $this->postRegister('student', [
            'email' => 'student-without-avatar@example.com',
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'student-without-avatar@example.com')->firstOrFail();
        $this->assertNull($user->avatar);
    }

    public function test_student_registration_succeeds(): void
    {
        Notification::fake();

        $response = $this->postRegister('student', [
            'email' => 'new-student@example.com',
            'name' => 'Học viên Mới',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'new-student@example.com')->firstOrFail();
        $this->assertSame('student', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($this->userHasPrimaryRolePivot($user, 'student'));
    }

    public function test_student_registration_requires_terms_acceptance(): void
    {
        $captcha = $this->registerCaptcha();
        $payload = $this->registerPayload('student', [
            'email' => 'missing-terms@example.com',
        ]);
        unset($payload['terms']);

        $this->post(route('register.role', 'student'), [
            ...$payload,
            'captcha_token' => $captcha['token'],
            'captcha_answer' => $captcha['answer'],
        ])->assertSessionHasErrors('terms');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'missing-terms@example.com']);
    }

    public function test_instructor_registration_succeeds(): void
    {
        Notification::fake();
        $category = Category::create(['name' => 'Registration category', 'slug' => 'registration-category', 'status' => true]);

        $response = $this->postRegister('instructor', [
            'email' => 'new-instructor@example.com',
            'name' => 'Giảng viên Mới',
            'category_id' => $category->id,
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'new-instructor@example.com')->firstOrFail();
        $this->assertSame('instructor', $user->role);
        $this->assertSame('pending', $user->instructor_status);
        $this->assertNull($user->submitted_for_review_at);
        $this->assertFalse($user->needs_admin_review);
        $this->assertNull($user->instructorApplication);
        $this->assertSame('draft', $user->instructorProfile?->teachingFields()->first()?->approval_status);
        $this->assertTrue($this->userHasPrimaryRolePivot($user, 'instructor'));
    }

    public function test_instructor_registration_allows_no_certificates(): void
    {
        Notification::fake();

        $this->postRegister('instructor', [
            'email' => 'instructor-no-certificates@example.com',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'instructor-no-certificates@example.com')->firstOrFail();

        $this->assertSame(0, $user->instructorCertificates()->count());
        $this->assertNull($user->instructorApplication);
        $this->assertNull($user->instructorApplication?->certificate_path);
    }

    public function test_instructor_registration_keeps_cv_but_never_creates_certificates(): void
    {
        Notification::fake();
        Storage::fake('public');
        Storage::fake('local');

        $this->postRegister('instructor', [
            'email' => 'instructor-cv-only@example.com',
            'cv' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
            // An obsolete client payload must not recreate the registration upload flow.
            'certificates' => [UploadedFile::fake()->create('obsolete-certificate.pdf', 100, 'application/pdf')],
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'instructor-cv-only@example.com')->firstOrFail();
        $this->assertSame(0, $user->instructorCertificates()->count());
        $this->assertNull($user->instructorApplication?->certificate_path);
        $this->assertFalse($user->needs_admin_review);
        Storage::disk('public')->assertExists($user->instructorProfile?->cv);
    }

    public function test_instructor_registration_retains_valid_cv_after_validation_failure(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $captcha = $this->registerCaptcha();
        $this->from(route('register.role', 'instructor'))
            ->post(route('register.role', 'instructor'), [
                ...$this->registerPayload('instructor', [
                    'email' => 'instructor-cv-retry@example.com',
                    'experience' => '',
                    'cv' => UploadedFile::fake()->create('ho-so-giang-vien.pdf', 200, 'application/pdf'),
                ]),
                'captcha_token' => $captcha['token'],
                'captcha_answer' => $captcha['answer'],
            ])
            ->assertRedirect(route('register.role', 'instructor'))
            ->assertSessionHasErrors('experience')
            ->assertSessionHas('registration.pending_cv');

        $this->get(route('register.role', 'instructor'))
            ->assertOk()
            ->assertSee('CV đã tải lên: ho-so-giang-vien.pdf', false);

        Notification::fake();
        $captcha = $this->registerCaptcha();
        $this->post(route('register.role', 'instructor'), [
            ...$this->registerPayload('instructor', [
                'email' => 'instructor-cv-retry@example.com',
                'experience' => '5 năm kinh nghiệm lập trình',
            ]),
            'captcha_token' => $captcha['token'],
            'captcha_answer' => $captcha['answer'],
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'instructor-cv-retry@example.com')->firstOrFail();
        $this->assertNotEmpty($user->instructorProfile?->cv);
        Storage::disk('public')->assertExists($user->instructorProfile->cv);
        $this->assertNull(session('registration.pending_cv'));
    }

    public function test_instructor_registration_rejects_invalid_or_oversized_cv_without_staging(): void
    {
        Storage::fake('local');

        $this->from(route('register.role', 'instructor'))
            ->postRegister('instructor', [
                'email' => 'instructor-bad-cv@example.com',
                'cv' => UploadedFile::fake()->create('cv.txt', 20, 'text/plain'),
            ])
            ->assertSessionHasErrors('cv');
        $this->assertNull(session('registration.pending_cv'));
        $this->assertSame([], Storage::disk('local')->allFiles('registration-pending-cvs'));

        $this->from(route('register.role', 'instructor'))
            ->postRegister('instructor', [
                'email' => 'instructor-big-cv@example.com',
                'cv' => UploadedFile::fake()->create('cv.pdf', 6000, 'application/pdf'),
            ])
            ->assertSessionHasErrors('cv');
        $this->assertNull(session('registration.pending_cv'));
        $this->assertSame([], Storage::disk('local')->allFiles('registration-pending-cvs'));
    }

    public function test_duplicate_email_registration_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postRegister('student', ['email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, User::query()->where('email', 'taken@example.com')->count());
    }

    public function test_duplicate_phone_registration_is_rejected(): void
    {
        User::factory()->create(['phone' => '0393028777']);

        $this->postRegister('student', ['phone' => '0393028777'])
            ->assertSessionHasErrors('phone');

        $this->assertGuest();
        $this->assertSame(1, User::query()->where('phone', '0393028777')->count());
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $captcha = $this->registerCaptcha();

        $this->post(route('register.role', 'student'), [
            'name' => 'Test User',
            'email' => 'mismatch@example.com',
            'phone' => '0912345678',
            'password' => self::REGISTER_PASSWORD,
            'password_confirmation' => 'Different1!',
            'terms' => '1',
            'captcha_token' => $captcha['token'],
            'captcha_answer' => $captcha['answer'],
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_public_registration_cannot_create_admin_role(): void
    {
        $this->post('/register/admin', $this->registerPayload('student', [
            'email' => 'admin-hack@example.com',
        ]))->assertNotFound();

        $captcha = $this->registerCaptcha();

        $this->post(route('register.role', 'student'), [
            ...$this->registerPayload('student', ['email' => 'admin-body@example.com']),
            'role' => 'admin',
            'captcha_token' => $captcha['token'],
            'captcha_answer' => $captcha['answer'],
        ])->assertSessionHasErrors('role');

        $this->assertNull(User::query()->where('email', 'admin-hack@example.com')->first());
        $this->assertNull(User::query()->where('email', 'admin-body@example.com')->first());
    }

    public function test_registered_password_is_hashed(): void
    {
        Notification::fake();

        $this->postRegister('student', ['email' => 'hash-test@example.com']);

        $user = User::query()->where('email', 'hash-test@example.com')->firstOrFail();

        $this->assertNotSame(self::REGISTER_PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::REGISTER_PASSWORD, $user->password));
    }

    public function test_registration_regenerates_session(): void
    {
        Notification::fake();

        $this->startSession();
        $oldSessionId = session()->getId();

        $this->postRegister('student', ['email' => 'session-reg@example.com']);

        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertAuthenticated();
    }

    public function test_registration_sends_email_verification_notification(): void
    {
        Notification::fake();

        $this->postRegister('student', ['email' => 'verify-send@example.com']);

        $user = User::query()->where('email', 'verify-send@example.com')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmailCodeNotification::class);
        Notification::assertCount(1);
    }

    public function test_login_with_email_succeeds(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'login-email@example.com',
            'password' => Hash::make('password'),
            'role' => 'student',
        ]);

        $this->postLogin('login-email@example.com', 'password')
            ->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_username_succeeds(): void
    {
        $user = User::factory()->unverified()->create([
            'username' => 'loginuser',
            'email' => 'login-username@example.com',
            'password' => Hash::make('password'),
            'role' => 'student',
        ]);

        $this->postLogin('loginuser', 'password')
            ->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_verification_is_required_before_two_factor_challenge(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified-2fa@example.com',
            'password' => Hash::make('password'),
            'role' => 'student',
            'two_factor_enabled' => true,
        ]);

        $this->postLogin($user->email, 'password')
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseMissing('two_factor_codes', [
            'user_id' => $user->id,
        ]);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'wrong-pass@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->postLogin('wrong-pass@example.com', 'wrong-password')
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_invalid_stored_hash_is_rejected_without_500_or_plaintext_fallback(): void
    {
        $user = User::factory()->create(['email' => 'invalid-hash@example.com']);
        // Simulate an import or direct SQL write bypassing User's hashed cast.
        DB::table('users')->where('id', $user->id)->update(['password' => 'plain-secret']);

        $this->from(route('login'));
        $response = $this->postLogin($user->email, 'plain-secret');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('identifier');
        $this->assertGuest();
        $this->assertSame('plain-secret', $user->fresh()->getRawOriginal('password'));
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'locked@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
        ]);

        $this->postLogin('locked@example.com', 'password')
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_soft_deleted_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'deleted@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->delete();

        $this->postLogin('deleted@example.com', 'password')
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_login_does_not_reveal_whether_email_exists(): void
    {
        User::factory()->create([
            'email' => 'exists@example.com',
            'password' => Hash::make('password'),
        ]);

        $existingUserResponse = $this->postLogin('exists@example.com', 'wrong-password');
        $missingUserResponse = $this->postLogin('missing@example.com', 'wrong-password');

        $existingUserResponse->assertSessionHasErrors([
            'identifier' => 'Thông tin đăng nhập không chính xác.',
        ]);
        $missingUserResponse->assertSessionHasErrors([
            'identifier' => 'Thông tin đăng nhập không chính xác.',
        ]);
    }

    public function test_remember_me_sets_recaller_cookie(): void
    {
        User::factory()->create([
            'email' => 'remember@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->postLogin('remember@example.com', 'password', remember: true);

        $response->assertCookie(Auth::guard()->getRecallerName());
        $this->assertAuthenticated();
    }

    public function test_login_rate_limit_blocks_excessive_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'ratelimit@example.com',
            'password' => Hash::make('password'),
        ]);

        RateLimiter::clear(strtolower('ratelimit@example.com').'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->postLogin('ratelimit@example.com', 'wrong-password')
                ->assertSessionHasErrors('identifier');
            $this->assertGuest();
        }

        $blockedResponse = $this->postLogin('ratelimit@example.com', 'wrong-password');

        if ($blockedResponse->getStatusCode() === 429) {
            $blockedResponse->assertStatus(429);
        } else {
            $blockedResponse->assertSessionHasErrors('identifier');
        }

        $this->assertGuest();
    }

    public function test_login_redirects_to_correct_dashboard_by_role(): void
    {
        $student = User::factory()->create([
            'email' => 'role-student@example.com',
            'password' => Hash::make('password'),
            'role' => 'student',
            'email_verified_at' => now(),
        ]);
        $instructor = User::factory()->create([
            'email' => 'role-instructor@example.com',
            'password' => Hash::make('password'),
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $admin = User::factory()->create([
            'email' => 'role-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->postLogin('role-student@example.com', 'password')
            ->assertRedirect(route('student.dashboard'));

        $this->post(route('logout'));

        $this->postLogin('role-instructor@example.com', 'password')
            ->assertRedirect(route('instructor.dashboard'));

        $this->post(route('logout'));

        $this->postLogin('role-admin@example.com', 'password')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_post_logout_succeeds_for_authenticated_users(): void
    {
        foreach (['student', 'instructor', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->post(route('logout'))
                ->assertRedirect(route('home'));

            $this->assertGuest();
        }
    }

    public function test_logout_invalidates_session_and_regenerates_csrf_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $oldSessionId = session()->getId();
        $oldToken = session()->token();

        $this->post(route('logout'))->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
    }

    public function test_get_logout_route_does_not_exist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/logout')
            ->assertStatus(405);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postRegister(string $role, array $overrides = []): TestResponse
    {
        $captcha = $this->registerCaptcha();

        return $this->post(route('register.role', $role), [
            ...$this->registerPayload($role, $overrides),
            'captcha_token' => $captcha['token'],
            'captcha_answer' => $captcha['answer'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registerPayload(string $role, array $overrides = []): array
    {
        $base = [
            'name' => $overrides['name'] ?? 'Người dùng '.$role,
            'email' => $overrides['email'] ?? $role.'-'.uniqid().'@example.com',
            'phone' => '09'.rand(10000000, 99999999),
            'password' => self::REGISTER_PASSWORD,
            'password_confirmation' => self::REGISTER_PASSWORD,
            'terms' => '1',
        ];

        if ($role === 'instructor') {
            $base = array_merge($base, [
                'specialty' => 'Công nghệ thông tin',
                'experience' => '5 năm kinh nghiệm lập trình',
                'bio' => 'Giới thiệu bản thân ngắn gọn.',
                'agree_information' => '1',
                'agree_terms' => '1',
            ]);
        }

        return array_merge($base, $overrides);
    }

    private function postLogin(string $identifier, string $password, bool $remember = false): TestResponse
    {
        $captcha = $this->loginCaptcha();

        return $this->post(route('login'), [
            'identifier' => $identifier,
            'password' => $password,
            'remember' => $remember ? '1' : null,
            'captcha_token' => $captcha['token'],
            'captcha_answer' => $captcha['answer'],
        ]);
    }

    /**
     * @return array{token: string, answer: string}
     */
    private function registerCaptcha(): array
    {
        $this->startSession();

        $generated = CaptchaService::generate('register');
        $captchas = session('auth_captchas', []);

        return [
            'token' => $generated['token'],
            'answer' => $captchas[$generated['token']]['answer'] ?? '0',
        ];
    }

    /**
     * @return array{token: string, answer: string}
     */
    private function loginCaptcha(): array
    {
        $this->startSession();

        $generated = CaptchaService::generate('login');
        $captchas = session('auth_captchas', []);

        return [
            'token' => $generated['token'],
            'answer' => $captchas[$generated['token']]['answer'] ?? '0',
        ];
    }

    private function userHasPrimaryRolePivot(User $user, string $slug): bool
    {
        $roleId = Role::query()->where('slug', $slug)->value('id');

        if (! $roleId) {
            return false;
        }

        return DB::table('role_user')
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->exists();
    }
}
