<?php
/**
 * Script dọn dẹp toàn bộ dữ liệu ảo (Virtual / Dummy / Seeded Data)
 * Giữ lại dữ liệu thật và dữ liệu chuẩn bài bản:
 * - Giữ lại 43 tài khoản hạt nhân (Admin, Giảng viên gốc, Qtrung, ...)
 * - Giữ lại 36 khóa học chuẩn (IDs 1-36) + các khóa học tự tạo thật (Node.js IDs 40137-40139)
 * - Giữ lại Master data: categories (121), roles, permissions, badges, coupons, settings, learning_paths...
 * - Xóa 104.360 học viên ảo + 280 giảng viên ảo (@onlinefea.edu.vn)
 * - Xóa 20.000 khóa học ảo nạp hàng loạt (bulk-course-*) + 1 khóa học test nháp (quertyjk)
 * - Xóa toàn bộ 3.280 đơn hàng ảo, 1.110 đánh giá ảo, 34.412 chứng chỉ ảo, 104.000 enrollments ảo
 */

require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$startTime = microtime(true);
echo "=================================================================\n";
echo "   BẮT ĐẦU DỌN DẸP TOÀN BỘ DỮ LIỆU ẢO - GIỮ LẠI DỮ LIỆU THẬT\n";
echo "=================================================================\n\n";

DB::statement('SET FOREIGN_KEY_CHECKS=0;');

// =============================================================
// BƯỚC 1: XÁC ĐỊNH VÀ DỌN DẸP KHÓA HỌC ẢO (20.000 BULK + QUERTYJK)
// =============================================================
echo "--- BƯỚC 1: Dọn dẹp khóa học ảo & nội dung liên quan ---\n";

