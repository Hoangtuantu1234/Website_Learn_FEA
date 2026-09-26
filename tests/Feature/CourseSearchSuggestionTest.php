<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseSearchSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->create([
            'name' => 'Nguyễn Văn A',
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'is_active' => true,
            'account_status' => 'active',
            'locked_at' => null,
        ]);

        $parentCategory = Category::create([
            'name' => 'Công nghệ thông tin',
            'slug' => 'cong-nghe-thong-tin',
            'status' => true,
        ]);

        $this->category = Category::create([
            'parent_id' => $parentCategory->id,
            'name' => 'Lập trình Web',
            'slug' => 'lap-trinh-web',
            'status' => true,
        ]);
    }

    /**
     * Case 1 — Tìm khóa học
     * Nhập tên khóa học → Hiển thị khóa học phù hợp → Click → Mở đúng khóa học
     */
    public function test_case_1_search_course_and_navigate_to_detail(): void
    {
        $course = Course::create([
            'instructor_id' => $this->instructor->id,
            'category_id' => $this->category->id,
            'title' => 'Lập trình Laravel cơ bản',
            'slug' => 'lap-trinh-laravel-co-ban',
            'description' => 'Khóa học học Laravel từ cơ bản đến nâng cao',
            'price' => 200000,
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $response = $this->getJson(route('courses.suggestions', ['q' => 'Laravel']));
        $response->assertOk()
            ->assertJsonFragment(['type' => 'course'])
            ->assertJsonFragment(['label' => 'Lập trình Laravel cơ bản'])
            ->assertJsonFragment(['url' => route('courses.show', $course->slug)]);

        // Click / truy cập đường dẫn khóa học trả về
        $detailResponse = $this->get(route('courses.show', $course->slug));
        $detailResponse->assertOk()
            ->assertSee('Lập trình Laravel cơ bản');
    }

    /**
     * Case 2 — Tìm danh mục
     * Nhập tên danh mục → Hiển thị danh mục → Click → Hiển thị đúng khóa học thuộc danh mục
     */
    public function test_case_2_search_category_and_navigate_to_category_courses(): void
    {
        $course = Course::create([
            'instructor_id' => $this->instructor->id,
            'category_id' => $this->category->id,
            'title' => 'Khóa học Web Frontend',
            'slug' => 'khoa-hoc-web-frontend',
            'description' => 'Mô tả khóa học web',
            'price' => 150000,
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $response = $this->getJson(route('courses.suggestions', ['q' => 'Lập trình Web']));
        $response->assertOk()
            ->assertJsonFragment(['type' => 'category'])
            ->assertJsonFragment(['label' => 'Lập trình Web'])
            ->assertJsonFragment(['url' => route('courses.category', $this->category->slug)]);

        // Click / truy cập đường dẫn danh mục trả về
        $categoryPageResponse = $this->get(route('courses.category', $this->category->slug));
        $categoryPageResponse->assertOk()
            ->assertSee('Khóa học Web Frontend');
    }

    /**
     * Case 3 — Tìm giảng viên
     * Nhập tên giảng viên → Hiển thị giảng viên → Click → Hiển thị đúng trang giảng viên
     */
    public function test_case_3_search_instructor_and_navigate_to_profile(): void
    {
        // Giảng viên hợp lệ có khóa học đã duyệt
        $course = Course::create([
            'instructor_id' => $this->instructor->id,
            'category_id' => $this->category->id,
            'title' => 'Khóa học của thầy A',
            'slug' => 'khoa-hoc-thay-a',
            'price' => 100000,
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);

        // Giảng viên chưa được duyệt (không được hiển thị trong kết quả)
        $unapprovedInstructor = User::factory()->create([
            'name' => 'Nguyễn Văn B Chưa Duyệt',
            'role' => 'instructor',
            'instructor_status' => 'pending',
            'is_active' => true,
        ]);

        // Giảng viên bị khóa (không được hiển thị trong kết quả)
        $lockedInstructor = User::factory()->create([
            'name' => 'Nguyễn Văn C Bị Khóa',
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'is_active' => false,
            'account_status' => 'locked',
            'locked_at' => now(),
        ]);

        $response = $this->getJson(route('courses.suggestions', ['q' => 'Nguyễn Văn']));
        $response->assertOk()
            ->assertJsonFragment(['type' => 'instructor', 'label' => 'Nguyễn Văn A'])
            ->assertJsonMissing(['label' => 'Nguyễn Văn B Chưa Duyệt'])
            ->assertJsonMissing(['label' => 'Nguyễn Văn C Bị Khóa']);

        // Click / truy cập trang giảng viên
        $instructorPageResponse = $this->get(route('instructors.show', $this->instructor));
        $instructorPageResponse->assertOk()
            ->assertSee('Nguyễn Văn A');
    }

    /**
     * Case 4 — Một từ khóa có nhiều loại kết quả
     * Nhập từ khóa → Khóa học, Danh mục, Giảng viên → Hiển thị đúng từng nhóm
     */
    public function test_case_4_keyword_returns_multiple_types_properly_grouped(): void
    {
        $phpCategory = Category::create([
            'name' => 'PHP & Frameworks',
            'slug' => 'php-frameworks',
            'status' => true,
        ]);

        $phpInstructor = User::factory()->create([
            'name' => 'Thầy PHP Master',
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'is_active' => true,
        ]);

        $phpCourse = Course::create([
            'instructor_id' => $phpInstructor->id,
            'category_id' => $phpCategory->id,
            'title' => 'Chuyên đề PHP Hiện Đại',
            'slug' => 'chuyen-de-php-hien-dai',
            'price' => 300000,
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $response = $this->getJson(route('courses.suggestions', ['q' => 'PHP']));
        $response->assertOk()
            ->assertJsonStructure([
                'suggestions',
                'courses',
                'categories',
                'instructors',
                'has_results',
            ]);

        $this->assertTrue($response->json('has_results'));
        $this->assertNotEmpty($response->json('courses'));
        $this->assertNotEmpty($response->json('categories'));
        $this->assertNotEmpty($response->json('instructors'));

        $this->assertSame('Chuyên đề PHP Hiện Đại', $response->json('courses.0.label'));
        $this->assertSame('PHP & Frameworks', $response->json('categories.0.label'));
        $this->assertSame('Thầy PHP Master', $response->json('instructors.0.label'));
    }

    /**
     * Case 5 — Không có kết quả
     * Nhập từ khóa không tồn tại → Không lỗi 500 → Trả về rỗng và has_results = false
     */
    public function test_case_5_non_existent_keyword_returns_empty_without_error(): void
    {
        $response = $this->getJson(route('courses.suggestions', ['q' => 'từ_khóa_hoàn_toàn_không_tồn_tại_12345']));
        $response->assertOk()
            ->assertJson([
                'suggestions' => [],
                'courses' => [],
                'categories' => [],
                'instructors' => [],
                'has_results' => false,
            ]);
    }

    /**
     * Case 6 — Từ khóa rỗng
     * Search rỗng hoặc quá ngắn (< 2 ký tự) → Không gây lỗi, không query toàn bộ database
     */
    public function test_case_6_empty_or_short_query_returns_empty_immediately(): void
    {
        $responseEmpty = $this->getJson(route('courses.suggestions', ['q' => '']));
        $responseEmpty->assertOk()
            ->assertJson(['suggestions' => []]);

        $responseOneChar = $this->getJson(route('courses.suggestions', ['q' => 'a']));
        $responseOneChar->assertOk()
            ->assertJson(['suggestions' => []]);
    }

    /**
     * Case 7 — Ký tự đặc biệt
     * Test: ', ", %, _, <>, <script> → Không được gây SQL error hoặc XSS
     */
    public function test_case_7_special_characters_sql_injection_and_xss_protection(): void
    {
        $specialKeywords = [
            "'",
            '"',
            '%',
            '_',
            '<>',
            '<script>alert(1)</script>',
            "'; DROP TABLE courses; --",
        ];

        foreach ($specialKeywords as $keyword) {
            $response = $this->getJson(route('courses.suggestions', ['q' => $keyword]));
            $response->assertOk();
            $this->assertIsArray($response->json('suggestions'));
        }
    }

    /**
     * Case 8 — Pagination, filter, sort, search hiện tại vẫn hoạt động bình thường
     */
    public function test_case_8_existing_search_filter_sort_and_pagination_remain_intact(): void
    {
        // Tạo 15 khóa học để kiểm tra phân trang (mỗi trang 12 khóa)
        foreach (range(1, 15) as $i) {
            Course::create([
                'instructor_id' => $this->instructor->id,
                'category_id' => $this->category->id,
                'title' => "Khóa học VueJS Phần {$i}",
                'slug' => "khoa-hoc-vuejs-phan-{$i}",
                'price' => $i % 2 === 0 ? 0 : 200000,
                'level' => $i <= 5 ? 'beginner' : 'intermediate',
                'status' => Course::STATUS_PUBLISHED,
                'is_published' => true,
                'published_at' => now()->subMinutes($i),
            ]);
        }

        // 1. Search cơ bản trên trang catalog
        $searchResponse = $this->get(route('courses.index', ['search' => 'VueJS']));
        $searchResponse->assertOk()
            ->assertViewHas('search', 'VueJS')
            ->assertViewHas('courses', fn ($courses) => $courses->total() === 15);

        // 2. Filter theo category
        $catFilterResponse = $this->get(route('courses.index', ['category' => $this->category->slug]));
        $catFilterResponse->assertOk()
            ->assertViewHas('selectedCategory', fn ($cat) => $cat && $cat->id === $this->category->id);

        // 3. Filter theo pricing (miễn phí)
        $freeResponse = $this->get(route('courses.index', ['search' => 'VueJS', 'pricing' => 'free']));
        $freeResponse->assertOk()
            ->assertViewHas('courses', fn ($courses) => $courses->total() === 7);

        // 4. Filter theo level
        $levelResponse = $this->get(route('courses.index', ['search' => 'VueJS', 'level' => 'beginner']));
        $levelResponse->assertOk()
            ->assertViewHas('courses', fn ($courses) => $courses->total() === 5);

        // 5. Pagination trang 2
        $page2Response = $this->get(route('courses.index', ['search' => 'VueJS', 'page' => 2]));
        $page2Response->assertOk()
            ->assertViewHas('courses', fn ($courses) => $courses->currentPage() === 2 && $courses->count() === 3);
    }
}
