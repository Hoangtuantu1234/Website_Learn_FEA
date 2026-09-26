<?php

namespace App\Http\Requests\Instructor;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\InstructorCourseCategoryAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Course|null $course */
        $course = $this->route('course')
            ?? $this->route('lesson')?->course
            ?? $this->route('chapter')?->course;

        if (! $course || ! $this->user()) {
            return false;
        }

        return app(InstructorCourseCategoryAccess::class)->canManageCourse($this->user(), $course);
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('lesson')) {
            $lessonRoute = $this->route('lesson');
            $lessonId = is_object($lessonRoute) ? $lessonRoute->id : $lessonRoute;
            $this->errorBag = 'updateLesson_'.$lessonId;
        } elseif ($this->route('section')) {
            $sectionRoute = $this->route('section');
            $sectionId = is_object($sectionRoute) ? $sectionRoute->id : $sectionRoute;
            $this->errorBag = 'storeLesson_'.$sectionId;
        }

        $this->merge([
            'title' => trim((string) $this->input('title')),
            'type' => $this->input('type') ?: null,
            'status' => $this->input('status') ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxVideoBytes = max(1, (int) config('video.upload.max_bytes'));
        $maxVideoKilobytes = (int) ceil($maxVideoBytes / 1024);

        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Lesson::TYPES)],
            's3_key' => ['nullable', 'string', 'max:1024', 'prohibited_unless:type,video'],
            'pending_video_upload' => ['nullable', 'boolean', 'prohibited_unless:type,video'],
            'video_original_name' => ['nullable', 'string', 'max:255'],
            'video_mime' => ['nullable', 'string', 'max:100'],
            'video_size' => ['nullable', 'integer', 'min:0', 'max:'.$maxVideoBytes],
            'video_file' => ['nullable', 'file', 'mimes:mp4,m4v,mov,avi,webm,mkv', 'max:'.$maxVideoKilobytes, 'prohibited_unless:type,video'],
            'video_url' => ['nullable', 'url:http,https', 'max:2048', 'prohibited_unless:type,video'],
            'content' => ['nullable', 'string', 'prohibited_if:type,quiz'],
            'document_file' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,zip,rar',
                'max:10240',
                Rule::prohibitedIf(fn () => ! in_array($this->input('type'), [Lesson::TYPE_DOCUMENT, Lesson::TYPE_ASSIGNMENT], true)),
            ],
            'assignment_due_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:3650',
                Rule::prohibitedIf(fn () => $this->input('type') !== Lesson::TYPE_ASSIGNMENT),
            ],
            'assignment_max_score' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000',
                Rule::prohibitedIf(fn () => $this->input('type') !== Lesson::TYPE_ASSIGNMENT),
            ],
            'assignment_passing_score' => [
                'nullable',
                'integer',
                'min:0',
                'max:1000',
                Rule::prohibitedIf(fn () => $this->input('type') !== Lesson::TYPE_ASSIGNMENT),
            ],
            'duration' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'is_preview' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'status' => ['nullable', Rule::in(Lesson::STATUSES)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Lesson|null $lesson */
                $lesson = $this->route('lesson');
                $type = $this->input('type');

                if ($type === Lesson::TYPE_VIDEO && ! $this->hasVideoContent($lesson)) {
                    $validator->errors()->add('video_url', 'Vui lòng tải file video bài giảng lên hoặc nhập Video URL.');
                }

                if ($type === Lesson::TYPE_DOCUMENT && ! $this->hasDocumentContent($lesson)) {
                    $validator->errors()->add('content', 'Vui lòng nhập nội dung tài liệu hoặc tải tệp tài liệu lên.');
                }

                if ($type === Lesson::TYPE_ASSIGNMENT && ! $this->hasAssignmentContent($lesson)) {
                    $validator->errors()->add('content', 'Vui lòng nhập yêu cầu bài tập hoặc tải file đính kèm lên.');
                }

                if ($type === Lesson::TYPE_ASSIGNMENT
                    && $this->filled('assignment_max_score')
                    && $this->filled('assignment_passing_score')
                    && $this->integer('assignment_passing_score') > $this->integer('assignment_max_score')) {
                    $validator->errors()->add('assignment_passing_score', 'Điểm đạt không được lớn hơn điểm tối đa.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxVideoMegabytes = (int) ceil(max(1, (int) config('video.upload.max_bytes')) / 1048576);

        return [
            'title.required' => 'Vui lòng nhập tên bài học.',
            'title.string' => 'Tên bài học phải là chuỗi ký tự.',
            'title.max' => 'Tên bài học không được vượt quá :max ký tự.',

            'type.required' => 'Vui lòng chọn loại bài học.',
            'type.in' => 'Loại bài học không hợp lệ. Chọn: Video, Tài liệu, Quiz hoặc Bài tập.',

            'video_file.file' => 'Video bài giảng phải là một tệp tin hợp lệ.',
            'video_file.mimes' => 'Video bài giảng chỉ cho phép định dạng MP4, MOV, AVI, WEBM hoặc MKV.',
            'video_file.max' => "Dung lượng video bài giảng tối đa là {$maxVideoMegabytes}MB.",
            'video_file.prohibited_unless' => 'Chỉ upload video khi loại bài học là Video.',
            'video_size.max' => "Dung lượng video bài giảng tối đa là {$maxVideoMegabytes}MB.",

            'video_url.url' => 'Video URL phải là địa chỉ HTTP hoặc HTTPS hợp lệ.',
            'video_url.max' => 'Video URL không được vượt quá :max ký tự.',
            'video_url.prohibited_unless' => 'Chỉ nhập Video URL khi loại bài học là Video.',

            'content.string' => 'Nội dung bài học phải là chuỗi ký tự.',
            'content.prohibited_if' => 'Không nhập nội dung văn bản trực tiếp cho bài học Quiz.',

            'document_file.file' => 'Tệp tài liệu phải là một file.',
            'document_file.mimes' => 'Tệp tài liệu chỉ cho phép PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT, ZIP hoặc RAR.',
            'document_file.max' => 'Dung lượng tệp tài liệu tối đa là 10MB.',
            'document_file.prohibited' => 'Chỉ upload tệp tài liệu cho bài học Tài liệu hoặc Bài tập.',

            'assignment_due_days.integer' => 'Thời hạn bài tập phải là số nguyên.',
            'assignment_due_days.min' => 'Thời hạn bài tập phải từ :min ngày trở lên.',
            'assignment_due_days.max' => 'Thời hạn bài tập không được vượt quá :max ngày.',
            'assignment_due_days.prohibited' => 'Chỉ nhập thời hạn khi loại bài học là Bài tập.',
            'assignment_max_score.integer' => 'Điểm tối đa phải là số nguyên.',
            'assignment_max_score.min' => 'Điểm tối đa phải từ :min trở lên.',
            'assignment_max_score.max' => 'Điểm tối đa không được vượt quá :max.',
            'assignment_max_score.prohibited' => 'Chỉ nhập điểm tối đa khi loại bài học là Bài tập.',
            'assignment_passing_score.integer' => 'Điểm đạt phải là số nguyên.',
            'assignment_passing_score.min' => 'Điểm đạt không được nhỏ hơn :min.',
            'assignment_passing_score.max' => 'Điểm đạt không được vượt quá :max.',
            'assignment_passing_score.prohibited' => 'Chỉ nhập điểm đạt khi loại bài học là Bài tập.',

            'duration.integer' => 'Thời lượng phải là số nguyên.',
            'duration.min' => 'Thời lượng không được nhỏ hơn :min.',
            'duration.max' => 'Thời lượng không được vượt quá :max giây.',

            'sort_order.integer' => 'Thứ tự sắp xếp phải là số nguyên.',
            'sort_order.min' => 'Thứ tự sắp xếp không được nhỏ hơn :min.',
            'sort_order.max' => 'Thứ tự sắp xếp không được vượt quá :max.',

            'status.in' => 'Trạng thái bài học không hợp lệ.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'Tên bài học',
            'type' => 'Loại bài học',
            'video_file' => 'Video bài giảng',
            'video_url' => 'Video URL',
            'content' => 'Nội dung',
            'document_file' => 'Tệp tài liệu',
            'assignment_due_days' => 'Thời hạn bài tập',
            'assignment_max_score' => 'Điểm tối đa',
            'assignment_passing_score' => 'Điểm đạt',
            'duration' => 'Thời lượng',
            'is_preview' => 'Bài xem thử',
            'sort_order' => 'Thứ tự',
            'status' => 'Trạng thái',
        ];
    }

    private function hasVideoContent(?Lesson $lesson): bool
    {
        return $this->filled('s3_key')
            || $this->boolean('pending_video_upload')
            || $this->filled('video_url')
            || $this->hasFile('video_file')
            || ($lesson && ($lesson->video_url || $lesson->video_path || $lesson->original_video_key || $lesson->hls_manifest_key));
    }

    private function hasDocumentContent(?Lesson $lesson): bool
    {
        return $this->filled('content')
            || $this->hasFile('document_file')
            || ($lesson && $lesson->document_file);
    }

    private function hasAssignmentContent(?Lesson $lesson): bool
    {
        return $this->filled('content')
            || $this->hasFile('document_file')
            || ($lesson && ($lesson->content || $lesson->document_file));
    }
}
