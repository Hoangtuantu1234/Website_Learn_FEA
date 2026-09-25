<?php

namespace Database\Seeders;

use App\Models\Course;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LargeScaleUserSeeder extends Seeder
{
    private array $vietnameseLastNames = [
        'Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Huỳnh', 'Phan', 'Vũ', 'Võ', 'Đặng',
        'Bùi', 'Đỗ', 'Hồ', 'Ngô', 'Dương', 'Lý', 'Đinh', 'Đoàn', 'Lâm', 'Trịnh',
        'Mai', 'Đào', 'Cao', 'Hà', 'Lưu', 'Lương', 'Thái', 'Châu', 'Tạ', 'Phùng',
    ];

    private array $vietnameseMiddleNames = [
        'Văn', 'Thị', 'Đức', 'Minh', 'Hải', 'Thanh', 'Quốc', 'Ngọc', 'Thu', 'Bảo',
        'Gia', 'Hồng', 'Tuấn', 'Mỹ', 'Khánh', 'Hoài', 'Xuân', 'Kim', 'Trọng', 'Đình',
        'Hữu', 'Công', 'Phúc', 'Phương', 'Tấn',
    ];

    private array $vietnameseFirstNames = [
        'An', 'Bình', 'Cường', 'Dũng', 'Dương', 'Đạt', 'Giang', 'Hà', 'Hải', 'Hiếu',
        'Hoa', 'Hoàng', 'Hùng', 'Huy', 'Hương', 'Khánh', 'Khoa', 'Kiên', 'Lâm', 'Linh',
        'Long', 'Mai', 'Minh', 'Nam', 'Nga', 'Ngân', 'Nghĩa', 'Ngọc', 'Nhi', 'Phong',
        'Phúc', 'Phương', 'Quang', 'Quân', 'Sơn', 'Tâm', 'Thái', 'Thành', 'Thảo', 'Thắng',
        'Thịnh', 'Thu', 'Thủy', 'Trang', 'Trung', 'Tú', 'Tuấn', 'Tùng', 'Vinh', 'Vũ',
        'Yến', 'Lan', 'Bích', 'Trúc', 'Diệp', 'Vy', 'Tiến', 'Bách', 'Triết', 'Đăng',
    ];

    public function run(): void
    {
        $targetTotal = 500;

        echo "\n=========================================================================\n";
        echo "   BẮT ĐẦU NẠP 500 USERS BIẾN ĐỘNG & TIẾN ĐỘ HỌC TẬP (2025 - 2026)\n";
        echo "=========================================================================\n\n";

        $startTime = microtime(true);
        $hashedPassword = Hash::make('password');

        $monthlyDistribution = [
            ['year' => 2025, 'month' => 1,  'count' => 12],
            ['year' => 2025, 'month' => 2,  'count' => 15],
            ['year' => 2025, 'month' => 3,  'count' => 18],
            ['year' => 2025, 'month' => 4,  'count' => 16],
            ['year' => 2025, 'month' => 5,  'count' => 20],
            ['year' => 2025, 'month' => 6,  'count' => 22],
            ['year' => 2025, 'month' => 7,  'count' => 24],
            ['year' => 2025, 'month' => 8,  'count' => 21],
            ['year' => 2025, 'month' => 9,  'count' => 23],
            ['year' => 2025, 'month' => 10, 'count' => 25],
            ['year' => 2025, 'month' => 11, 'count' => 26],
            ['year' => 2025, 'month' => 12, 'count' => 28],
            ['year' => 2026, 'month' => 1,  'count' => 25],
            ['year' => 2026, 'month' => 2,  'count' => 28],
            ['year' => 2026, 'month' => 3,  'count' => 32],
            ['year' => 2026, 'month' => 4,  'count' => 30],
            ['year' => 2026, 'month' => 5,  'count' => 35],
            ['year' => 2026, 'month' => 6,  'count' => 36],
            ['year' => 2026, 'month' => 7,  'count' => 38],
            ['year' => 2026, 'month' => 8,  'count' => 26],
        ];

        $courses = Course::pluck('id')->all();
        if (empty($courses)) {
            $courses = [1, 2, 3, 4, 5];
        }
        $totalCourses = count($courses);

        $lastNamesCount = count($this->vietnameseLastNames);
        $middleNamesCount = count($this->vietnameseMiddleNames);
        $firstNamesCount = count($this->vietnameseFirstNames);

        $now = now();
        $userCounter = 0;
        $totalCertificates = 0;
        $totalCompleted = 0;
        $totalInProgress = 0;
        $totalIncomplete = 0;
        $totalNew = 0;

        // Dọn dẹp các user thử nghiệm trước đó theo đúng pattern
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $targetUserIds = DB::table('users')
            ->where('username', 'like', 'user_%')
            ->where('email', 'like', '%@onlinefea.edu.vn')
            ->pluck('id')
            ->all();

        if (!empty($targetUserIds)) {
            foreach (array_chunk($targetUserIds, 2000) as $chunkIds) {
                DB::table('certificates')->whereIn('user_id', $chunkIds)->delete();
                DB::table('enrollments')->whereIn('user_id', $chunkIds)->delete();
                DB::table('users')->whereIn('id', $chunkIds)->delete();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $maxUserId = DB::table('users')->max('id') ?? 0;
        $currentUserId = $maxUserId + 1;

        $userBatch = [];
        $enrollmentBatch = [];
        $certificateBatch = [];

        foreach ($monthlyDistribution as $mIdx => $slot) {
            $year = $slot['year'];
            $month = $slot['month'];
            $monthCount = $slot['count'];
            $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;

            for ($u = 1; $u <= $monthCount; $u++) {
                $userCounter++;
                $userId = $currentUserId++;

                $day = (($u * 3 + ($userCounter % 7)) % min(28, $daysInMonth)) + 1;
                $hour = 7 + (($u * 3) % 15);
                $minute = ($u * 11) % 60;
                $second = ($u * 17) % 60;

                $createdAt = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
                $createdCarbon = Carbon::create($year, $month, $day, $hour, $minute, $second);

                $isRecent = ($year === 2026 && $month >= 6);
                if ($isRecent) {
                    $loginDaysAgo = ($userCounter % 30);
                    $loginHoursAgo = ($userCounter % 24);
                    $lastLoginAt = $now->copy()->subDays($loginDaysAgo)->subHours($loginHoursAgo)->format('Y-m-d H:i:s');
                } else {
                    $lastLoginAt = $createdAt;
                }

                $last = $this->vietnameseLastNames[($userCounter - 1) % $lastNamesCount];
                $mid = $this->vietnameseMiddleNames[(($userCounter * 3) + 1) % $middleNamesCount];
                $first = $this->vietnameseFirstNames[(($userCounter * 7) + 2) % $firstNamesCount];
                $name = "{$last} {$mid} {$first}";

                $username = sprintf('user_%06d', $userCounter);
                $email = sprintf('user_%06d@onlinefea.edu.vn', $userCounter);
                $phone = sprintf('089%07d', $userCounter);

                $role = 'student';
                $avatar = 'https://images.unsplash.com/photo-' . (1530000000000 + ($userCounter * 914159) % 50000000) . '?w=150&auto=format&fit=crop&q=80';

                $userBatch[] = [
                    'id' => $userId,
                    'name' => $name,
                    'username' => $username,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => $role,
                    'avatar' => $avatar,
                    'bio' => 'Thành viên cộng đồng học tập trực tuyến OnlineFEA.',
                    'password' => $hashedPassword,
                    'is_active' => true,
                    'account_status' => 'active',
                    'email_verified_at' => $createdAt,
                    'last_login_at' => $lastLoginAt,
                    'two_factor_enabled' => false,
                    'created_at' => $createdAt,
                    'updated_at' => $lastLoginAt,
                ];

                // Mỗi student đăng ký từ 1 đến 3 khóa học
                $coursesToEnrollCount = 1 + ($userCounter % 3);
                for ($c = 0; $c < $coursesToEnrollCount; $c++) {
                    $courseId = $courses[($userCounter * 3 + $c * 7) % $totalCourses];

                    if ($c === 0) {
                        $enrolledAtStr = $createdAt;
                        $enrolledCarbon = $createdCarbon->copy();
                    } elseif ($year === 2025) {
                        $eMonth = (($userCounter + $c * 3) % 8) + 1;
                        $eDay = (($userCounter * 5) % 28) + 1;
                        $enrolledAtStr = sprintf('2026-%02d-%02d 10:15:30', $eMonth, $eDay);
                        $enrolledCarbon = Carbon::create(2026, $eMonth, $eDay, 10, 15, 30);
                    } else {
                        $eDay = min(28, $day + $c * 3);
                        $enrolledAtStr = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $eDay, $hour, $minute, $second);
                        $enrolledCarbon = Carbon::create($year, $month, $eDay, $hour, $minute, $second);
                    }

                    $statusType = ($userCounter + $c) % 4;
                    if ($statusType === 0) {
                        $completedAt = $enrolledCarbon->copy()->addDays(rand(10, 30))->format('Y-m-d H:i:s');
                        $enrollmentBatch[] = [
                            'user_id' => $userId,
                            'course_id' => $courseId,
                            'order_id' => null,
                            'status' => 'completed',
                            'progress_percent' => 100.00,
                            'completed_lessons' => 12,
                            'total_lessons' => 12,
                            'enrolled_at' => $enrolledAtStr,
                            'completed_at' => $completedAt,
                            'last_accessed_at' => $completedAt,
                            'created_at' => $enrolledAtStr,
                            'updated_at' => $completedAt,
                        ];

                        $certificateBatch[] = [
                            'user_id' => $userId,
                            'course_id' => $courseId,
                            'certificate_code' => sprintf('FEA-%s-%06d-%s', $enrolledCarbon->format('Y'), $userCounter, strtoupper(Str::random(4))),
                            'file_path' => 'certificates/cert_' . $userId . '_' . $courseId . '.pdf',
                            'issued_at' => $completedAt,
                            'created_at' => $completedAt,
                            'updated_at' => $completedAt,
                        ];

                        $totalCompleted++;
                        $totalCertificates++;
                    } elseif ($statusType === 1) {
                        $progress = (float) rand(25, 85);
                        $completedLessons = (int) round(($progress / 100) * 12);
                        $lastAccess = $enrolledCarbon->copy()->addDays(rand(3, 15))->format('Y-m-d H:i:s');

                        $enrollmentBatch[] = [
                            'user_id' => $userId,
                            'course_id' => $courseId,
                            'order_id' => null,
                            'status' => 'active',
                            'progress_percent' => $progress,
                            'completed_lessons' => $completedLessons,
                            'total_lessons' => 12,
                            'enrolled_at' => $enrolledAtStr,
                            'completed_at' => null,
                            'last_accessed_at' => $lastAccess,
                            'created_at' => $enrolledAtStr,
                            'updated_at' => $lastAccess,
                        ];
                        $totalInProgress++;
                    } elseif ($statusType === 2) {
                        $progress = (float) rand(5, 18);
                        $lastAccess = $enrolledCarbon->copy()->addDays(rand(1, 3))->format('Y-m-d H:i:s');

                        $enrollmentBatch[] = [
                            'user_id' => $userId,
                            'course_id' => $courseId,
                            'order_id' => null,
                            'status' => 'active',
                            'progress_percent' => $progress,
                            'completed_lessons' => 1,
                            'total_lessons' => 12,
                            'enrolled_at' => $enrolledAtStr,
                            'completed_at' => null,
                            'last_accessed_at' => $lastAccess,
                            'created_at' => $enrolledAtStr,
                            'updated_at' => $lastAccess,
                        ];
                        $totalIncomplete++;
                    } else {
                        $progress = 0.00;
                        $enrollmentBatch[] = [
                            'user_id' => $userId,
                            'course_id' => $courseId,
                            'order_id' => null,
                            'status' => 'active',
                            'progress_percent' => $progress,
                            'completed_lessons' => 0,
                            'total_lessons' => 12,
                            'enrolled_at' => $enrolledAtStr,
                            'completed_at' => null,
                            'last_accessed_at' => $enrolledAtStr,
                            'created_at' => $enrolledAtStr,
                            'updated_at' => $enrolledAtStr,
                        ];
                        $totalNew++;
                    }
                }
            }
        }

        echo "\n--> Đang chèn " . count($userBatch) . " Users vào Database...\n";
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        foreach (array_chunk($userBatch, 1000) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        echo "--> Đang chèn " . count($enrollmentBatch) . " Tiến độ học tập (Enrollments)...\n";
        foreach (array_chunk($enrollmentBatch, 1000) as $chunk) {
            DB::table('enrollments')->insert($chunk);
        }

        echo "--> Đang chèn " . count($certificateBatch) . " Chứng chỉ hoàn thành...\n";
        foreach (array_chunk($certificateBatch, 1000) as $chunk) {
            DB::table('certificates')->insert($chunk);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $totalElapsed = round(microtime(true) - $startTime, 2);

        echo "\n=========================================================================\n";
        echo "✓ HOÀN TẤT NẠP THÀNH CÔNG 500 USERS VÀ TIẾN ĐỘ HỌC TẬP!\n";
        echo "   • Tổng số Enrollments: " . number_format(count($enrollmentBatch)) . "\n";
        echo "   • Hoàn thành khóa học (Completed): " . number_format($totalCompleted) . "\n";
        echo "   • Đang học tích cực (In Progress): " . number_format($totalInProgress) . "\n";
        echo "   • Dở dang / Chưa xong (Incomplete): " . number_format($totalIncomplete) . "\n";
        echo "   • Người dùng mới (New Users): " . number_format($totalNew) . "\n";
        echo "   • Chứng chỉ cấp (Certificates): " . number_format($totalCertificates) . "\n";
        echo "   • Thời gian thực thi: {$totalElapsed} giây\n";
        echo "=========================================================================\n\n";
    }
}
