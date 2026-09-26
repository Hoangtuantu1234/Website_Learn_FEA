<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ContentUpdate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\ContentVersionService;
use App\Services\ContentUpdateService;
use App\Services\EnrollmentVersionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseReleaseConcurrencyTest extends TestCase
{
    // Committed fixtures are necessary: two real connections must see V1 before
    // one publishes V2 while the other's REPEATABLE READ snapshot is still V1.
    use DatabaseTruncation;

    /**
     * These lookup rows are installed by migrations and are shared by the
     * remaining test process. Truncating them makes later RBAC tests depend on
     * execution order because RefreshDatabase does not rerun migrations.
     *
     * @var array<int, string>
     */
    protected array $exceptTables = [
        'roles',
        'permissions',
        'permission_role',
    ];

    protected function tearDown(): void
    {
        // Keep committed fixtures isolated without rolling back unrelated,
        // pre-existing migrations in this application's test database.
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_enrollment_reads_committed_published_pointer_despite_an_older_transaction_snapshot(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $studentA = User::factory()->create();
        $studentB = User::factory()->create();
        $category = Category::create(['name' => 'Concurrency', 'slug' => 'concurrency']);
        $course = Course::create(['instructor_id' => $instructor->id, 'category_id' => $category->id,
            'title' => 'V1', 'slug' => 'concurrent-release', 'status' => 'published', 'is_published' => true]);
        $versions = app(ContentVersionService::class);
        $v1 = $versions->createInitialCourseVersion($course, $admin);
        $a = app(EnrollmentVersionService::class)->firstOrCreate($course, $studentA->id);
        $default = DB::getDefaultConnection();
        config(['database.connections.enrollment_reader' => config('database.connections.'.$default)]);
        $reader = DB::connection('enrollment_reader');
        try {
            $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $reader->beginTransaction();
            $this->assertSame($v1->id, (int) $reader->table('courses')->where('id', $course->id)->value('published_version_id'));

            $addition = ContentUpdate::create(['course_id' => $course->id, 'type' => 'lesson', 'action' => 'create',
                'status' => 'pending', 'created_by' => $admin->id,
                'payload' => ['title' => 'New in V2', 'type' => 'document', 'status' => 'published', 'sort_order' => 1]]);
            app(ContentUpdateService::class)->applyApprovedUpdate($addition, $admin);
            $v2 = $course->fresh()->publishedVersion;
            $this->assertSame($v1->id, (int) $reader->table('courses')->where('id', $course->id)->value('published_version_id'));

            DB::setDefaultConnection('enrollment_reader');
            $b = app(EnrollmentVersionService::class)->firstOrCreate($course, $studentB->id);
            $this->assertSame($v2->id, $b->course_version_id);
            // Publication from the old snapshot must also see the V2 manifest
            // and newly committed child identities through current reads.
            $candidate = $versions->cloneCourseVersion($course, $admin);
            $versions->updateDraft($candidate, ['title' => 'V3']);
            $v3 = $versions->publishCourseVersion($candidate, $admin);
            $reader->commit();
            DB::setDefaultConnection($default);
            $this->assertSame($v1->id, $a->fresh()->course_version_id);
            $this->assertSame($v2->id, Enrollment::findOrFail($b->id)->courseVersion->id);
            $this->assertNotNull(Enrollment::findOrFail($b->id)->courseVersion->manifest_built_at);
            $this->assertSame($v2->lessonMappings()->value('lesson_version_id'), $v3->lessonMappings()->value('lesson_version_id'));
            $this->assertSame('New in V2', $v3->lessonMappings->first()->lessonVersion->title);
        } finally {
            if ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            DB::setDefaultConnection($default);
            DB::purge('enrollment_reader');
        }
    }

    public function test_phase_one_migration_rolls_back_without_removing_legacy_rows(): void
    {
        $user = User::factory()->create();
        $course = Course::create(['instructor_id' => $user->id, 'title' => 'Legacy', 'slug' => 'rollback-legacy']);
        $enrollment = Enrollment::create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'active']);
        $migration = require database_path('migrations/2026_09_03_000001_add_course_release_manifests_and_enrollment_pins.php');
        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('enrollments', 'course_version_id'));
            $this->assertFalse(Schema::hasTable('course_version_lessons'));
            $this->assertFalse(Schema::hasTable('course_version_sections'));
            $this->assertSame('Legacy', $course->fresh()->title);
            $this->assertSame('active', $enrollment->fresh()->status);
        } finally {
            $migration->up();
        }
        $this->assertTrue(Schema::hasColumn('enrollments', 'course_version_id'));
        $this->assertNull($enrollment->fresh()->course_version_id);
    }
}
