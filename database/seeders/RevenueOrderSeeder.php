<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RevenueOrderSeeder extends Seeder
{
    public function run(): void
    {
        $targetTotalRevenue = 320000000.00; // 320.000.000 VNĐ

        echo "\n=========================================================================\n";
        echo "   BẮT ĐẦU NẠP DOANH THU BIẾN ĐỘNG (T1/2025 - T8/2026)\n";
        echo "   Mục tiêu Tổng doanh thu: " . number_format($targetTotalRevenue) . " VNĐ\n";
        echo "=========================================================================\n\n";

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('payments')->truncate();
        DB::table('order_items')->truncate();
        DB::table('orders')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $courses = Course::where('status', 'published')->get(['id', 'price']);
        if ($courses->isEmpty()) {
            $courses = Course::all(['id', 'price']);
        }
        $courseList = $courses->all();
        $totalCourses = count($courseList);

        $studentIds = User::where('role', 'student')->pluck('id')->all();
        if (empty($studentIds)) {
            $studentIds = User::pluck('id')->all();
        }
        $totalStudents = count($studentIds);

        // 20 tháng từ Tháng 01/2025 đến Tháng 08/2026 (Tổng = 320.000.000 VNĐ)
        $monthlyTargetRevenue = [
            ['year' => 2025, 'month' => 1,  'target' => 10000000],
            ['year' => 2025, 'month' => 2,  'target' => 11000000],
            ['year' => 2025, 'month' => 3,  'target' => 12000000],
            ['year' => 2025, 'month' => 4,  'target' => 11500000],
            ['year' => 2025, 'month' => 5,  'target' => 13000000],
            ['year' => 2025, 'month' => 6,  'target' => 14000000],
            ['year' => 2025, 'month' => 7,  'target' => 15000000],
            ['year' => 2025, 'month' => 8,  'target' => 14500000],
            ['year' => 2025, 'month' => 9,  'target' => 15500000],
            ['year' => 2025, 'month' => 10, 'target' => 16000000],
            ['year' => 2025, 'month' => 11, 'target' => 16500000],
            ['year' => 2025, 'month' => 12, 'target' => 18000000],
            ['year' => 2026, 'month' => 1,  'target' => 16500000],
            ['year' => 2026, 'month' => 2,  'target' => 17500000],
            ['year' => 2026, 'month' => 3,  'target' => 20000000],
            ['year' => 2026, 'month' => 4,  'target' => 19000000],
            ['year' => 2026, 'month' => 5,  'target' => 21000000],
            ['year' => 2026, 'month' => 6,  'target' => 22000000],
            ['year' => 2026, 'month' => 7,  'target' => 22500000],
            ['year' => 2026, 'month' => 8,  'target' => 17000000],
        ];

        // Tính lại tháng 8/2026 để tổng doanh thu đạt đúng target (320 triệu VNĐ)
        $sumPrevious19Months = 0;
        for ($i = 0; $i < 19; $i++) {
            $sumPrevious19Months += $monthlyTargetRevenue[$i]['target'];
        }
        $monthlyTargetRevenue[19]['target'] = $targetTotalRevenue - $sumPrevious19Months;

        $currentTotalRevenue = 0.0;
        $orderCounter = 1;
        $gateways = ['momo', 'bank_transfer'];
        $standardPrices = [399000, 499000, 599000, 699000, 799000, 890000, 990000];

        $ordersBatch = [];
        $itemsBatch = [];
        $paymentsBatch = [];

        foreach ($monthlyTargetRevenue as $mIdx => $slot) {
            $year = $slot['year'];
            $month = $slot['month'];
            $monthTarget = $slot['target'];
            $isLastMonth = ($mIdx === 19);

            $monthGeneratedRevenue = 0.0;
            // Số đơn mỗi tháng từ 18 đến 23 đơn (Tổng 20 tháng ~ 400 đơn)
            $monthOrderCount = rand(18, 23);
            $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;

            for ($o = 1; $o <= $monthOrderCount; $o++) {
                $isLastOrderOfMonth = ($o === $monthOrderCount);
                $isAbsoluteLastOrder = ($isLastMonth && $isLastOrderOfMonth);

                if ($isAbsoluteLastOrder) {
                    $itemPrice = $targetTotalRevenue - $currentTotalRevenue;
                } elseif ($isLastOrderOfMonth) {
                    $itemPrice = $monthTarget - $monthGeneratedRevenue;
                } else {
                    $itemPrice = $standardPrices[rand(0, count($standardPrices) - 1)];
                    if ($monthGeneratedRevenue + $itemPrice > $monthTarget - 200000) {
                        $itemPrice = max(199000, (int) round(($monthTarget - $monthGeneratedRevenue) / ($monthOrderCount - $o + 1)));
                    }
                }

                $itemPrice = (float) $itemPrice;
                $currentTotalRevenue += $itemPrice;
                $monthGeneratedRevenue += $itemPrice;

                $day = rand(1, min(28, $daysInMonth));
                $hour = rand(8, 22);
                $minute = rand(0, 59);
                $second = rand(0, 59);

                $createdAt = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
                $orderCode = sprintf('ORD-%d%02d-%06d', $year, $month, $orderCounter);
                $userId = $studentIds[($orderCounter - 1) % $totalStudents];
                $course = $courseList[($orderCounter * 3) % $totalCourses];
                $gateway = $gateways[$orderCounter % count($gateways)];
                $orderId = $orderCounter;

                $ordersBatch[] = [
                    'id' => $orderId,
                    'order_code' => $orderCode,
                    'user_id' => $userId,
                    'coupon_id' => null,
                    'subtotal' => $itemPrice,
                    'discount_amount' => 0.00,
                    'total_amount' => $itemPrice,
                    'status' => 'paid',
                    'payment_method' => $gateway,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $itemsBatch[] = [
                    'order_id' => $orderId,
                    'course_id' => $course->id,
                    'price' => $itemPrice,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $paymentsBatch[] = [
                    'order_id' => $orderId,
                    'gateway' => $gateway,
                    'transaction_id' => 'TXN' . strtoupper(Str::random(10)),
                    'amount' => $itemPrice,
                    'status' => 'success',
                    'gateway_response' => json_encode(['response_code' => '00', 'message' => 'Giao dịch thành công']),
                    'paid_at' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $orderCounter++;

                if ($isAbsoluteLastOrder) {
                    break;
                }
            }

            echo sprintf("   • Tháng %02d/%d: Doanh thu = %15s VNĐ (%2d đơn hàng)\n",
                $month, $year, number_format($monthGeneratedRevenue), $monthOrderCount);
        }

        echo "\n--> Đang lưu " . count($ordersBatch) . " đơn hàng vào cơ sở dữ liệu...\n";
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        foreach (array_chunk($ordersBatch, 1000) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        foreach (array_chunk($itemsBatch, 1000) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }
        foreach (array_chunk($paymentsBatch, 1000) as $chunk) {
            DB::table('payments')->insert($chunk);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $finalRevenue = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.status', 'paid')
            ->sum('order_items.price');

        echo "\n=========================================================================\n";
        echo "✓ HOÀN TẤT NẠP DOANH THU THÀNH CÔNG!\n";
        echo "   • Tổng số đơn hàng: " . number_format($orderCounter - 1) . " đơn hàng\n";
        echo "   • TỔNG DOANH THU ĐẠT ĐƯỢC: " . number_format($finalRevenue) . " VNĐ\n";
        echo "   • Mục tiêu yêu cầu:       " . number_format($targetTotalRevenue) . " VNĐ\n";
        echo "=========================================================================\n\n";
    }
}