DB::statement("DROP TEMPORARY TABLE IF EXISTS temp_del_courses;");
DB::statement("
    CREATE TEMPORARY TABLE temp_del_courses (
        id BIGINT UNSIGNED PRIMARY KEY
    ) AS
    SELECT id FROM courses WHERE slug LIKE 'bulk-course-%' OR slug = 'quertyjk';
");

$bulkCoursesCount = DB::table('temp_del_courses')->count();
echo "▶ Số khóa học ảo cần xóa: {$bulkCoursesCount}\n";

if ($bulkCoursesCount > 0) {
    // 1.1 Lập bảng tạm bài học (lessons)
    DB::statement("DROP TEMPORARY TABLE IF EXISTS temp_del_lessons;");
    DB::statement("
        CREATE TEMPORARY TABLE temp_del_lessons (
            id BIGINT UNSIGNED PRIMARY KEY
        ) AS
        SELECT id FROM lessons WHERE course_id IN (SELECT id FROM temp_del_courses);
    ");
    $lessonsCount = DB::table('temp_del_lessons')->count();
    echo "  - Tìm thấy {$lessonsCount} bài học thuộc khóa học ảo\n";

    // 1.2 Lập bảng tạm trắc nghiệm (quizzes)
    DB::statement("DROP TEMPORARY TABLE IF EXISTS temp_del_quizzes;");
    DB::statement("
        CREATE TEMPORARY TABLE temp_del_quizzes (
            id BIGINT UNSIGNED PRIMARY KEY
        ) AS
        SELECT id FROM quizzes WHERE lesson_id IN (SELECT id FROM temp_del_lessons);
    ");
    $quizzesCount = DB::table('temp_del_quizzes')->count();
    echo "  - Tìm thấy {$quizzesCount} bài trắc nghiệm (quizzes) thuộc khóa học ảo\n";

    // 1.3 Lập bảng tạm câu hỏi (quiz_questions)
    DB::statement("DROP TEMPORARY TABLE IF EXISTS temp_del_questions;");
    DB::statement("
        CREATE TEMPORARY TABLE temp_del_questions (
            id BIGINT UNSIGNED PRIMARY KEY
        ) AS
        SELECT id FROM quiz_questions WHERE quiz_id IN (SELECT id FROM temp_del_quizzes);
    ");
    $questionsCount = DB::table('temp_del_questions')->count();
    echo "  - Tìm thấy {$questionsCount} câu hỏi trắc nghiệm\n";

    // 1.4 Xóa quiz options
    if (Schema::hasTable('quiz_options')) {
        $deleted = DB::delete("DELETE FROM quiz_options WHERE quiz_question_id IN (SELECT id FROM temp_del_questions)");
        echo "  ✓ Đã xóa {$deleted} phương án trắc nghiệm (quiz_options)\n";
    }

    // 1.5 Xóa question versions & quiz version questions
    if (Schema::hasTable('question_versions')) {
        $deleted = DB::delete("DELETE FROM question_versions WHERE question_id IN (SELECT id FROM temp_del_questions)");
        echo "  ✓ Đã xóa {$deleted} question_versions\n";
    }
    if (Schema::hasTable('quiz_version_questions')) {
        $deleted = DB::delete("DELETE FROM quiz_version_questions WHERE question_id IN (SELECT id FROM temp_del_questions)");
        echo "  ✓ Đã xóa {$deleted} quiz_version_questions\n";
    }

    // 1.6 Xóa quiz versions
    if (Schema::hasTable('quiz_versions')) {
        $deleted = DB::delete("DELETE FROM quiz_versions WHERE quiz_id IN (SELECT id FROM temp_del_quizzes)");
        echo "  ✓ Đã xóa {$deleted} quiz_versions\n";
    }

    // 1.7 Xóa quiz questions & quizzes
    if (Schema::hasTable('quiz_questions')) {
        $deleted = DB::delete("DELETE FROM quiz_questions WHERE id IN (SELECT id FROM temp_del_questions)");
        echo "  ✓ Đã xóa {$deleted} quiz_questions\n";
    }
    if (Schema::hasTable('quizzes')) {
        $deleted = DB::delete("DELETE FROM quizzes WHERE id IN (SELECT id FROM temp_del_quizzes)");
        echo "  ✓ Đã xóa {$deleted} quizzes\n";
    }

    // 1.8 Xóa attachments, subtitles, notes của lessons
    if (Schema::hasTable('lesson_attachments')) {
        $deleted = DB::delete("DELETE FROM lesson_attachments WHERE lesson_id IN (SELECT id FROM temp_del_lessons)");
        echo "  ✓ Đã xóa {$deleted} lesson_attachments\n";
    }
    if (Schema::hasTable('lesson_subtitles')) {
        $deleted = DB::delete("DELETE FROM lesson_subtitles WHERE lesson_id IN (SELECT id FROM temp_del_lessons)");
        echo "  ✓ Đã xóa {$deleted} lesson_subtitles\n";
    }
    if (Schema::hasTable('lesson_notes')) {
        $deleted = DB::delete("DELETE FROM lesson_notes WHERE lesson_id IN (SELECT id FROM temp_del_lessons)");
        echo "  ✓ Đã xóa {$deleted} lesson_notes\n";
    }
    if (Schema::hasTable('lesson_comments')) {
        $deleted = DB::delete("DELETE FROM lesson_comments WHERE lesson_id IN (SELECT id FROM temp_del_lessons)");
        echo "  ✓ Đã xóa {$deleted} lesson_comments\n";
    }
    if (Schema::hasTable('lesson_progress')) {
        $deleted = DB::delete("DELETE FROM lesson_progress WHERE lesson_id IN (SELECT id FROM temp_del_lessons)");
        echo "  ✓ Đã xóa {$deleted} lesson_progress\n";
    }

    // 1.9 Xóa lessons
    if (Schema::hasTable('lessons')) {
        $deleted = DB::delete("DELETE FROM lessons WHERE id IN (SELECT id FROM temp_del_lessons)");
        echo "  ✓ Đã xóa {$deleted} bài học (lessons)\n";
    }

    // 1.10 Xóa course_sections & chapters
    if (Schema::hasTable('course_sections')) {
        $deleted = DB::delete("DELETE FROM course_sections WHERE course_id IN (SELECT id FROM temp_del_courses)");
        echo "  ✓ Đã xóa {$deleted} course_sections\n";
    }
    if (Schema::hasTable('chapters')) {
        $deleted = DB::delete("DELETE FROM chapters WHERE course_id IN (SELECT id FROM temp_del_courses)");
        echo "  ✓ Đã xóa {$deleted} chapters\n";
    }

    // 1.11 Xóa các bảng phụ trợ liên quan khóa học
    if (Schema::hasTable('learning_path_courses')) {
        $deleted = DB::delete("DELETE FROM learning_path_courses WHERE course_id IN (SELECT id FROM temp_del_courses)");
        echo "  ✓ Đã xóa {$deleted} learning_path_courses\n";
    }
    if (Schema::hasTable('recently_viewed_courses')) {
        $deleted = DB::delete("DELETE FROM recently_viewed_courses WHERE course_id IN (SELECT id FROM temp_del_courses)");
        echo "  ✓ Đã xóa {$deleted} recently_viewed_courses\n";
    }
    if (Schema::hasTable('wishlists')) {
        $deleted = DB::delete("DELETE FROM wishlists WHERE course_id IN (SELECT id FROM temp_del_courses)");
        echo "  ✓ Đã xóa {$deleted} wishlists\n";
    }
    if (Schema::hasTable('cart_items')) {
        $deleted = DB::delete("DELETE FROM cart_items WHERE course_id IN (SELECT id FROM temp_del_courses)");
        echo "  ✓ Đã xóa {$deleted} cart_items\n";
    }
    if (Schema::hasTable('video_moderations')) {
        $deleted = DB::delete("DELETE FROM video_moderations WHERE lesson_id IN (SELECT id FROM temp_del_lessons)");
        echo "  ✓ Đã xóa {$deleted} video_moderations\n";
    }
    if (Schema::hasTable('content_updates')) {
        $deleted = DB::delete("DELETE FROM content_updates WHERE course_id IN (SELECT id FROM temp_del_courses)");
        echo "  ✓ Đã xóa {$deleted} content_updates\n";
    }
    if (Schema::hasTable('full_course_import_batches')) {
        DB::table('full_course_import_batches')->truncate();
        echo "  ✓ Đã dọn dẹp full_course_import_batches\n";
    }

    // 1.12 Xóa chính các courses ảo
    $deleted = DB::delete("DELETE FROM courses WHERE id IN (SELECT id FROM temp_del_courses)");
    echo "  ✓ ĐÃ XÓA THÀNH CÔNG {$deleted} KHÓA HỌC ẢO (courses)!\n";

    // 1.13 Xóa thumbnail rác của khóa học test 40140 (quertyjk)
    $testThumb = storage_path('app/public/course-thumbnails/1QqbEuSMw8j1EqSbR1AW2RGZ5W8fd6Jf1PMLDDD7.jpg');
    if (file_exists($testThumb)) {
        unlink($testThumb);
        echo "  ✓ Đã xóa file thumbnail rác của khóa học test: 1QqbEuSMw8j1EqSbR1AW2RGZ5W8fd6Jf1PMLDDD7.jpg\n";
    }
}


// =============================================================
// BƯỚC 2: DỌN DẸP ĐƠN HÀNG, THANH TOÁN, ĐÁNH GIÁ, CHỨNG CHỈ ẢO
// =============================================================
echo "\n--- BƯỚC 2: Dọn dẹp đơn hàng, thanh toán, đánh giá & chứng chỉ ảo ---\n";

$tablesToTruncate = [
    'payments' => 'thanh toán (payments)',
    'order_items' => 'chi tiết đơn hàng (order_items)',
    'orders' => 'đơn hàng (orders)',
    'reviews' => 'đánh giá (reviews)',
    'course_reviews' => 'đánh giá khóa học (course_reviews)',
    'lesson_comments' => 'bình luận bài học (lesson_comments)',
    'certificates' => 'chứng chỉ cấp phát (certificates)',
    'enrollments' => 'lượt đăng ký khóa học (enrollments)',
    'lesson_progress' => 'tiến độ học tập (lesson_progress)',
    'quiz_attempts' => 'lượt làm trắc nghiệm (quiz_attempts)',
    'quiz_attempt_answers' => 'câu trả lời trắc nghiệm (quiz_attempt_answers)',
    'quiz_version_question_invalidations' => 'hủy câu hỏi (quiz_version_question_invalidations)',
    'quiz_attempt_regrades' => 'chấm lại trắc nghiệm (quiz_attempt_regrades)',
    'activity_logs' => 'nhật ký hoạt động (activity_logs)',
    'user_points' => 'điểm thưởng học viên (user_points)',
    'user_badges' => 'huy hiệu học viên (user_badges)',
    'withdrawals' => 'yêu cầu rút tiền (withdrawals)',
    'recently_viewed_courses' => 'khóa học đã xem gần đây (recently_viewed_courses)',
    'wishlists' => 'danh sách yêu thích (wishlists)',
    'cart_items' => 'mục giỏ hàng (cart_items)',
    'video_access_logs' => 'nhật ký xem video (video_access_logs)',
];

foreach ($tablesToTruncate as $tbl => $label) {
    if (Schema::hasTable($tbl)) {
        $count = DB::table($tbl)->count();
        DB::table($tbl)->truncate();
        echo "  ✓ Đã xóa sạch {$count} bản ghi trong {$label}\n";
    }
}

// Đặt lại các chỉ số đánh giá và thống kê của các khóa học còn lại về 0 chuẩn thực tế
DB::table('courses')->update([
    'rating_avg' => 0.00,
    'rating_count' => 0,
    'enrollment_count' => 0,
]);
echo "  ✓ Đã reset chỉ số rating_avg, rating_count, enrollment_count của các khóa học còn lại về 0.\n";


// =============================================================
// BƯỚC 3: DỌN DẸP TÀI KHOẢN NGƯỜI DÙNG & GIẢNG VIÊN ẢO (@onlinefea.edu.vn)
// =============================================================
echo "\n--- BƯỚC 3: Dọn dẹp tài khoản học viên & giảng viên ảo (@onlinefea.edu.vn) ---\n";

DB::statement("DROP TEMPORARY TABLE IF EXISTS temp_del_users;");
DB::statement("
    CREATE TEMPORARY TABLE temp_del_users (
        id BIGINT UNSIGNED PRIMARY KEY
    ) AS
    SELECT id FROM users WHERE email LIKE '%@onlinefea.edu.vn';
");

$fakeUsersCount = DB::table('temp_del_users')->count();
echo "▶ Số tài khoản ảo cần xóa (@onlinefea.edu.vn): {$fakeUsersCount}\n";

if ($fakeUsersCount > 0) {
    // 3.1 Dọn các bảng liên quan đến Instructor Profiles của user ảo
    DB::statement("DROP TEMPORARY TABLE IF EXISTS temp_del_instructor_profiles;");
    DB::statement("
        CREATE TEMPORARY TABLE temp_del_instructor_profiles (
            id BIGINT UNSIGNED PRIMARY KEY
        ) AS
        SELECT id FROM instructor_profiles WHERE user_id IN (SELECT id FROM temp_del_users);
    ");
    $instProfilesCount = DB::table('temp_del_instructor_profiles')->count();
    echo "  - Tìm thấy {$instProfilesCount} hồ sơ giảng viên ảo\n";

    if (Schema::hasTable('instructor_profile_teaching_fields')) {
        $deleted = DB::delete("DELETE FROM instructor_profile_teaching_fields WHERE instructor_profile_id IN (SELECT id FROM temp_del_instructor_profiles)");
        echo "  ✓ Đã xóa {$deleted} instructor_profile_teaching_fields\n";
    }

    if (Schema::hasTable('instructor_certificates')) {
        $deleted = DB::delete("DELETE FROM instructor_certificates WHERE user_id IN (SELECT id FROM temp_del_users)");
        echo "  ✓ Đã xóa {$deleted} instructor_certificates\n";
    }

    if (Schema::hasTable('instructor_applications')) {
        $deleted = DB::delete("DELETE FROM instructor_applications WHERE user_id IN (SELECT id FROM temp_del_users)");
        echo "  ✓ Đã xóa {$deleted} instructor_applications\n";
    }

    if (Schema::hasTable('instructor_profiles')) {
        $deleted = DB::delete("DELETE FROM instructor_profiles WHERE id IN (SELECT id FROM temp_del_instructor_profiles)");
        echo "  ✓ Đã xóa {$deleted} instructor_profiles ảo\n";
    }

    // 3.2 Dọn role_user của tài khoản ảo
    if (Schema::hasTable('role_user')) {
        $deleted = DB::delete("DELETE FROM role_user WHERE user_id IN (SELECT id FROM temp_del_users)");
        echo "  ✓ Đã xóa {$deleted} vai trò gán (role_user)\n";
    }

    // 3.3 Dọn các bảng phiên đăng nhập & xác thực
    if (Schema::hasTable('active_sessions')) {
        $deleted = DB::delete("DELETE FROM active_sessions WHERE user_id IN (SELECT id FROM temp_del_users)");
        echo "  ✓ Đã xóa {$deleted} active_sessions\n";
    }
    if (Schema::hasTable('two_factor_codes')) {
        $deleted = DB::delete("DELETE FROM two_factor_codes WHERE user_id IN (SELECT id FROM temp_del_users)");
        echo "  ✓ Đã xóa {$deleted} two_factor_codes\n";
    }
    if (Schema::hasTable('email_verification_codes')) {
        $deleted = DB::delete("DELETE FROM email_verification_codes WHERE user_id IN (SELECT id FROM temp_del_users)");
        echo "  ✓ Đã xóa {$deleted} email_verification_codes\n";
    }
    if (Schema::hasTable('study_group_members')) {
        $deleted = DB::delete("DELETE FROM study_group_members WHERE user_id IN (SELECT id FROM temp_del_users)");
        echo "  ✓ Đã xóa {$deleted} study_group_members\n";
    }
    if (Schema::hasTable('sessions')) {
        try {
            DB::delete("DELETE FROM sessions WHERE user_id IN (SELECT id FROM temp_del_users)");
        } catch (\Exception $e) {}
    }
    if (Schema::hasTable('ai_chat_messages')) {
        try {
            DB::delete("DELETE FROM ai_chat_messages WHERE user_id IN (SELECT id FROM temp_del_users)");
        } catch (\Exception $e) {}
    }
    if (Schema::hasTable('ai_conversations')) {
        try {
            DB::delete("DELETE FROM ai_conversations WHERE user_id IN (SELECT id FROM temp_del_users)");
        } catch (\Exception $e) {}
    }

    // 3.4 Xóa chính các tài khoản người dùng ảo trong users
    $deleted = DB::delete("DELETE FROM users WHERE id IN (SELECT id FROM temp_del_users)");
    echo "  ✓ ĐÃ XÓA THÀNH CÔNG {$deleted} TÀI KHOẢN NGƯỜI DÙNG ẢO!\n";
}

DB::statement('SET FOREIGN_KEY_CHECKS=1;');

// Xóa cache hệ thống
try {
    Illuminate\Support\Facades\Cache::flush();
    echo "  ✓ Đã xóa sạch application cache.\n";
} catch (\Exception $e) {}


// =============================================================
// BƯỚC 4: TỔNG KẾT & KIỂM TRA DỮ LIỆU CÒN LẠI
// =============================================================
echo "\n=================================================================\n";
echo "   KẾT QUẢ KIỂM TRA DỮ LIỆU SAU KHI DỌN DẸP\n";
echo "=================================================================\n";

$remainingUsers = DB::table('users')->count();
$remainingCourses = DB::table('courses')->count();
$remainingChapters = DB::table('chapters')->count();
$remainingLessons = DB::table('lessons')->count();
$remainingSections = DB::table('course_sections')->count();
$remainingOrders = DB::table('orders')->count();
$remainingPayments = DB::table('payments')->count();
$remainingReviews = DB::table('reviews')->count();
$remainingEnrollments = DB::table('enrollments')->count();
$remainingCategories = DB::table('categories')->count();

echo "• Tổng số tài khoản còn lại: {$remainingUsers} (Toàn bộ là tài khoản thật/gốc)\n";
echo "• Tổng số khóa học còn lại: {$remainingCourses} (36 khóa học chuẩn bài bản + các khóa Node.js tự tạo)\n";
echo "• Tổng số chương học (chapters): {$remainingChapters}\n";
echo "• Tổng số bài học (lessons): {$remainingLessons}\n";
echo "• Tổng số sections: {$remainingSections}\n";
echo "• Tổng số đơn hàng: {$remainingOrders}\n";
echo "• Tổng số thanh toán: {$remainingPayments}\n";
echo "• Tổng số đánh giá: {$remainingReviews}\n";
echo "• Tổng số lượt ghi danh: {$remainingEnrollments}\n";
echo "• Tổng số danh mục (Categories): {$remainingCategories}\n";

echo "\n--- Danh sách các khóa học còn lại ---\n";
$courses = DB::table('courses')->select('id', 'title', 'slug', 'instructor_id', 'status', 'created_at')->orderBy('id')->get();
foreach ($courses as $c) {
    echo "  [ID {$c->id}] {$c->title} | Slug: {$c->slug} | Trạng thái: {$c->status}\n";
}

echo "\n--- Danh sách người dùng còn lại (Toàn bộ tài khoản chuẩn) ---\n";
$users = DB::table('users')->select('id', 'name', 'email', 'role')->orderBy('id')->get();
foreach ($users as $u) {
    echo "  [ID {$u->id}] {$u->name} <{$u->email}> (Vai trò: {$u->role})\n";
}

$duration = round(microtime(true) - $startTime, 2);
echo "\nHoàn tất dọn dẹp trong {$duration} giây!\n";
