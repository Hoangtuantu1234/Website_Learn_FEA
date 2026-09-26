<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterInstructorRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Models\Category;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AuthService;
use App\Services\CaptchaService;
use App\Services\RegistrationPendingCvService;
use App\Services\DatabaseSessionInvalidator;
use App\Services\EmailVerificationService;
use App\Services\TwoFactorService;
use App\Support\MailErrorFormatter;
use App\Support\SensitiveData;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AuthController extends Controller
{
    public function showLogin(Request $request): View
    {
        $redirect = $request->query('redirect');

        if (is_string($redirect) && $this->isSafeRedirect($redirect)) {
            $request->session()->put('url.intended', $redirect);
        }

        return view('auth.login', [
            'captcha' => CaptchaService::generate('login'),
        ]);
    }

    public function login(LoginRequest $request, AuthService $authService, TwoFactorService $twoFactorService): RedirectResponse
    {
        $request->ensureIsNotRateLimited();
        $request->validateCaptcha();

        $user = $authService->login(
            $request->string('identifier')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
            $request->throttleKey(),
            $request
        );

        if ($user->two_factor_enabled) {
            $request->session()->forget('two_factor_passed_at');
        }

        if (config('auth.email_verification_enabled', true) && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice')
                ->with('success', 'Vui lòng xác thực email để tiếp tục.');
        }

        if ($twoFactorRedirect = $this->twoFactorChallengeRedirect($user, $request, $twoFactorService)) {
            return $twoFactorRedirect;
        }

        return $this->redirectAfterAuthentication($user, $request)->with('success', 'Đăng nhập thành công!');
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function showRegisterRole(string $role): View
    {
        abort_unless(in_array($role, ['student', 'instructor'], true), Response::HTTP_NOT_FOUND);

        $categories = $role === 'instructor'
            ? Category::whereNull('parent_id')->with('children')->orderBy('name')->get()
            : collect();

        return view('auth.register-role', [
            'role' => $role,
            'categories' => $categories,
            'captcha' => CaptchaService::generate('register'),
            'pendingCv' => $role === 'instructor'
                ? app(RegistrationPendingCvService::class)->current(request())
                : null,
        ]);
    }

    public function register(
        string $role,
        Request $request,
        AuthService $authService,
        EmailVerificationService $emailVerificationService
    ): RedirectResponse {
        abort_unless(in_array($role, ['student', 'instructor'], true), Response::HTTP_NOT_FOUND);

        if ($role === 'instructor') {
            $formRequest = app(RegisterInstructorRequest::class);
        } else {
            $formRequest = app(RegisterRequest::class);
        }

        $data = $formRequest->validated();
        $formRequest->validateCaptcha();
        $data['role'] = $role;

        $user = $authService->register($data, $request);

        Auth::login($user);
        $request->session()->regenerate();
        $authService->registerActiveSession($user, $request);

        // Chỉ hướng dẫn giảng viên vừa đăng ký tới bước hoàn thiện hồ sơ một lần.
        if ($user->isInstructor()) {
            $request->session()->put('new_instructor_profile_redirect_user_id', $user->id);
        }

        if (! config('auth.email_verification_enabled', true)) {
            return $this->redirectAfterNewInstructorRegistration($user, $request)
                ->with('success', 'Đăng ký thành công.');
        }

        try {
            event(new Registered($user));
        } catch (ValidationException $exception) {
            return redirect()->route('verification.notice')
                ->withErrors($exception->errors())
                ->with('resend_after', $emailVerificationService->resendCooldownSeconds($user));
        } catch (Throwable $exception) {
            Log::error('Verification code email could not be sent after registration.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);

            return redirect()->route('verification.notice')
                ->with('error', 'Đăng ký thành công nhưng chưa gửi được mã xác thực: '.MailErrorFormatter::verificationSendFailure($exception));
        }

        return redirect()->route('verification.notice')
            ->with('success', 'Đăng ký thành công. Vui lòng kiểm tra email để nhập mã xác thực.')
            ->with('resend_after', $emailVerificationService->resendCooldownSeconds($user) ?: EmailVerificationCode::RESEND_COOLDOWN_SECONDS);
    }

    public function logout(Request $request): RedirectResponse
    {
        ActivityLogService::log(Auth::id(), 'logout', User::class, Auth::id(), null, $request);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Đã đăng xuất.');
    }

    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'in:email,username,phone'],
            'value' => ['required', 'string', 'max:255'],
        ]);

        $exists = User::where($validated['field'], $validated['value'])->exists();

        return response()->json([
            'available' => ! $exists,
            'message' => $exists ? 'Giá trị này đã được sử dụng.' : 'Có thể sử dụng.',
        ]);
    }

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password', [
            'captcha' => CaptchaService::generate('forgot-password'),
        ]);
    }

    public function sendResetLink(ForgotPasswordRequest $request): RedirectResponse
    {
        $request->validateCaptcha();

        $email = (string) $request->input('email');
        $neutralMessage = 'Nếu email tồn tại trong hệ thống, liên kết đặt lại mật khẩu sẽ được gửi.';
        $throttleSeconds = (int) config('auth.passwords.users.throttle', 60);

        try {
            $status = Password::broker('users')->sendResetLink(
                $request->only('email')
            );
        } catch (Throwable $exception) {
            Log::error('Password reset email could not be sent.', [
                'user_id' => User::query()->where('email', $email)->value('id'),
                'email_masked' => SensitiveData::maskEmail($email),
                'exception' => $exception::class,
            ]);

            return back()->withErrors([
                'email' => MailErrorFormatter::passwordResetSendFailure($exception),
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()
                ->withErrors([
                    'email' => 'Vui lòng đợi trước khi yêu cầu gửi lại liên kết đặt lại mật khẩu.',
                ])
                ->with('resend_after', $throttleSeconds);
        }

        // RESET_LINK_SENT and INVALID_USER share one response to avoid email enumeration.
        return back()
            ->with('success', $neutralMessage)
            ->with('resend_after', $throttleSeconds);
    }

    public function showResetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(
        ResetPasswordRequest $request,
        DatabaseSessionInvalidator $sessionInvalidator,
    ): RedirectResponse {
        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($sessionInvalidator): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                ])->save();

                // Guest reset cannot call logoutOtherDevices(); purge DB sessions by user_id.
                $sessionInvalidator->invalidateForUser($user->id);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('success', 'Mật khẩu đã được cập nhật. Bạn có thể đăng nhập lại.');
        }

        return back()->withErrors([
            'email' => $this->passwordResetFailureMessage($status),
        ]);
    }

    private function passwordResetFailureMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.',
            Password::INVALID_USER => 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.',
            Password::RESET_THROTTLED => 'Vui lòng đợi trước khi thử đặt lại mật khẩu lại.',
            default => 'Không thể đặt lại mật khẩu. Vui lòng thử lại.',
        };
    }

    public function verificationNotice(Request $request, EmailVerificationService $emailVerificationService): View|RedirectResponse
    {
        $user = $request->user();

        if (! config('auth.email_verification_enabled', true) || $user?->hasVerifiedEmail()) {
            return $this->redirectAfterAuthentication($user, $request);
        }

        return view('auth.verify-email', [
            'currentUser' => $user,
            'maskedEmail' => $emailVerificationService->maskEmail($user->email),
            'resendAfter' => max(
                (int) session('resend_after', 0),
                $emailVerificationService->resendCooldownSeconds($user)
            ),
        ]);
    }

    public function verifyEmailCode(
        VerifyEmailCodeRequest $request,
        EmailVerificationService $emailVerificationService,
        TwoFactorService $twoFactorService
    ): RedirectResponse {
        $user = $request->user();

        if (! config('auth.email_verification_enabled', true) || $user->hasVerifiedEmail()) {
            return redirect()->intended($user->dashboardUrl());
        }

        $result = $emailVerificationService->verify($user, $request->string('code')->toString());

        if (! $result['success']) {
            return back()->withErrors(['code' => $result['message']]);
        }

        event(new Verified($user));
        $request->session()->regenerate();
        app(AuthService::class)->registerActiveSession($user, $request);

        if ($twoFactorRedirect = $this->twoFactorChallengeRedirect($user, $request, $twoFactorService, $result['message'])) {
            return $twoFactorRedirect;
        }

        return $this->redirectAfterNewInstructorRegistration($user, $request)
            ->with('success', $result['message']);
    }

    public function verifyEmail(
        EmailVerificationRequest $request,
        TwoFactorService $twoFactorService
    ): RedirectResponse {
        if (! config('auth.email_verification_enabled', true) || $request->user()->hasVerifiedEmail()) {
            return $this->redirectAfterAuthentication($request->user(), $request);
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        if ($twoFactorRedirect = $this->twoFactorChallengeRedirect(
            $request->user(),
            $request,
            $twoFactorService,
            'Email đã được xác thực.'
        )) {
            return $twoFactorRedirect;
        }

        return $this->redirectAfterNewInstructorRegistration($request->user(), $request)
            ->with('success', 'Email đã được xác thực thành công.');
    }

    public function resendVerification(Request $request, EmailVerificationService $emailVerificationService): RedirectResponse
    {
        if (! config('auth.email_verification_enabled', true) || $request->user()->hasVerifiedEmail()) {
            return $this->redirectAfterAuthentication($request->user(), $request);
        }

        try {
            $emailVerificationService->sendCode($request->user());
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('resend_after', $emailVerificationService->resendCooldownSeconds($request->user()));
        } catch (Throwable $exception) {
            Log::error('Verification code email could not be resent.', [
                'user_id' => $request->user()->id,
                'email' => $request->user()->email,
                'error' => $exception->getMessage(),
            ]);

            return back()->withErrors([
                'email' => MailErrorFormatter::verificationSendFailure($exception),
            ]);
        }

        return back()
            ->with('success', 'Mã xác thực mới đã được gửi.')
            ->with('resend_after', EmailVerificationCode::RESEND_COOLDOWN_SECONDS);
    }

    public function instantVerify(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        abort_unless(app()->environment('local'), Response::HTTP_NOT_FOUND);

        $user = $request->user();

        if ($user && ! $user->hasVerifiedEmail()) {
            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }
        }

        if ($twoFactorRedirect = $this->twoFactorChallengeRedirect(
            $user,
            $request,
            $twoFactorService,
            'Email đã được xác thực.'
        )) {
            return $twoFactorRedirect;
        }

        return $this->redirectAfterNewInstructorRegistration($user, $request)
            ->with('success', 'Email đã được xác thực thành công.');
    }

    public function showTwoFactorChallenge(): View
    {
        return view('auth.two-factor-challenge');
    }

    public function verifyTwoFactor(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        if (! $twoFactorService->verify($request->user(), $validated['code'])) {
            return back()->withErrors(['code' => 'Mã 2FA không đúng hoặc đã hết hạn.']);
        }

        $request->session()->put('two_factor_passed_at', now()->timestamp);
        ActivityLogService::log($request->user()->id, 'verify_2fa', User::class, $request->user()->id, null, $request);

        return $this->redirectAfterAuthentication($request->user(), $request)->with('success', 'Xác thực 2FA thành công.');
    }

    public function resendTwoFactor(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $twoFactorService->sendCode($request->user());

        return back()->with('success', 'Mã 2FA mới đã được gửi.')->with('resend_after', 60);
    }

    public function quickLogin(Request $request, string $role): RedirectResponse
    {
        abort_unless(app()->environment('local'), Response::HTTP_NOT_FOUND);

        $email = match ($role) {
            'admin' => 'admin@example.com',
            'instructor' => 'instructor@example.com',
            'student' => 'student@example.com',
            default => abort(Response::HTTP_NOT_FOUND),
        };

        $user = User::where('email', $email)->firstOrFail();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect($user->dashboardUrl())->with('success', 'Đăng nhập nhanh thành công.');
    }

    private function twoFactorChallengeRedirect(
        User $user,
        Request $request,
        TwoFactorService $twoFactorService,
        ?string $successPrefix = null
    ): ?RedirectResponse {
        if (! $user->two_factor_enabled || $request->session()->has('two_factor_passed_at')) {
            return null;
        }

        $twoFactorService->sendCode($user);

        $message = 'Mã 2FA mới đã được gửi tới email của bạn.';
        if ($successPrefix !== null && $successPrefix !== '') {
            $message = $successPrefix.' '.$message;
        }

        return redirect()->route('two-factor.challenge')->with('success', $message);
    }

    private function redirectAfterAuthentication(User $user, Request $request): RedirectResponse
    {
        if (! $user->isStudent()) {
            $request->session()->forget('url.intended');

            return redirect($user->dashboardUrl());
        }

        return redirect()->intended($user->dashboardUrl());
    }

    /**
     * A new instructor is taken to the profile once after registration/verification,
     * without changing redirects for later logins or existing accounts.
     */
    private function redirectAfterNewInstructorRegistration(User $user, Request $request): RedirectResponse
    {
        $registeredInstructorId = $request->session()->pull('new_instructor_profile_redirect_user_id');

        if ($user->isInstructor() && (int) $registeredInstructorId === $user->id) {
            return redirect()->route('instructor.profile');
        }

        return $this->redirectAfterAuthentication($user, $request);
    }

    private function isSafeRedirect(string $redirect): bool
    {
        if ($redirect === '') {
            return false;
        }

        if (Str::startsWith($redirect, '/') && ! Str::startsWith($redirect, '//')) {
            return true;
        }

        return Str::startsWith($redirect, url('/'));
    }
}
