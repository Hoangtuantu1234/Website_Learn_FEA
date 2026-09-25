<?php

use App\Http\Controllers\Api\StudyGroupController;
use App\Http\Controllers\Web\Admin\AiModerationController;
use App\Http\Controllers\Web\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Web\Admin\CommissionController;
use App\Http\Controllers\Web\Admin\ContentUpdateController;
use App\Http\Controllers\Web\Admin\ContentVersionHistoryController as AdminContentVersionHistoryController;
use App\Http\Controllers\Web\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Web\Admin\CourseReviewController;
use App\Http\Controllers\Web\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Web\Admin\InstructorApplicationController;
use App\Http\Controllers\Web\Admin\InstructorDocumentRequirementController;
use App\Http\Controllers\Web\Admin\InstructorTeachingFieldReviewController;
use App\Http\Controllers\Web\Admin\LearningPathController as AdminLearningPathController;
use App\Http\Controllers\Web\Admin\ManageController;
use App\Http\Controllers\Web\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Web\Admin\QuizQuestionInvalidationController;
use App\Http\Controllers\Web\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Web\Admin\RoleController;
use App\Http\Controllers\Web\Admin\StudentReviewController as AdminStudentReviewController;
use App\Http\Controllers\Web\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Web\Admin\UserController;
use App\Http\Controllers\Web\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CourseController;
use App\Http\Controllers\Web\DiscussionController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\Instructor\ContentVersionHistoryController as InstructorContentVersionHistoryController;
use App\Http\Controllers\Web\Instructor\CouponController as InstructorCouponController;
use App\Http\Controllers\Web\Instructor\CourseController as InstructorCourseController;
use App\Http\Controllers\Web\Instructor\CurriculumController as InstructorCurriculumController;
use App\Http\Controllers\Web\Instructor\DashboardController as InstructorDashboardController;
use App\Http\Controllers\Web\Instructor\DiscussionController as InstructorDiscussionController;
use App\Http\Controllers\Web\Instructor\FullCourseImportController;
use App\Http\Controllers\Web\Instructor\InstructorProfileController;
use App\Http\Controllers\Web\Instructor\LearningPathController as InstructorLearningPathController;
use App\Http\Controllers\Web\Instructor\LessonImportController as InstructorLessonImportController;
use App\Http\Controllers\Web\Instructor\QuizAttemptRequestController as InstructorQuizAttemptRequestController;
use App\Http\Controllers\Web\Instructor\QuizController as InstructorQuizController;
use App\Http\Controllers\Web\Instructor\QuizQuestionInvalidationController as InstructorQuizQuestionInvalidationController;
use App\Http\Controllers\Web\Instructor\ReviewController as InstructorReviewController;
use App\Http\Controllers\Web\Instructor\ReviewReplyController;
use App\Http\Controllers\Web\Instructor\S3MultipartUploadController;
use App\Http\Controllers\Web\Instructor\SubmissionController;
use App\Http\Controllers\Web\Instructor\WalletController as InstructorWalletController;
use App\Http\Controllers\Web\InstructorController;
use App\Http\Controllers\Web\InstructorPendingController;
use App\Http\Controllers\Web\LeaderboardController;
use App\Http\Controllers\Web\LearningPathAiController;
use App\Http\Controllers\Web\LearningPathController;
use App\Http\Controllers\Web\LegalDocumentController;
use App\Http\Controllers\Web\LessonCommentController;
use App\Http\Controllers\Web\MessengerController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\PaymentController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\ReviewController;
use App\Http\Controllers\Web\ReviewHelpfulController;
use App\Http\Controllers\Web\SocialAuthController;
use App\Http\Controllers\Web\Student\AssignmentController as StudentAssignmentController;
use App\Http\Controllers\Web\Student\CartController;
use App\Http\Controllers\Web\Student\CertificateController as StudentCertificateController;
use App\Http\Controllers\Web\Student\CourseController as StudentCourseController;
use App\Http\Controllers\Web\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Web\Student\LessonAiController;
use App\Http\Controllers\Web\Student\LessonNoteController;
use App\Http\Controllers\Web\Student\LessonNoteLibraryController;
use App\Http\Controllers\Web\Student\MiscController as StudentMiscController;
use App\Http\Controllers\Web\Student\OrderController as StudentOrderController;
use App\Http\Controllers\Web\Student\ProfileController as StudentProfileController;
use App\Http\Controllers\Web\Student\QuizController as StudentQuizController;
use App\Http\Controllers\Web\Student\RecentlyViewedCourseController;
use App\Http\Controllers\Web\Student\RefundController as StudentRefundController;
use App\Http\Controllers\Web\Student\ReviewController as StudentReviewController;
use App\Http\Controllers\Web\Student\SecurityController as StudentSecurityController;
use App\Http\Controllers\Web\Student\StudyGroupController as StudentStudyGroupController;
use App\Http\Controllers\Web\Student\VoucherController as StudentVoucherController;
use App\Http\Controllers\Web\Student\WishlistController as StudentWishlistController;
use App\Http\Controllers\Web\SupportTicketController;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);

Route::get('/home', [HomeController::class, 'index'])->name('home');
Route::get('/dieu-khoan-dang-ky', [LegalDocumentController::class, 'registrationTerms'])
    ->name('legal.registration-terms');
Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');

// ─── STATIC INFORMATION PAGES ───
Route::prefix('pages')->name('pages.')->group(function () {
    Route::get('/academy', [PageController::class, 'academy'])->name('academy');
    Route::get('/innovation-lab', [PageController::class, 'innovationLab'])->name('innovation-lab');
    Route::get('/career-accelerator', [PageController::class, 'careerAccelerator'])->name('career-accelerator');
    Route::get('/corporate-training', [PageController::class, 'corporateTraining'])->name('corporate-training');
});

// ─── GIẢNG VIÊN (PUBLIC) ───
Route::get('/instructors', [InstructorController::class, 'index'])->name('instructors.index');
Route::get('/instructors/{user}', [InstructorController::class, 'show'])->name('instructors.show');
Route::get('/courses/category/{category:slug}', [CourseController::class, 'category'])->name('courses.category');
Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard');

// ─── LỘ TRÌNH HỌC TẬP (PUBLIC) ───
Route::get('/learning-paths', [LearningPathController::class, 'index'])->name('learning-paths.index');
Route::get('/learning-paths/{slug}', [LearningPathController::class, 'show'])->name('learning-paths.show');
Route::post('/learning-paths/ai/chat', [LearningPathAiController::class, 'chat'])->name('learning-paths.ai.chat');
Route::get('/learning-paths/ai/conversation', [LearningPathAiController::class, 'getConversation'])->name('learning-paths.ai.conversation');
Route::post('/learning-paths/ai/reset', [LearningPathAiController::class, 'reset'])->name('learning-paths.ai.reset');

