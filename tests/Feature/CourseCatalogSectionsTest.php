<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseCatalogSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_catalog_renders_three_sections_with_at_most_four_courses_each(): void
    {
        $this->seedCatalogCourses();

        $response = $this->get(route('courses.index'));

        $response->assertOk()
            ->assertViewHas('showCourseOverview', true)
            ->assertViewHas('allCoursesPreview', fn ($courses) => $courses->count() === 4)
            ->assertViewHas('paidCoursesPreview', fn ($courses) => $courses->count() === 4
                && $courses->every(fn ($course) => (float) ($course->discount_price ?? $course->sale_price ?? $course->price) > 0))
            ->assertViewHas('freeCoursesPreview', fn ($courses) => $courses->count() === 4
                && $courses->every(fn ($course) => (float) ($course->discount_price ?? $course->sale_price ?? $course->price) <= 0))
            ->assertSee('data-course-section="all"', false)
            ->assertSee('data-course-section="paid"', false)
            ->assertSee('data-course-section="free"', false)
            ->assertSee('href="'.route('courses.index', ['view' => 'all']).'"', false)
            ->assertSee('href="'.route('courses.index', ['pricing' => 'paid']).'"', false)
            ->assertSee('href="'.route('courses.index', ['pricing' => 'free']).'"', false);

        foreach (['all', 'paid', 'free'] as $section) {
            preg_match('/<section data-course-section="'.$section.'".*?<\/section>/s', $response->getContent(), $matches);
            $this->assertNotEmpty($matches, "Missing [{$section}] course section.");
            $this->assertSame(4, substr_count($matches[0], 'data-course-card'));
        }
    }

    public function test_view_more_routes_open_the_full_filtered_catalog(): void
    {
        $this->seedCatalogCourses();

        $this->get(route('courses.index', ['view' => 'all']))
            ->assertOk()
            ->assertViewHas('showCourseOverview', false)
            ->assertViewHas('courses', fn ($courses) => $courses->total() === 12);

        $this->get(route('courses.index', ['pricing' => 'paid']))
            ->assertOk()
            ->assertViewHas('showCourseOverview', false)
            ->assertViewHas('courses', fn ($courses) => $courses->total() === 6
                && $courses->every(fn ($course) => (float) ($course->discount_price ?? $course->sale_price ?? $course->price) > 0));

        $this->get(route('courses.index', ['pricing' => 'free']))
            ->assertOk()
            ->assertViewHas('showCourseOverview', false)
            ->assertViewHas('courses', fn ($courses) => $courses->total() === 6
                && $courses->every(fn ($course) => (float) ($course->discount_price ?? $course->sale_price ?? $course->price) <= 0));
    }

    public function test_search_suggestions_return_real_courses_instructors_and_categories(): void
    {
        $this->seedCatalogCourses();

        $this->getJson(route('courses.suggestions', ['q' => 'trả phí']))
            ->assertOk()
            ->assertJsonFragment(['type' => 'course'])
            ->assertJsonFragment(['label' => 'Khóa trả phí 1']);

        $this->getJson(route('courses.suggestions', ['q' => 'Công nghệ']))
            ->assertOk()
            ->assertJsonFragment(['type' => 'category', 'label' => 'Công nghệ']);

        $empty = $this->getJson(route('courses.suggestions', ['q' => 'xyzkhongtontai']))
            ->assertOk()
            ->json('suggestions');
        $this->assertSame([], $empty);

        $this->get(route('courses.index'))
            ->assertOk()
            ->assertSee('/courses/suggestions', false)
            ->assertSee('Không tìm thấy kết quả', false);
    }

    private function seedCatalogCourses(): void
    {
        $instructor = User::factory()->create([
            'role' => 'instructor',
            'instructor_status' => 'approved',
            'is_active' => true,
        ]);
        $category = Category::create([
            'name' => 'Công nghệ',
            'slug' => 'cong-nghe',
            'status' => true,
        ]);

        foreach (range(1, 6) as $index) {
            $this->createCourse($instructor, $category, "Khóa trả phí {$index}", 250000, $index * 2);
            $this->createCourse($instructor, $category, "Khóa miễn phí {$index}", 0, ($index * 2) - 1);
        }
    }

    private function createCourse(User $instructor, Category $category, string $title, int $price, int $minutesAgo): void
    {
        Course::create([
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => $title,
            'slug' => str($title)->slug().'-'.$minutesAgo,
            'description' => 'Nội dung khóa học phục vụ kiểm thử catalog.',
            'price' => $price,
            'status' => Course::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
