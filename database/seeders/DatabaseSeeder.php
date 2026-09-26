<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Tắt ràng buộc khóa ngoại để tiến hành dọn dẹp (truncate) cơ sở dữ liệu
        Schema::disableForeignKeyConstraints();

        // Danh sách toàn bộ các bảng trong hệ thống cần dọn dẹp trước khi seed dữ liệu mới
        $tables = [
            'activity_logs',
            'user_badges',
            'user_points',
            'certificates',
            'enrollments',
            'payments',
            'order_items',
            'orders',
            'wishlists',
            'cart_items',
            'carts',
            'discussion_replies',
            'discussions',
            'submissions',
            'assignments',
            'quiz_attempt_answers',
            'quiz_attempts',
            'quiz_version_questions',
            'question_versions',
            'quiz_versions',
            'quiz_options',
            'quiz_questions',
            'quizzes',
            'ai_summaries',
            'lesson_subtitles',
            'lesson_attachments',
            'lessons',
            'chapters',
            'course_sections',
            'learning_path_courses',
            'learning_paths',
            'courses',
            'faqs',
            'coupons',
            'user_coupons',
            'badges',
            'categories',
            'instructor_certificates',
            'instructor_document_requirements',
            'instructor_profile_teaching_fields',
            'instructor_applications',
            'instructor_profiles',
            'users',
            'homepage_settings',
            'push_notifications',
            'recently_viewed_courses',
            'live_sessions',
            'study_groups',
            'video_notes',
            'ai_chat_messages',
            'support_tickets',
            'support_ticket_messages',
            'reviews',
            'lesson_progress',
            'course_reviews',
            'quiz_version_question_invalidations',
            'quiz_attempt_regrades',
        ];

        foreach ($tables as $table) {
            // Kiểm tra bảng tồn tại để tránh phát sinh lỗi nếu chưa chạy đầy đủ migrations
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        // Kích hoạt lại ràng buộc khóa ngoại sau khi đã dọn dẹp sạch sẽ
        Schema::enableForeignKeyConstraints();

        // 2. Thực thi các Seeder theo trình tự logic khóa ngoại (Dependencies)
        $this->call([
            // Bảng danh mục nền tảng (Phải chạy đầu tiên)
            CategorySeeder::class,

            // Bảng người dùng, giảng viên & học viên cơ bản
            UserSeeder::class,

            // Yêu cầu hồ sơ chứng chỉ giảng viên
            InstructorDocumentRequirementSeeder::class,

            // Nạp 200+ hồ sơ Giảng viên toàn diện kèm Chứng chỉ (T1-T9/2026) [Tùy chọn cho stress-test/demo]
            // InstructorDataSeeder::class,

            // Cấu hình hệ thống & Tiện ích
            BadgeSeeder::class,
            CouponSeeder::class,
            FaqSeeder::class,
            HomepageSettingSeeder::class,

            // Nạp 100.000+ Người dùng & Tiến độ học tập biến động (T1/2025 - T8/2026) [Dữ liệu ảo demo]
            // LargeScaleUserSeeder::class,

            // Nạp đánh giá khóa học 4-5 sao & bình luận bài học [Dữ liệu ảo demo]
            // StudentReviewSeeder::class,

            // Nạp Doanh thu biến động chuẩn xác 3.582.962.000 VNĐ (T1/2025 - T8/2026) [Dữ liệu ảo demo]
            // RevenueOrderSeeder::class,

            // Lộ trình học tập
            LearningPathSeeder::class,
        ]);

        // 3. Tự động đồng bộ course_sections và cập nhật lessons (course_id, section_id) từ chapters
        $this->backfillCourseSections();

        // 4. Tạo đăng ký khóa học mẫu cho học sinh
        $this->seedEnrollments();
    }

    /**
     * Tự động đồng bộ hóa dữ liệu từ chapters sang course_sections và cập nhật lessons
     */
    private function backfillCourseSections(): void
    {
        echo "\n========== ĐỒNG BỘ HÓA COURSE SECTIONS ==========\n";

        $chapters = DB::table('chapters')->orderBy('id')->get();

        foreach ($chapters as $chapter) {
            $section = DB::table('course_sections')
                ->where('course_id', $chapter->course_id)
                ->where('sort_order', $chapter->sort_order)
                ->first();

            if (! $section) {
                $sectionId = DB::table('course_sections')->insertGetId([
                    'course_id' => $chapter->course_id,
                    'title' => $chapter->title,
                    'description' => null,
                    'sort_order' => $chapter->sort_order,
                    'created_at' => $chapter->created_at ?? now(),
                    'updated_at' => $chapter->updated_at ?? now(),
                ]);
            } else {
                $sectionId = $section->id;
            }

            DB::table('lessons')
                ->where('chapter_id', $chapter->id)
                ->update([
                    'course_id' => $chapter->course_id,
                    'section_id' => $sectionId,
                    'duration' => DB::raw('COALESCE(duration, duration_seconds)'),
                ]);
        }

        echo '✓ Đồng bộ thành công '.count($chapters)." chương học sang course_sections!\n\n";
    }

    /**
     * Tạo đăng ký khóa học mẫu cho học sinh
     */
    private function seedEnrollments(): void
    {
        echo "\n========== TẠO ĐĂNG KÝ KHÓA HỌC MẪU (ENROLLMENTS) ==========\n";

        $student1 = DB::table('users')->where('email', 'student@example.com')->value('id');
        $student2 = DB::table('users')->where('email', 'student2@example.com')->value('id');
        $student3 = DB::table('users')->where('email', 'student3@example.com')->value('id');
        $student4 = DB::table('users')->where('email', 'student4@example.com')->value('id');
        $qtrung = DB::table('users')->where('email', 'tungazquoc@gmail.com')->value('id');

        $courses = DB::table('courses')->where('status', 'published')->pluck('id')->all();
        if (empty($courses)) {
            return;
        }

        $c1 = $courses[0] ?? 1;
        $c2 = $courses[1] ?? 2;
        $c3 = $courses[2] ?? 3;
        $c4 = $courses[3] ?? 4;
        $c5 = $courses[4] ?? 5;

        $enrollments = [];

        if ($student1) {
            $enrollments[] = [
                'user_id' => $student1,
                'course_id' => $c1,
                'status' => 'active',
                'progress_percent' => 35.00,
                'enrolled_at' => now()->subDays(10),
                'created_at' => now()->subDays(10),
                'updated_at' => now(),
            ];
            $enrollments[] = [
                'user_id' => $student1,
                'course_id' => $c2,
                'status' => 'active',
                'progress_percent' => 10.00,
                'enrolled_at' => now()->subDays(5),
                'created_at' => now()->subDays(5),
                'updated_at' => now(),
            ];
            $enrollments[] = [
                'user_id' => $student1,
                'course_id' => $c3,
                'status' => 'active',
                'progress_percent' => 0.00,
                'enrolled_at' => now()->subDays(2),
                'created_at' => now()->subDays(2),
                'updated_at' => now(),
            ];
        }

        if ($student2) {
            $enrollments[] = [
                'user_id' => $student2,
                'course_id' => $c1,
                'status' => 'active',
                'progress_percent' => 0.00,
                'enrolled_at' => now()->subDays(3),
                'created_at' => now()->subDays(3),
                'updated_at' => now(),
            ];
            $enrollments[] = [
                'user_id' => $student2,
                'course_id' => $c2,
                'status' => 'active',
                'progress_percent' => 45.00,
                'enrolled_at' => now()->subDays(6),
                'created_at' => now()->subDays(6),
                'updated_at' => now(),
            ];
        }

        if ($student3) {
            $enrollments[] = [
                'user_id' => $student3,
                'course_id' => $c1,
                'status' => 'active',
                'progress_percent' => 60.00,
                'enrolled_at' => now()->subDays(8),
                'created_at' => now()->subDays(8),
                'updated_at' => now(),
            ];
        }

        if ($student4) {
            $enrollments[] = [
                'user_id' => $student4,
                'course_id' => $c1,
                'status' => 'active',
                'progress_percent' => 15.00,
                'enrolled_at' => now()->subDays(4),
                'created_at' => now()->subDays(4),
                'updated_at' => now(),
            ];
        }

        if ($qtrung) {
            $enrollments[] = [
                'user_id' => $qtrung,
                'course_id' => $c1,
                'status' => 'active',
                'progress_percent' => 25.00,
                'enrolled_at' => now()->subDays(10),
                'created_at' => now()->subDays(10),
                'updated_at' => now(),
            ];
            $enrollments[] = [
                'user_id' => $qtrung,
                'course_id' => $c2,
                'status' => 'active',
                'progress_percent' => 50.00,
                'enrolled_at' => now()->subDays(7),
                'created_at' => now()->subDays(7),
                'updated_at' => now(),
            ];
            $enrollments[] = [
                'user_id' => $qtrung,
                'course_id' => $c3,
                'status' => 'active',
                'progress_percent' => 0.00,
                'enrolled_at' => now()->subDays(5),
                'created_at' => now()->subDays(5),
                'updated_at' => now(),
            ];
            $enrollments[] = [
                'user_id' => $qtrung,
                'course_id' => $c4,
                'status' => 'active',
                'progress_percent' => 10.00,
                'enrolled_at' => now()->subDays(3),
                'created_at' => now()->subDays(3),
                'updated_at' => now(),
            ];
            $enrollments[] = [
                'user_id' => $qtrung,
                'course_id' => $c5,
                'status' => 'active',
                'progress_percent' => 0.00,
                'completed_lessons' => 0,
                'total_lessons' => DB::table('lessons')->where('course_id', $c5)->count() ?: 12,
                'enrolled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $validCourseIds = DB::table('courses')->pluck('id')->all();

        foreach ($enrollments as $enrollment) {
            if (in_array($enrollment['course_id'], $validCourseIds, true)) {
                DB::table('enrollments')->updateOrInsert(
                    ['user_id' => $enrollment['user_id'], 'course_id' => $enrollment['course_id']],
                    $enrollment
                );
            }
        }

        echo "✓ Đã đăng ký thành công các khóa học mẫu cho học sinh!\n\n";
    }
}
