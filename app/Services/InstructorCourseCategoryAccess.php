<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Course;
use App\Models\InstructorTeachingField;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves an instructor's course permissions based on their approved teaching fields,
 * with hierarchical support (parent -> child categories) and fallback for legacy profiles.
 */
class InstructorCourseCategoryAccess
{
    /** @return Collection<int, Category> */
    public function selectableCategories(User $instructor): Collection
    {
        $registeredCategories = $this->getRegisteredCategories($instructor);

        if ($registeredCategories->isEmpty()) {
            return new Collection;
        }

        $selectableCategoryIds = [];

        foreach ($registeredCategories as $cat) {
            if (! $cat->status) {
                continue;
            }

            if ($cat->parent_id) {
                // Danh mục con: kiểm tra danh mục cha còn hoạt động không
                if ($cat->parent && ! $cat->parent->status) {
                    continue;
                }
                $selectableCategoryIds[] = (int) $cat->id;
            } else {
                // Danh mục cha (ngành):
                // Nếu có các danh mục con đang hoạt động, lấy tất cả danh mục con để tạo khóa học
                $activeChildren = $cat->children()->active()->pluck('id')->all();
                if (! empty($activeChildren)) {
                    $selectableCategoryIds = array_merge($selectableCategoryIds, array_map('intval', $activeChildren));
                } else {
                    // Nếu không có danh mục con, bản thân danh mục cha được chọn
                    $selectableCategoryIds[] = (int) $cat->id;
                }
            }
        }

        $uniqueIds = array_values(array_unique($selectableCategoryIds));

        if (empty($uniqueIds)) {
            return new Collection;
        }

        return Category::query()
            ->whereIn('id', $uniqueIds)
            ->with('parent:id,name,status')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'status', 'sort_order']);
    }

    public function canTeachCategory(User $instructor, int $categoryId): bool
    {
        if (! $instructor->isApprovedInstructor() || $categoryId <= 0) {
            return false;
        }

        $category = Category::find($categoryId);
        if (! $category || ! $category->status) {
            return false;
        }

        $registeredCategories = $this->getRegisteredCategories($instructor);
        if ($registeredCategories->isEmpty()) {
            return false;
        }

        $registeredIds = $registeredCategories->pluck('id')->map(fn ($id) => (int) $id)->all();

        // 1. Trực tiếp thuộc danh mục đã đăng ký
        if (in_array($categoryId, $registeredIds, true)) {
            return true;
        }

        // 2. Nếu là danh mục con, kiểm tra xem danh mục cha có nằm trong các ngành đã đăng ký không
        if ($category->parent_id && in_array((int) $category->parent_id, $registeredIds, true)) {
            return true;
        }

        return false;
    }

    public function canManageCourse(User $instructor, Course $course): bool
    {
        if (! $instructor->isApprovedInstructor() || ! $course->isOwnedBy($instructor)) {
            return false;
        }

        if (empty($course->category_id)) {
            return true;
        }

        if (! $this->hasAnyConfiguredTeachingFields($instructor)) {
            return true;
        }

        if ($this->canTeachCategory($instructor, (int) $course->category_id)
            || $this->hasSupersededFieldForCategory($instructor, (int) $course->category_id)) {
            return true;
        }

        // An approved instructor keeps access to courses in their current
        // primary profile category even when pivot rows are still draft/pending.
        $profile = $instructor->relationLoaded('instructorProfile')
            ? $instructor->instructorProfile
            : $instructor->instructorProfile()->first();

        return $profile
            && ! empty($profile->category_id)
            && (int) $profile->category_id === (int) $course->category_id;
    }

    public function hasAnyConfiguredTeachingFields(User $instructor): bool
    {
        $profile = $instructor->relationLoaded('instructorProfile')
            ? $instructor->instructorProfile
            : $instructor->instructorProfile()->first();

        if (! $profile) {
            return false;
        }

        return $profile->teachingCategories()->exists()
            || ! empty($profile->category_id)
            || ! empty($profile->teaching_field)
            || ! empty($profile->specialty);
    }

    /**
     * A superseded field cannot create a new course, but it deliberately keeps
     * the instructor's access to courses they already own in that field.
     */
    private function hasSupersededFieldForCategory(User $instructor, int $categoryId): bool
    {
        $profile = $instructor->relationLoaded('instructorProfile')
            ? $instructor->instructorProfile
            : $instructor->instructorProfile()->first();

        if (! $profile || $categoryId <= 0) {
            return false;
        }

        $category = Category::find($categoryId);
        $fieldCategoryIds = [$categoryId];
        if ($category?->parent_id) {
            $fieldCategoryIds[] = (int) $category->parent_id;
        }

        return $profile->teachingFields()
            ->where('approval_status', InstructorTeachingField::STATUS_SUPERSEDED)
            ->whereIn('category_id', $fieldCategoryIds)
            ->exists();
    }

    /**
     * Lấy danh sách các danh mục/ngành mà giảng viên đã đăng ký (kèm fallback).
     *
     * @return Collection<int, Category>
     */
    public function getRegisteredCategories(User $instructor): Collection
    {
        if (! $instructor->isApprovedInstructor()) {
            return new Collection;
        }

        $profile = $instructor->relationLoaded('instructorProfile')
            ? $instructor->instructorProfile
            : $instructor->instructorProfile()->first();

        if (! $profile) {
            return new Collection;
        }

        $teachingCategories = $profile->approvedTeachingCategories()
            ->with('parent:id,name,status')
            ->get();

        if ($teachingCategories->isNotEmpty()) {
            return $teachingCategories;
        }

        // Fallback: nếu pivot table chưa có bản ghi, lấy từ category_id / teaching_field
        if ($profile->teachingCategories()->exists()) {
            return new Collection;
        }

        $fallbackCategories = $instructor->getTeachingCategories();
        if ($fallbackCategories->isNotEmpty()) {
            // Tự động đồng bộ vào pivot để các lần query tiếp theo chuẩn xác
            return $fallbackCategories->loadMissing('parent:id,name,status');
        }

        return new Collection;
    }
}