// ─── CHỨNG CHỈ CÔNG KHAI (không cần đăng nhập) ───
Route::get('/certificates/{code}', [StudentMiscController::class, 'publicCertificate'])->name('certificates.public');
Route::get('/certificates/{code}/pdf', [StudentMiscController::class, 'publicCertificatePdf'])->name('certificates.public.pdf');
Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::post('/courses/{course}/enroll', [CourseController::class, 'enroll'])->name('courses.enroll');
    Route::get('/my-courses', [StudentCourseController::class, 'index'])->name('my-courses');

    // Study Groups
    Route::get('/study-groups', [StudyGroupController::class, 'index'])->name('study-groups.index');
    Route::post('/study-groups', [StudyGroupController::class, 'store'])->name('study-groups.store');
    Route::get('/study-groups/{studyGroup}', [StudyGroupController::class, 'show'])->name('study-groups.show');
    Route::put('/study-groups/{studyGroup}', [StudyGroupController::class, 'update'])->name('study-groups.update');
    Route::delete('/study-groups/{studyGroup}', [StudyGroupController::class, 'destroy'])->name('study-groups.destroy');
    Route::post('/study-groups/{studyGroup}/join', [StudyGroupController::class, 'join'])->name('study-groups.join');
    Route::post('/study-groups/{studyGroup}/leave', [StudyGroupController::class, 'leave'])->name('study-groups.leave');
    Route::get('/study-groups/{studyGroup}/members', [StudyGroupController::class, 'members'])->name('study-groups.members');
    Route::post('/study-groups/{studyGroup}/messages', [StudyGroupController::class, 'storeMessage'])->name('study-groups.messages.store');
    Route::post('/study-groups/{studyGroup}/messages/{message}/recall', [StudyGroupController::class, 'recallMessage'])->name('study-groups.messages.recall');
    Route::get('/study-groups/{studyGroup}/messages/{message}/file', [StudyGroupController::class, 'downloadFile'])->name('study-groups.messages.download');
    Route::delete('/study-groups/{studyGroup}/members/{user}', [StudyGroupController::class, 'removeMember'])->name('study-groups.members.remove');

    // Study Group Invitations
    Route::get('/study-groups/{studyGroup}/search-users', [StudyGroupController::class, 'searchUsers'])->name('study-groups.search-users');
    Route::post('/study-groups/{studyGroup}/invite', [StudyGroupController::class, 'invite'])->name('study-groups.invite');
    Route::post('/study-groups/{studyGroup}/invitations/{invitation}/cancel', [StudyGroupController::class, 'cancelInvitation'])->name('study-groups.invitations.cancel');
    Route::post('/study-groups/invitations/{invitation}/accept', [StudyGroupController::class, 'acceptInvitation'])->name('study-groups.invitations.accept');
    Route::post('/study-groups/invitations/{invitation}/reject', [StudyGroupController::class, 'rejectInvitation'])->name('study-groups.invitations.reject');
});
Route::middleware(['auth', 'active', 'verified', 'role:student'])->group(function () {
    Route::get('/favorites', [StudentWishlistController::class, 'index'])->name('favorites.index');
    Route::post('/courses/{course}/favorite', [StudentMiscController::class, 'storeFavorite'])->name('courses.favorite.store');
    Route::delete('/courses/{course}/favorite', [StudentMiscController::class, 'destroyFavorite'])->name('courses.favorite.destroy');
});
Route::get('/courses/{course}/lessons/{lesson}', [CourseController::class, 'lesson'])->name('courses.lessons.show');
Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::post('/courses/{course}/lessons/{lesson}/progress', [CourseController::class, 'updateLessonProgress'])->name('courses.lessons.progress');
    Route::post('/courses/{course}/lessons/{lesson}/quiz/start', [StudentQuizController::class, 'start'])->name('courses.lessons.quiz.start');
    Route::post('/courses/{course}/lessons/{lesson}/quiz/save-progress', [StudentQuizController::class, 'saveProgress'])->name('courses.lessons.quiz.save-progress');
    Route::post('/courses/{course}/lessons/{lesson}/quiz/terminate', [StudentQuizController::class, 'terminate'])->name('courses.lessons.quiz.terminate');
    Route::post('/courses/{course}/lessons/{lesson}/quiz/submit', [StudentQuizController::class, 'submitAjax'])->name('courses.lessons.quiz.submit');
    Route::get('/courses/{course}/lessons/{lesson}/quiz/attempts/{attempt}', [StudentQuizController::class, 'reviewAttempt'])->name('courses.lessons.quiz.attempts.show');
    Route::post('/courses/{course}/lessons/{lesson}/quiz/request-attempt', [StudentQuizController::class, 'requestAttempt'])->name('courses.lessons.quiz.request-attempt');
    Route::get('/courses/{course}/lessons/{lesson}/assignment/download', [StudentAssignmentController::class, 'download'])->name('courses.lessons.assignment.download');
    Route::post('/courses/{course}/lessons/{lesson}/quiz/attempts/{attempt}/focus-violation', [StudentQuizController::class, 'recordFocusViolation'])->middleware('throttle:20,1')->name('courses.lessons.quiz.focus-violation');
    Route::post('/courses/{course}/lessons/{lesson}/assignment/submit', [StudentAssignmentController::class, 'submit'])->name('courses.lessons.assignment.submit');
    Route::post('/courses/{course}/lessons/{lesson}/assignment/retry', [StudentAssignmentController::class, 'retry'])->name('courses.lessons.assignment.retry');
});

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::get('/messenger/conversations', [MessengerController::class, 'index'])->name('messenger.conversations.index');
    Route::post('/courses/{course}/lessons/{lesson}/discussions', [DiscussionController::class, 'store'])->name('courses.lessons.discussions.store');
    Route::get('/discussions/{discussion}/messages', [DiscussionController::class, 'messages'])->name('discussions.messages');
    Route::get('/discussions/{discussion}/messages/{messageKey}', [DiscussionController::class, 'message'])->name('discussions.message');
    Route::post('/discussions/{discussion}/read', [DiscussionController::class, 'markRead'])->name('discussions.read');
    Route::get('/discussion-messages/{kind}/{message}/attachment', [DiscussionController::class, 'attachment'])->name('discussion-messages.attachment');
    Route::post('/discussions/{discussion}/recall', [DiscussionController::class, 'recallDiscussion'])->name('discussions.recall');
    Route::delete('/discussions/{discussion}', [DiscussionController::class, 'destroyDiscussion'])->name('discussions.destroy');
    Route::post('/discussions/{discussion}/replies', [DiscussionController::class, 'storeReply'])->name('discussions.replies.store');
    Route::post('/discussion-replies/{reply}/recall', [DiscussionController::class, 'recallReply'])->name('discussions.replies.recall');
    Route::delete('/discussion-replies/{reply}', [DiscussionController::class, 'destroyReply'])->name('discussions.replies.destroy');
    Route::post('/discussion-replies/{reply}/toggle-helpful', [DiscussionController::class, 'toggleHelpful'])->name('discussions.replies.toggle-helpful');
    Route::get('/lessons/{lesson}/comments', [LessonCommentController::class, 'index'])->name('lessons.comments.index');
    Route::post('/lessons/{lesson}/comments', [LessonCommentController::class, 'store'])->name('lessons.comments.store');
    Route::put('/comments/{comment}', [LessonCommentController::class, 'update'])->name('comments.update');
    Route::delete('/comments/{comment}', [LessonCommentController::class, 'destroy'])->name('comments.destroy');
    Route::post('/comments/{comment}/toggle-hide', [LessonCommentController::class, 'toggleHide'])->name('comments.toggle-hide');
});

