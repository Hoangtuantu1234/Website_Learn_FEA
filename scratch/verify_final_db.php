<?php
require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== TỔNG QUAN CƠ SỞ DỮ LIỆU HIỆN TẠI ===\n\n";

$tables = [
    'users' => 'Tài khoản người dùng',
    'courses' => 'Khóa học',
    'chapters' => 'Chương học (chapters)',
    'lessons' => 'Bài học (lessons)',
    'course_sections' => 'Phần học (sections)',
    'quizzes' => 'Bài kiểm tra (quizzes)',
    'categories' => 'Danh mục khóa học',
    'orders' => 'Đơn hàng',
    'order_items' => 'Chi tiết đơn hàng',
    'payments' => 'Giao dịch thanh toán',
    'reviews' => 'Đánh giá học viên',
    'certificates' => 'Chứng chỉ đã cấp',
    'enrollments' => 'Lượt ghi danh',
    'instructor_profiles' => 'Hồ sơ giảng viên',
    'instructor_applications' => 'Đơn đăng ký giảng viên',
    'badges' => 'Huy hiệu hệ thống',
    'coupons' => 'Mã giảm giá',
];

foreach ($tables as $tbl => $name) {
    $count = DB::table($tbl)->count();
    echo sprintf("%-35s : %d\n", $name, $count);
}

echo "\n--- DANH SÁCH KHÓA HỌC (39 KHÓA) ---\n";
$courses = DB::table('courses')->select('id', 'title', 'slug', 'instructor_id', 'status', 'rating_avg', 'enrollment_count')->get();
foreach ($courses as $c) {
    echo sprintf("[%2d] %-60s | %-10s | Đánh giá: %.1f | Học viên: %d\n", $c->id, mb_substr($c->title, 0, 58), $c->status, $c->rating_avg, $c->enrollment_count);
}

echo "\n--- PHÂN BỔ VAI TRÒ NGƯỜI DÙNG (43 TÀI KHOẢN CHUẨN) ---\n";
$roleCounts = DB::select("SELECT role, COUNT(*) as cnt FROM users GROUP BY role");
foreach ($roleCounts as $rc) {
    echo "Vai trò '{$rc->role}': {$rc->cnt} tài khoản\n";
}