Route::middleware(['auth', 'active', 'verified', 'role:student', 'throttle:30,1'])->group(function () {
    Route::get('/courses/{course}/lessons/{lesson}/notes', [LessonNoteController::class, 'index'])->name('courses.lessons.notes.index');
    Route::post('/courses/{course}/lessons/{lesson}/notes', [LessonNoteController::class, 'store'])->name('courses.lessons.notes.store');
    Route::patch('/lesson-notes/{lessonNote}', [LessonNoteController::class, 'update'])->name('lesson-notes.update');
    Route::delete('/lesson-notes/{lessonNote}', [LessonNoteController::class, 'destroy'])->name('lesson-notes.destroy');
});

Route::middleware(['auth', 'active', 'verified', 'throttle:20,1'])->group(function () {
    Route::get('/courses/{course}/lessons/{lesson}/ai-summary', [LessonAiController::class, 'summary'])
        ->middleware('throttle:6,1')
        ->name('courses.lessons.ai-summary');
    Route::post('/courses/{course}/lessons/{lesson}/ai-explain', [LessonAiController::class, 'explain'])
        ->middleware('throttle:10,1')
        ->name('courses.lessons.ai-explain');
    Route::post('/courses/{course}/lessons/{lesson}/ai-chat', [LessonAiController::class, 'chat'])
        ->middleware('throttle:20,1')
        ->name('courses.lessons.ai-chat');
    Route::get('/courses/{course}/lessons/{lesson}/ai-chat', [LessonAiController::class, 'history'])
        ->middleware('throttle:30,1')
        ->name('courses.lessons.ai-chat.history');
});

Route::get('/learn/{course:slug}/lessons/{lesson}/quiz', [StudentQuizController::class, 'show'])->name('learn.lessons.quiz.show');
Route::get('/learn/{course:slug}/lessons/{lesson}/quiz-attempts/{attempt}/result', [StudentQuizController::class, 'result'])->middleware(['auth', 'active', 'verified'])->name('learn.lessons.quiz.result');
Route::post('/learn/{course:slug}/lessons/{lesson}/quiz/submit', [StudentQuizController::class, 'submit'])->middleware(['auth', 'active', 'verified'])->name('learn.lessons.quiz.submit');
Route::get('/learn/{course:slug}/lessons/{lesson}/quiz/attempts/{attempt}', [StudentQuizController::class, 'reviewAttempt'])->middleware(['auth', 'active', 'verified'])->name('learn.lessons.quiz.attempts.show');
Route::middleware(['auth', 'active', 'verified', 'role:student', 'throttle:6,1'])->group(function () {
    Route::post('/courses/{course}/reviews', [ReviewController::class, 'store'])->name('courses.reviews.store');
    Route::put('/courses/{course}/reviews/{review}', [ReviewController::class, 'update'])->name('courses.reviews.update');
    Route::delete('/courses/{course}/reviews/{review}', [ReviewController::class, 'destroy'])->name('courses.reviews.destroy');
});
Route::post('/reviews/{review}/helpful', [ReviewHelpfulController::class, 'toggle'])
    ->middleware(['auth', 'active', 'verified', 'throttle:20,1'])
    ->name('reviews.helpful.toggle');
Route::get('/courses/{slug}', [CourseController::class, 'show'])->name('courses.show');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::get('/register/{role}', [AuthController::class, 'showRegisterRole'])->where('role', 'student|instructor')->name('register.role');
    Route::post('/register/{role}', [AuthController::class, 'register'])->where('role', 'student|instructor')->middleware('throttle:6,1');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:10,1')->name('password.email');
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'facebook'])
        ->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'facebook'])
        ->name('social.callback');
    if (app()->environment('local')) {
        Route::post('/quick-login/{role}', [AuthController::class, 'quickLogin'])
            ->whereIn('role', ['admin', 'instructor', 'student'])
            ->name('quick-login');
    }
});

Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

Route::get('/auth/availability', [AuthController::class, 'availability'])->middleware('throttle:30,1')->name('auth.availability');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/email/verify', [AuthController::class, 'verificationNotice'])->name('verification.notice');
    Route::post('/email/verify-code', [AuthController::class, 'verifyEmailCode'])
        ->middleware('throttle:10,1')
        ->name('verification.code.verify');
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:5,15')
        ->name('verification.send');
    if (app()->environment('local')) {
        Route::post('/email/verify/instant', [AuthController::class, 'instantVerify'])
            ->name('verification.instant');
    }
    Route::get('/two-factor-challenge', [AuthController::class, 'showTwoFactorChallenge'])->middleware('verified')->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [AuthController::class, 'verifyTwoFactor'])->middleware(['verified', 'throttle:6,1'])->name('two-factor.verify');
    Route::post('/two-factor-challenge/resend', [AuthController::class, 'resendTwoFactor'])->middleware(['verified', 'throttle:3,1'])->name('two-factor.resend');
});

Route::middleware(['auth', 'active', 'verified', '2fa'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/email', [ProfileController::class, 'updateEmail'])->name('profile.email.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::post('/profile/two-factor/send', [ProfileController::class, 'sendTwoFactorCode'])->name('profile.two-factor.send');
    Route::post('/profile/two-factor/enable', [ProfileController::class, 'enableTwoFactor'])->name('profile.two-factor.enable');
    Route::delete('/profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('profile.two-factor.disable');
    Route::delete('/profile/sessions/others', [ProfileController::class, 'destroyOtherSessions'])->name('profile.sessions.destroy-others');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
});

Route::get('/dashboard', function () {
    return redirect(auth()->user()->dashboardUrl());
})->middleware(['auth', 'active', 'verified', '2fa'])->name('dashboard');

// ─── TICKET HỖ TRỢ (Student + Instructor) ───
Route::middleware(['auth', 'active', 'verified', '2fa', 'role:student,instructor'])->prefix('support')->name('support.')->group(function () {
    Route::get('/tickets', [SupportTicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/create', [SupportTicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [SupportTicketController::class, 'store'])->middleware('throttle:10,1')->name('tickets.store');
    Route::get('/tickets/{ticket}', [SupportTicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/replies', [SupportTicketController::class, 'reply'])->middleware('throttle:20,1')->name('tickets.reply');
    Route::post('/tickets/{ticket}/close', [SupportTicketController::class, 'close'])->name('tickets.close');
    Route::post('/tickets/{ticket}/reopen', [SupportTicketController::class, 'reopen'])->name('tickets.reopen');
    Route::get('/tickets/{ticket}/attachments/{attachment}', [SupportTicketController::class, 'downloadAttachment'])->name('tickets.attachments.download');
});

// ─── HỌC VIÊN ───
Route::middleware(['auth', 'active', 'verified', '2fa', 'role:student'])->prefix('student')->name('student.')->group(function () {
    Route::get('/dashboard', [StudentDashboardController::class, 'index'])->name('dashboard');
    Route::get('/courses', [StudentCourseController::class, 'index'])->name('courses');
    Route::get('/recently-viewed', [RecentlyViewedCourseController::class, 'index'])->name('recently-viewed');
    Route::get('/recently-viewed-courses', [RecentlyViewedCourseController::class, 'index'])->name('recently-viewed.index');
    Route::get('/lesson-notes', [LessonNoteLibraryController::class, 'index'])->name('lesson-notes.index');
    Route::get('/reviews', [StudentReviewController::class, 'index'])->name('reviews.index');
    Route::delete('/recently-viewed-courses', [RecentlyViewedCourseController::class, 'clear'])->name('recently-viewed.clear');
    Route::delete('/recently-viewed-courses/{recentlyViewedCourse}', [RecentlyViewedCourseController::class, 'destroy'])->name('recently-viewed.destroy');
    Route::get('/cart', [CartController::class, 'index'])->name('cart');
    Route::post('/cart/add/{course}', [CartController::class, 'add'])->name('cart.add');
    Route::post('/cart/buy-now/{course}', [CartController::class, 'buyNow'])->name('cart.buy_now');
    Route::delete('/cart/remove/{courseId}', [CartController::class, 'remove'])->name('cart.remove');
    Route::delete('/cart/remove-ajax/{courseId}', [CartController::class, 'removeAjax'])->name('cart.remove_ajax');
    Route::post('/cart/move-to-wishlist/{courseId}', [CartController::class, 'moveToWishlist'])->name('cart.move_to_wishlist');
    Route::post('/cart/checkout', [CartController::class, 'checkout'])->middleware('throttle:10,1')->block(10, 10)->name('cart.checkout');
    Route::post('/cart/coupon/apply', [CartController::class, 'applyCoupon'])->middleware('throttle:30,1')->name('cart.coupon.apply');
    Route::get('/checkout/{order_code}/pay', [CartController::class, 'showPaymentPage'])->name('checkout.pay');
    Route::get('/checkout/{order_code}/status', [CartController::class, 'checkStatus'])->name('checkout.status');
    Route::post('/checkout/{order_code}/apply-coupon', [CartController::class, 'applyCouponToOrder'])->middleware('throttle:20,1')->name('checkout.apply_coupon');
    Route::delete('/checkout/{order_code}/remove-coupon', [CartController::class, 'removeCouponFromOrder'])->middleware('throttle:20,1')->name('checkout.remove_coupon');
    Route::post('/checkout/{order_code}/pay', [CartController::class, 'processPayment'])->middleware('throttle:checkout-payment')->name('checkout.process_payment');
    Route::get('/checkout/mock-gateway/{order_code}', [CartController::class, 'mockGateway'])->name('checkout.mock_gateway');
    Route::post('/checkout/{order_code}/simulate', [CartController::class, 'simulatePayment'])->middleware('throttle:10,1')->name('checkout.simulate');
    Route::get('/checkout/{order_code}/success', [CartController::class, 'successPage'])->name('checkout.success');
    Route::get('/checkout/{order_code}/failed', [CartController::class, 'failedPage'])->name('checkout.failed');
    Route::get('/wishlist', [StudentWishlistController::class, 'index'])->name('wishlist');
    Route::post('/wishlist/{courseId}', [StudentMiscController::class, 'toggleWishlist'])->name('wishlist.toggle');
    Route::get('/certificates', [StudentCertificateController::class, 'index'])->name('certificates');
    Route::get('/certificates/{certificate}/pdf', [StudentCertificateController::class, 'pdf'])->name('certificates.pdf');
    Route::get('/orders', [StudentOrderController::class, 'index'])->name('orders');
    Route::get('/assignments', [StudentAssignmentController::class, 'index'])->name('assignments.index');
    Route::get('/orders/{order}', [StudentOrderController::class, 'show'])->name('orders.show');
    Route::delete('/orders/{order}', [StudentOrderController::class, 'cancel'])->middleware('throttle:10,1')->name('orders.cancel');
    Route::post('/orders/{order}/refund', [StudentRefundController::class, 'store'])->middleware('throttle:5,1')->block(10, 10)->name('orders.refund');
    Route::get('/vouchers', [StudentVoucherController::class, 'index'])->name('vouchers.index');
    Route::get('/vouchers/{coupon}', [StudentVoucherController::class, 'show'])->name('vouchers.show');
    Route::get('/study-groups', [StudentStudyGroupController::class, 'index'])->name('study-groups.index');
    Route::get('/study-groups/{studyGroup}', [StudentStudyGroupController::class, 'show'])->name('study-groups.show');
    Route::get('/profile', [StudentProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [StudentProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/security', [StudentSecurityController::class, 'index'])->name('profile.security');
    Route::put('/profile/email', [StudentSecurityController::class, 'updateEmail'])->name('profile.email.update');
    Route::put('/profile/password', [StudentSecurityController::class, 'updatePassword'])->name('profile.password.update');
});

Route::redirect('/cart', '/student/cart')->name('cart');

// ─── GIẢNG VIÊN ───
Route::middleware(['auth', 'active', 'verified', '2fa', 'role:instructor'])->prefix('instructor')->name('instructor.')->group(function () {
    // Trang hồ sơ & quản lý chứng chỉ / tài liệu minh chứng (luôn truy cập được kể cả khi locked)
    Route::get('/profile', [InstructorProfileController::class, 'show'])->name('profile');
    Route::put('/profile', [InstructorProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/documents', [InstructorProfileController::class, 'uploadDocument'])->name('profile.documents.upload');
    Route::put('/profile/documents/{certificate}/url', [InstructorProfileController::class, 'updateDocumentUrl'])->name('profile.documents.url.update');
    Route::delete('/profile/documents/{certificate}', [InstructorProfileController::class, 'deleteDocument'])->name('profile.documents.delete');
    Route::patch('/profile/documents/{certificate}', [InstructorProfileController::class, 'replaceDocument'])->name('profile.documents.replace');
    Route::get('/profile/documents/{certificate}/view', [InstructorProfileController::class, 'viewDocument'])->name('profile.documents.view');
    Route::post('/profile/submit-review', [InstructorProfileController::class, 'submitForReview'])->middleware('throttle:5,1')->name('profile.submit-review');
    Route::post('/profile/teaching-fields/{teachingField}/submit-review', [InstructorProfileController::class, 'submitTeachingFieldForReview'])->middleware('throttle:5,1')->name('profile.teaching-fields.submit-review');
    Route::post('/profile/teaching-fields/{teachingField}/submit-supplement', [InstructorProfileController::class, 'submitTeachingFieldSupplement'])->middleware('throttle:5,1')->name('profile.teaching-fields.submit-supplement');
    Route::post('/profile/request-reactivation', [InstructorProfileController::class, 'requestReactivation'])->middleware('throttle:5,1')->name('profile.request-reactivation');

    // Backward compatibility aliases for certificate upload/view/delete
    Route::get('/pending', [InstructorPendingController::class, 'show'])->name('pending');
    Route::post('/certificates/upload', [InstructorProfileController::class, 'uploadDocument'])->name('certificates.upload');
    Route::delete('/certificates/{certificate}', [InstructorProfileController::class, 'deleteDocument'])->name('certificates.delete');
    Route::patch('/certificates/{certificate}', [InstructorProfileController::class, 'replaceDocument'])->name('certificates.replace');
    Route::get('/certificates/{certificate}/view', [InstructorProfileController::class, 'viewDocument'])->name('certificates.view');
    Route::post('/submit-review', [InstructorProfileController::class, 'submitForReview'])->middleware('throttle:5,1')->name('submit-review');
    Route::post('/resubmit', [InstructorPendingController::class, 'resubmit'])->middleware('throttle:5,1')->name('resubmit');

    Route::middleware('approved.instructor')->group(function () {
        Route::get('/dashboard', [InstructorDashboardController::class, 'index'])->name('dashboard');
        Route::get('/courses', [InstructorCourseController::class, 'index'])->name('courses.index');
        Route::get('/courses/import', [FullCourseImportController::class, 'create'])->name('courses.full-import.create');
        Route::get('/courses/import/template', [FullCourseImportController::class, 'downloadTemplate'])->name('courses.full-import.template');
        Route::post('/courses/import/preview', [FullCourseImportController::class, 'preview'])->name('courses.full-import.preview');
        Route::post('/courses/import/confirm', [FullCourseImportController::class, 'confirm'])->name('courses.full-import.confirm');
        Route::get('/courses/import/previews/{batch}', [FullCourseImportController::class, 'show'])->name('courses.full-import.show');
        Route::get('/courses/create', [InstructorCourseController::class, 'create'])->name('courses.create');
        Route::get('/courses/{course}', [InstructorCourseController::class, 'show'])->name('courses.show');
        Route::get('/courses/{course}/curriculum', [InstructorCurriculumController::class, 'index'])->name('courses.curriculum');
        Route::get('/courses/{course}/versions', [InstructorContentVersionHistoryController::class, 'index'])->name('courses.versions.index');
        Route::get('/courses/{course}/versions/{type}/{version}', [InstructorContentVersionHistoryController::class, 'show'])->name('courses.versions.show');
        Route::get('/courses/{course}/versions/{type}/{version}/compare', [InstructorContentVersionHistoryController::class, 'compare'])->name('courses.versions.compare');
        Route::get('/courses/{course}/versions/{type}/{version}/rollback', [InstructorContentVersionHistoryController::class, 'confirmRollback'])->name('courses.versions.rollback.confirm');
        Route::post('/courses/{course}/versions/{type}/{version}/rollback', [InstructorContentVersionHistoryController::class, 'storeRollback'])->name('courses.versions.rollback.store');
        Route::get('/courses/{course}/lessons/{lesson}/quiz', [InstructorQuizController::class, 'show'])->name('courses.lessons.quiz.show');
        Route::get('/courses/{course}/edit', [InstructorCourseController::class, 'edit'])->name('courses.edit');
        Route::get('/courses/{course}/students', [InstructorCourseController::class, 'students'])->name('courses.students');
        Route::get('/courses/{course}/students/export', [InstructorCourseController::class, 'exportStudents'])->name('courses.students.export');
        Route::get('/courses/{course}/students/{student}', [InstructorCourseController::class, 'studentDetail'])->name('courses.students.detail');
        Route::get('/courses/{course}/students/{student}/quizzes/{quiz}/attempts/{attempt}', [InstructorCourseController::class, 'studentQuizAttempt'])->name('courses.students.quiz-attempt');
        Route::post('/courses/{course}/students/{student}/notify', [InstructorCourseController::class, 'sendNotification'])->name('courses.students.notify');
        Route::get('/revenue', [InstructorCourseController::class, 'revenue'])->name('revenue');
        Route::get('/wallet', [InstructorWalletController::class, 'index'])->name('wallet.index');
        Route::put('/wallet/bank-details', [InstructorWalletController::class, 'updateBankDetails'])->middleware('throttle:5,1')->name('wallet.bank-details.update');
        Route::post('/wallet/withdraw', [InstructorWalletController::class, 'requestWithdrawal'])->middleware('throttle:5,1')->block(10, 10)->name('wallet.withdraw');
        Route::resource('coupons', InstructorCouponController::class);
        Route::post('coupons/{coupon}/toggle-status', [InstructorCouponController::class, 'toggleStatus'])->name('coupons.toggle-status');
        Route::resource('learning-paths', InstructorLearningPathController::class);

        Route::get('/reviews', [InstructorReviewController::class, 'index'])->name('reviews.index');
        Route::get('/submissions', [SubmissionController::class, 'index'])->name('submissions.index');
        Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');
        Route::post('/submissions/{submission}/grade', [SubmissionController::class, 'grade'])->name('submissions.grade');
        Route::post('/submissions/{submission}/grant-retry', [SubmissionController::class, 'grantRetry'])->name('submissions.grant-retry');
        Route::get('/quiz-attempt-requests', [InstructorQuizAttemptRequestController::class, 'index'])->name('quiz-attempt-requests.index');
        Route::post('/quiz-attempt-requests/{attemptRequest}/approve', [InstructorQuizAttemptRequestController::class, 'approve'])->name('quiz-attempt-requests.approve');
        Route::post('/quiz-attempt-requests/{attemptRequest}/reject', [InstructorQuizAttemptRequestController::class, 'reject'])->name('quiz-attempt-requests.reject');
        Route::get('/discussions', [InstructorDiscussionController::class, 'index'])->name('discussions.index');
        Route::get('/discussions/{discussion}', [InstructorDiscussionController::class, 'show'])->name('discussions.show');
        Route::get('/comments', [App\Http\Controllers\Web\Instructor\LessonCommentController::class, 'index'])->name('comments.index');
        Route::get('/comments/{comment}', [App\Http\Controllers\Web\Instructor\LessonCommentController::class, 'show'])->name('comments.show');

        Route::post('/reviews/{review}/reply', [ReviewReplyController::class, 'store'])->middleware('throttle:12,1')->name('reviews.reply');
        Route::put('/replies/{review}', [ReviewReplyController::class, 'update'])->middleware('throttle:12,1')->name('replies.update');
        Route::delete('/replies/{review}', [ReviewReplyController::class, 'destroy'])->name('replies.destroy');
        Route::post('/courses', [InstructorCourseController::class, 'store'])->name('courses.store');
        Route::post('/courses/{course}/s3/multipart/create', [S3MultipartUploadController::class, 'create'])->name('courses.s3.multipart.create');
        Route::post('/courses/{course}/s3/multipart/batch-sign', [S3MultipartUploadController::class, 'batchSignParts'])->name('courses.s3.multipart.batch-sign');
        Route::post('/courses/{course}/s3/multipart/sign-part', [S3MultipartUploadController::class, 'getPartUrl'])->name('courses.s3.multipart.sign-part');
        Route::post('/courses/{course}/s3/multipart/complete', [S3MultipartUploadController::class, 'complete'])->name('courses.s3.multipart.complete');
        Route::post('/courses/{course}/s3/multipart/abort', [S3MultipartUploadController::class, 'abort'])->name('courses.s3.multipart.abort');
        Route::get('/courses/{course}/hls-status', [InstructorCurriculumController::class, 'getHlsStatus'])->name('courses.hls-status');
        Route::post('/courses/{course}/sections', [InstructorCurriculumController::class, 'storeSection'])->name('courses.sections.store');
        Route::put('/courses/{course}/sections/{section}', [InstructorCurriculumController::class, 'updateSection'])->name('courses.sections.update');
        Route::delete('/courses/{course}/sections/{section}', [InstructorCurriculumController::class, 'destroySection'])->name('courses.sections.destroy');
        Route::post('/courses/{course}/sections/{section}/lessons', [InstructorCurriculumController::class, 'storeLesson'])->name('courses.sections.lessons.store');
        Route::get('/courses/{course}/lessons/import/template', [InstructorLessonImportController::class, 'downloadTemplate'])->name('courses.lessons.import.template');
        Route::post('/courses/{course}/sections/{section}/lessons/import/preview', [InstructorLessonImportController::class, 'preview'])->name('courses.lessons.import.preview');
        Route::post('/courses/{course}/sections/{section}/lessons/import/confirm', [InstructorLessonImportController::class, 'confirm'])->name('courses.lessons.import.confirm');
        Route::get('/courses/{course}/sections/{section}/lessons', function (Course $course, CourseSection $section) {
            $targetCourse = (int) $section->course_id === (int) $course->id
                ? $course
                : $section->course_id;

            return redirect()
                ->route('instructor.courses.curriculum', $targetCourse)
                ->with('error', (int) $section->course_id === (int) $course->id
                    ? null
                    : 'Liên kết chương học không khớp khóa học. Hệ thống đã chuyển đến đúng khóa học.');
        });
        Route::put('/courses/{course}/lessons/{lesson}', [InstructorCurriculumController::class, 'updateLesson'])->name('courses.lessons.update');
        Route::delete('/courses/{course}/lessons/{lesson}', [InstructorCurriculumController::class, 'destroyLesson'])->name('courses.lessons.destroy');
        Route::put('/courses/{course}/content-updates/{contentUpdate}', [InstructorCurriculumController::class, 'updateContentUpdate'])->name('courses.content-updates.update');
        Route::delete('/courses/{course}/content-updates/{contentUpdate}', [InstructorCurriculumController::class, 'destroyContentUpdate'])->name('courses.content-updates.destroy');
        Route::post('/courses/{course}/content-updates/{contentUpdate}/revise', [InstructorCurriculumController::class, 'reviseRejectedContentUpdate'])->name('courses.content-updates.revise');
        Route::post('/courses/{course}/lessons/{lesson}/quiz', [InstructorQuizController::class, 'store'])->name('courses.lessons.quiz.store');
        Route::get('/quizzes/questions/sample-template', [InstructorQuizController::class, 'downloadSampleTemplate'])->name('quizzes.questions.sample-template');
        Route::post('/quizzes/{quiz}/import-questions', [InstructorQuizController::class, 'importQuestions'])->name('quizzes.questions.import');
        Route::post('/quizzes/{quiz}/questions', [InstructorQuizController::class, 'storeQuestion'])->name('quizzes.questions.store');
        Route::post('/quiz-version-questions/{mapping}/invalidations', [InstructorQuizQuestionInvalidationController::class, 'store'])->name('quiz-version-questions.invalidations.store');
        Route::put('/quiz-questions/{question}', [InstructorQuizController::class, 'updateQuestion'])->name('quiz-questions.update');
        Route::delete('/quiz-questions/{question}', [InstructorQuizController::class, 'destroyQuestion'])->name('quiz-questions.destroy');
        Route::post('/quiz-questions/{question}/answers', [InstructorQuizController::class, 'storeAnswer'])->name('quiz-questions.answers.store');
        Route::put('/quiz-questions/{question}/answers', [InstructorQuizController::class, 'updateAnswers'])->name('quiz-questions.answers.update');
        Route::put('/quiz-answers/{answer}', [InstructorQuizController::class, 'updateAnswer'])->name('quiz-answers.update');
        Route::delete('/quiz-answers/{answer}', [InstructorQuizController::class, 'destroyAnswer'])->name('quiz-answers.destroy');
        Route::put('/courses/{course}', [InstructorCourseController::class, 'update'])->name('courses.update');
        Route::delete('/courses/{course}', [InstructorCourseController::class, 'destroy'])->name('courses.destroy');
        Route::post('/courses/{course}/archive', [InstructorCourseController::class, 'archive'])->name('courses.archive');
        Route::post('/courses/{course}/toggle-featured', [InstructorCourseController::class, 'toggleFeatured'])->name('courses.toggle-featured');
        Route::post('/courses/{course}/chapters', [InstructorCourseController::class, 'addChapter'])->name('courses.chapters.store');
        Route::get('/courses/{course}/submit', [InstructorCourseController::class, 'submitPage'])->name('courses.submit.page');
        Route::post('/courses/{course}/submit', [InstructorCourseController::class, 'submit'])->name('courses.submit');
        Route::post('/chapters/{chapter}/lessons', [InstructorCourseController::class, 'addLesson'])->name('chapters.lessons.store');
    });
});

// ─── ADMIN ───
Route::middleware(['auth', 'active', 'verified', '2fa', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [UserController::class, 'index'])->name('users');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::post('/users/bulk', [UserController::class, 'bulk'])->name('users.bulk');
    Route::get('/users/export/csv', [UserController::class, 'exportCsv'])->name('users.export.csv');
    Route::get('/users/export/pdf', [UserController::class, 'exportPdf'])->name('users.export.pdf');
    Route::post('/users/import', [UserController::class, 'import'])->name('users.import');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{user}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::delete('/users/{user}/force', [UserController::class, 'forceDelete'])->name('users.force-delete');
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    // Quản lý duyệt Giảng viên
    Route::get('/instructors/statistics', [InstructorApplicationController::class, 'statistics'])->name('instructors.statistics');
    Route::get('/instructors/supplements', [InstructorApplicationController::class, 'supplements'])->name('instructors.supplements.index');
    Route::prefix('instructors/applications')->name('instructors.applications.')->group(function () {
        Route::get('/', [InstructorApplicationController::class, 'index'])->name('index');
        Route::get('/{user}', [InstructorApplicationController::class, 'show'])->name('show');
        Route::get('/{user}/certificate', [InstructorApplicationController::class, 'viewCertificate'])->name('certificate');
        Route::get('/certificates/{certificate}/view', [InstructorApplicationController::class, 'viewCertificateItem'])->name('certificates.view');
        Route::post('/{user}/documents/{certificate}/review', [InstructorApplicationController::class, 'reviewDocument'])->name('documents.review');
        Route::post('/{user}/reactivation/approve', [InstructorApplicationController::class, 'approveReactivation'])->name('reactivation.approve');
        Route::post('/{user}/reactivation/reject', [InstructorApplicationController::class, 'rejectReactivation'])->name('reactivation.reject');
        Route::post('/{user}/approve', [InstructorApplicationController::class, 'approve'])->name('approve');
        Route::post('/{user}/reject', [InstructorApplicationController::class, 'reject'])->name('reject');
    });

    Route::prefix('instructors/teaching-fields')->name('instructors.teaching-fields.')->group(function () {
        Route::get('/', [InstructorTeachingFieldReviewController::class, 'index'])->name('index');
        Route::post('/{teachingField}/approve', [InstructorTeachingFieldReviewController::class, 'approve'])->name('approve');
        Route::post('/{teachingField}/reject', [InstructorTeachingFieldReviewController::class, 'reject'])->name('reject');
    });

    // Quản lý cấu hình yêu cầu hồ sơ theo ngành
    Route::prefix('instructors/requirements')->name('instructors.requirements.')->group(function () {
        Route::get('/', [InstructorDocumentRequirementController::class, 'index'])->name('index');
        Route::post('/', [InstructorDocumentRequirementController::class, 'store'])->name('store');
        Route::put('/{requirement}', [InstructorDocumentRequirementController::class, 'update'])->name('update');
        Route::post('/{requirement}/toggle-status', [InstructorDocumentRequirementController::class, 'toggleStatus'])->name('toggle-status');
        Route::delete('/{requirement}', [InstructorDocumentRequirementController::class, 'destroy'])->name('destroy');
    });
    Route::get('/categories', [AdminCategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/create', [AdminCategoryController::class, 'create'])->name('categories.create');
    Route::post('/categories', [AdminCategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}/edit', [AdminCategoryController::class, 'edit'])->name('categories.edit');
    Route::put('/categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');
    Route::post('/categories/{category}/toggle-status', [AdminCategoryController::class, 'toggleStatus'])->name('categories.toggle-status');
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy');
    // Cấu hình phần thưởng TOP Tháng & Tuần & Lịch sử trao thưởng
    Route::get('coupons/reward-config', [AdminCouponController::class, 'rewardConfigForm'])->name('coupons.reward_config');
    Route::post('coupons/reward-config', [AdminCouponController::class, 'rewardConfigStore'])->name('coupons.reward_config.store');
    Route::post('coupons/reward-run-now', [AdminCouponController::class, 'rewardRunNow'])->name('coupons.reward_run_now');
    Route::post('coupons/reward-weekly-run-now', [AdminCouponController::class, 'rewardWeeklyRunNow'])->name('coupons.reward_weekly_run_now');
    Route::get('coupons/reward-history', [AdminCouponController::class, 'rewardHistory'])->name('coupons.reward_history');

    Route::resource('coupons', AdminCouponController::class);
    Route::post('coupons/{coupon}/toggle-status', [AdminCouponController::class, 'toggleStatus'])->name('coupons.toggle-status');

    Route::resource('learning-paths', AdminLearningPathController::class);
    Route::get('/courses', [ManageController::class, 'index'])->name('courses.index');
    Route::get('/course-reviews', [CourseReviewController::class, 'index'])->name('course-reviews.index');
    Route::get('/course-reviews/{course}', [CourseReviewController::class, 'show'])->name('course-reviews.show');
    Route::post('/course-reviews/{course}/approve', [CourseReviewController::class, 'approve'])->name('course-reviews.approve');
    Route::post('/course-reviews/{course}/reject', [CourseReviewController::class, 'reject'])->name('course-reviews.reject');
    Route::get('/content-updates', [ContentUpdateController::class, 'index'])->name('content-updates.index');
    Route::get('/content-updates/{contentUpdate}', [ContentUpdateController::class, 'show'])->name('content-updates.show');
    Route::post('/content-updates/{contentUpdate}/approve', [ContentUpdateController::class, 'approve'])->name('content-updates.approve');
    Route::post('/content-updates/{contentUpdate}/reject', [ContentUpdateController::class, 'reject'])->name('content-updates.reject');
    Route::get('/courses/{course}/versions', [AdminContentVersionHistoryController::class, 'index'])->name('courses.versions.index');
    Route::get('/courses/{course}/versions/{type}/{version}', [AdminContentVersionHistoryController::class, 'show'])->name('courses.versions.show');
    Route::get('/courses/{course}/versions/{type}/{version}/compare', [AdminContentVersionHistoryController::class, 'compare'])->name('courses.versions.compare');
    Route::get('/quiz-invalidations', [QuizQuestionInvalidationController::class, 'index'])->name('quiz-invalidations.index');
    Route::get('/quiz-invalidations/{invalidation}', [QuizQuestionInvalidationController::class, 'show'])->name('quiz-invalidations.show');
    Route::post('/quiz-invalidations/{invalidation}/approve', [QuizQuestionInvalidationController::class, 'approve'])->name('quiz-invalidations.approve');
    Route::post('/quiz-invalidations/{invalidation}/reject', [QuizQuestionInvalidationController::class, 'reject'])->name('quiz-invalidations.reject');
    Route::get('/student-reviews', [AdminStudentReviewController::class, 'index'])->name('student-reviews.index');
    Route::get('/student-reviews/{review}', [AdminStudentReviewController::class, 'show'])->name('student-reviews.show');
    Route::patch('/student-reviews/{review}/hide', [AdminStudentReviewController::class, 'hide'])->name('student-reviews.hide');
    Route::patch('/student-reviews/{review}/restore', [AdminStudentReviewController::class, 'restore'])->name('student-reviews.restore');
    Route::delete('/student-reviews/{review}', [AdminStudentReviewController::class, 'destroy'])->name('student-reviews.destroy');
    Route::post('/replies/{review}/toggle-hide', [ManageController::class, 'toggleHideReply'])->name('replies.toggleHide');
    Route::get('/courses/pending', fn () => redirect()->route('admin.course-reviews.index'))->name('courses.pending');
    Route::get('/courses/{course}/review', [ManageController::class, 'review'])->name('courses.review');
    Route::get('/courses/{course}/students', [ManageController::class, 'students'])->name('courses.students');
    Route::post('/courses/{course}/approve', [ManageController::class, 'approve'])->name('courses.approve');
    Route::post('/courses/{course}/reject', [ManageController::class, 'reject'])->name('courses.reject');
    Route::post('/courses/{course}/review', [ManageController::class, 'submitReview'])->name('courses.submitReview');
    Route::post('/courses/{course}/lessons/{lesson}/note', [ManageController::class, 'saveLessonNote'])->name('courses.lessons.saveNote');
    Route::post('/courses/{course}/publish', [ManageController::class, 'publish'])->name('courses.publish');
    Route::post('/ai-moderation/{lesson}/extract', [AiModerationController::class, 'extractFrames'])->name('ai-moderation.extract');
    Route::post('/ai-moderation/analyze-frame', [AiModerationController::class, 'analyzeFrame'])->name('ai-moderation.analyze-frame');
    Route::post('/ai-moderation/{lesson}/category-match', [AiModerationController::class, 'checkCategoryMatch'])->name('ai-moderation.category-match');
    Route::post('/ai-moderation/{lesson}/save', [AiModerationController::class, 'saveResults'])->name('ai-moderation.save');
    Route::post('/courses/{course}/archive', [ManageController::class, 'archive'])->name('courses.archive');
    Route::post('/courses/{course}/restore', [ManageController::class, 'restore'])->name('courses.restore');
    Route::post('/courses/{course}/toggle-featured', [ManageController::class, 'toggleFeatured'])->name('courses.toggle-featured');
    Route::get('/courses/{course}', [ManageController::class, 'show'])->name('courses.show');
    Route::get('/revenue', [ManageController::class, 'revenue'])->name('revenue');
    Route::get('/commissions', [CommissionController::class, 'index'])->name('commissions.index');
    Route::post('/commissions/default-rate', [CommissionController::class, 'updateDefaultRate'])->name('commissions.update-default');
    Route::put('/commissions/instructors/{user}', [CommissionController::class, 'updateInstructorRate'])->name('commissions.update-instructor');
    Route::get('/withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals.index');
    Route::post('/withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve'])->middleware('throttle:20,1')->name('withdrawals.approve');
    Route::post('/withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject'])->middleware('throttle:20,1')->name('withdrawals.reject');
    Route::get('/refunds', [AdminRefundController::class, 'index'])->name('refunds.index');
    Route::get('/refunds/{refund}', [AdminRefundController::class, 'show'])->name('refunds.show');
    Route::post('/refunds/{refund}/approve', [AdminRefundController::class, 'approve'])->middleware('throttle:20,1')->name('refunds.approve');
    Route::post('/refunds/{refund}/reject', [AdminRefundController::class, 'reject'])->middleware('throttle:20,1')->name('refunds.reject');
    Route::get('/activity-logs', [ManageController::class, 'activityLogs'])->name('activity-logs');
    Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications', [AdminNotificationController::class, 'store'])->name('notifications.store');
    Route::get('/support-tickets', [AdminSupportTicketController::class, 'index'])->name('support-tickets.index');
    Route::get('/support-tickets/{ticket}', [AdminSupportTicketController::class, 'show'])->name('support-tickets.show');
    Route::post('/support-tickets/{ticket}/replies', [AdminSupportTicketController::class, 'reply'])->middleware('throttle:30,1')->name('support-tickets.reply');
    Route::patch('/support-tickets/{ticket}', [AdminSupportTicketController::class, 'update'])->name('support-tickets.update');
    Route::get('/support-tickets/{ticket}/attachments/{attachment}', [AdminSupportTicketController::class, 'downloadAttachment'])->name('support-tickets.attachments.download');
    Route::get('/homepage', [ManageController::class, 'homepage'])->name('homepage');
    Route::put('/homepage', [ManageController::class, 'updateHomepage'])->name('homepage.update');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

// ─── STREAMING VIDEO KIỂM DUYỆT (Admin & Giảng viên) ───
Route::middleware(['auth', 'active', 'verified', '2fa', 'role:admin,instructor'])->group(function () {
    Route::get('/admin/ai-moderation/{lesson}/stream-video', [AiModerationController::class, 'streamVideo'])->name('admin.ai-moderation.stream-video');
    Route::get('/admin/ai-moderation/{lesson}/hls/playlist.m3u8', [AiModerationController::class, 'streamHlsPlaylist'])->name('admin.ai-moderation.hls.playlist');
    Route::get('/admin/ai-moderation/{lesson}/hls/{segment}', [AiModerationController::class, 'streamHlsSegment'])->name('admin.ai-moderation.hls.segment');
});

if (app()->environment('local')) {
    Route::get('/dev/login-as-admin', function () {
        auth()->login(User::where('role', 'admin')->firstOrFail());

        return redirect()->route('admin.dashboard');
    })->name('dev.login-as-admin');

    Route::get('/dev/login-as-student', function () {
        $user = User::where('role', 'student')->first()
            ?? User::firstOrFail();

        auth()->login($user);

        return redirect()->route('dashboard');
    })->name('dev.login-as-student');

}

// ─── CỔNG THANH TOÁN THỰC TẾ (REAL PAYMENT GATEWAYS) ───
Route::post('/payments/payos/ipn', [PaymentController::class, 'payosIpn'])->name('payments.payos.ipn');
Route::post('/payments/momo/ipn', [PaymentController::class, 'momoIpn'])->name('payments.momo.ipn');
Route::get('/payments/momo/return', [PaymentController::class, 'momoReturn'])->name('payments.momo.return');
