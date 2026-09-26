<?php

namespace App\Http\Controllers\Web\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Instructor\StoreQuizRequest;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Services\QuizContentService;
use App\Services\QuizQuestionInvalidationService;
use App\Services\QuizVersioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuizController extends Controller
{
    public function __construct(
        private readonly QuizContentService $quizContent,
        private readonly QuizVersioningService $quizVersioning,
        private readonly QuizQuestionInvalidationService $questionInvalidations,
    ) {}

    public function show(Course $course, Lesson $lesson): View
    {
        $this->authorizeLesson($course, $lesson);

        if ($lesson->type === Lesson::TYPE_QUIZ) {
            $lesson->setRelation('quiz', $this->quizContent->getOrCreateForLesson($lesson));
        }

        $lesson->loadMissing('quiz');
        $quizIdentity = $lesson->quiz;
        $authoringVersion = $quizIdentity ? $this->quizVersioning->authoringVersion($quizIdentity) : null;
        $publishedVersion = $quizIdentity?->current_published_version_id
            ? $this->quizVersioning->currentPublished($quizIdentity)
            : null;
        $quiz = $quizIdentity && $authoringVersion
            ? $this->quizVersioning->projectVersion($quizIdentity, $authoringVersion)
            : $quizIdentity;
        $reviewUpdate = $quizIdentity && $quizIdentity->current_draft_version_id && $authoringVersion
            ? $this->quizVersioning->contentUpdateForVersion($quizIdentity, $authoringVersion)
            : null;
        $publishedMappings = collect();
        $publishedInvalidations = collect();
        $publishedInvalidationCounts = collect();

        if ($publishedVersion) {
            $publishedVersion->load([
                'questionMappings.questionVersion',
                'questionMappings.invalidations' => fn ($query) => $query->latest('id'),
            ]);
            $publishedMappings = $publishedVersion->questionMappings;
            $publishedInvalidations = $publishedMappings->mapWithKeys(fn ($mapping) => [
                $mapping->id => $mapping->invalidations->first(),
            ]);
            $publishedInvalidationCounts = $publishedMappings->mapWithKeys(fn ($mapping) => [
                $mapping->id => $this->questionInvalidations->counts($mapping),
            ]);
        }

        return view('instructor.quizzes.show', [
            'course' => $course,
            'lesson' => $lesson,
            'quiz' => $quiz,
            'quizValidation' => $authoringVersion ? $this->quizContent->validateQuizVersion($authoringVersion) : null,
            'questionTypes' => $this->quizContent->questionTypes(),
            'authoringVersion' => $authoringVersion,
            'publishedVersion' => $publishedVersion,
            'reviewUpdate' => $reviewUpdate,
            'mutationsLocked' => $reviewUpdate && ($reviewUpdate->isPending() || $reviewUpdate->isApproved()),
            'desiredIsActive' => $reviewUpdate
                ? (bool) data_get($reviewUpdate->payload, 'desired_is_active', $quizIdentity?->is_active)
                : (bool) $quizIdentity?->is_active,
            'publishedMappings' => $publishedMappings,
            'publishedInvalidations' => $publishedInvalidations,
            'publishedInvalidationCounts' => $publishedInvalidationCounts,
        ]);
    }

    public function store(StoreQuizRequest $request, Course $course, Lesson $lesson): RedirectResponse
    {
        $this->authorizeLesson($course, $lesson);

        $this->quizContent->saveMetadata($lesson, $request->validated(), $request->boolean('is_active'));

        return redirect()
            ->route('instructor.courses.lessons.quiz.show', [$course, $lesson])
            ->with('success', 'Đã lưu bản nháp Quiz.');
    }

    public function downloadSampleTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mau_cau_hoi_quiz.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Nội dung câu hỏi',
                'Loại câu hỏi (single_choice / multiple_choice / true_false)',
                'Điểm',
                'Giải thích đáp án',
                'Đáp án 1',
                'Đáp án 2',
                'Đáp án 3',
                'Đáp án 4',
                'Đáp án đúng (ví dụ: 1 hoặc 1,2 hoặc 1,3)',
            ]);

            fputcsv($handle, [
                'Lập trình Python là gì?',
                'single_choice',
                '1',
                'Python là ngôn ngữ lập trình bậc cao phổ biến.',
                'Ngôn ngữ lập trình',
                'Hệ điều hành',
                'Cơ sở dữ liệu',
                'Trình duyệt web',
                '1',
            ]);

            fputcsv($handle, [
                'Các kiểu dữ liệu cơ bản trong Python bao gồm những gì?',
                'multiple_choice',
                '2',
                'int, float và str là các kiểu dữ liệu tích hợp sẵn trong Python.',
                'int',
                'float',
                'str',
                'HTML',
                '1,2,3',
            ]);

            fputcsv($handle, [
                'HTML có phải là một ngôn ngữ lập trình không?',
                'true_false',
                '1',
                'HTML là ngôn ngữ đánh dấu siêu văn bản, không phải ngôn ngữ lập trình.',
                'Đúng',
                'Sai',
                '',
                '',
                '2',
            ]);

            fclose($handle);
        }, 200, $headers);
    }

    public function importQuestions(Request $request, Quiz $quiz): RedirectResponse
    {
        $this->authorizeQuiz($quiz);

        $request->validate([
            'import_file' => ['required', 'file', 'max:5120'],
        ], [
            'import_file.required' => 'Vui lòng chọn tệp Excel hoặc CSV để tải lên.',
            'import_file.file' => 'Tệp nhập không hợp lệ.',
            'import_file.max' => 'Dung lượng tệp tối đa là 5MB.',
        ]);

        $file = $request->file('import_file');
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['csv', 'txt', 'xlsx', 'xls'], true)) {
            return back()->with('error', 'Tệp phải có định dạng .csv hoặc .xlsx / .xls.')->withInput();
        }

        $rows = $this->readRowsFromFile($file->getPathname(), $extension);

        if (empty($rows)) {
            return back()->with('error', 'Không thể đọc nội dung tệp hoặc tệp rỗng. Vui lòng kiểm tra lại.')->withInput();
        }

        $importedCount = 0;

        // Tự động phát hiện định dạng file:
        // Định dạng 1 (gom nhóm): question_code | option_code | option_text | is_correct
        // Định dạng 2 (cũ): nội dung câu hỏi | loại | điểm | giải thích | đáp án 1..4 | đáp án đúng
        $isGroupedFormat = $this->detectGroupedFormat($rows);

        if ($isGroupedFormat) {
            $questions = $this->parseGroupedFormat($rows);
        } else {
            $questions = $this->parseFlatFormat($rows);
        }

        DB::transaction(function () use ($quiz, $questions, &$importedCount) {
            foreach ($questions as $q) {
                if (empty($q['option_data'])) {
                    continue;
                }

                $this->quizContent->createQuestion($quiz, [
                    'question_text' => $q['question_text'],
                    'question_type' => $q['question_type'],
                    'score' => $q['score'],
                    'explanation' => $q['explanation'],
                ], $q['option_data']);

                $importedCount++;
            }
        });

        if ($importedCount === 0) {
            return back()->with('error', 'Không tìm thấy câu hỏi hợp lệ trong tệp. Vui lòng sử dụng đúng định dạng file mẫu.')->withInput();
        }

        return back()->with('success', "Đã nhập thành công {$importedCount} câu hỏi từ tệp vào Quiz!");
    }

    private function readRowsFromFile(string $filePath, string $extension = 'csv'): array
    {
        $ext = strtolower($extension);

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            try {
                $reader = IOFactory::createReaderForFile($filePath);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = [];
                foreach ($worksheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    $cells = [];
                    foreach ($cellIterator as $cell) {
                        $val = $cell ? trim((string) $cell->getValue()) : '';
                        $cells[] = $val;
                    }
                    if (array_filter($cells, fn ($c) => $c !== '') !== []) {
                        $rows[] = $cells;
                    }
                }

                return $rows;
            } catch (\Throwable $e) {
                // Nếu đọc bằng Excel parser lỗi, fallback thử đọc dạng text bên dưới
            }
        }

        $rows = [];
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            return [];
        }

        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = ',';
        if ($firstLine !== false) {
            if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
                $delimiter = ';';
            } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
                $delimiter = "\t";
            }
        }

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        while (($data = fgetcsv($handle, 10000, $delimiter)) !== false) {
            if (array_filter($data, fn ($cell) => trim((string) $cell) !== '') !== []) {
                $rows[] = array_map(fn ($val) => trim((string) $val), $data);
            }
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeQuestionType(string $raw): string
    {
        $lower = mb_strtolower(trim($raw));

        if (str_contains($lower, 'multiple') || str_contains($lower, 'nhiều') || $lower === 'multiple_choice' || $lower === '2') {
            return QuizQuestion::TYPE_MULTIPLE;
        }

        if (str_contains($lower, 'true') || str_contains($lower, 'đúng') || str_contains($lower, 'sai') || $lower === 'true_false' || $lower === '3') {
            return QuizQuestion::TYPE_TRUE_FALSE;
        }

        return QuizQuestion::TYPE_SINGLE;
    }

    private function parseCorrectAnswers(string $raw, int $optionsCount, string $type): array
    {
        if ($raw === '') {
            return [1];
        }

        $parts = preg_split('/[;,\s]+/', $raw);
        $result = [];

        foreach ($parts as $p) {
            $p = trim($p);
            if (is_numeric($p)) {
                $idx = (int) $p;
                if ($idx >= 1 && $idx <= 20) {
                    $result[] = $idx;
                }
            } elseif (mb_strtolower($p) === 'đúng' || mb_strtolower($p) === 'true') {
                $result[] = 1;
            } elseif (mb_strtolower($p) === 'sai' || mb_strtolower($p) === 'false') {
                $result[] = 2;
            }
        }

        return array_unique($result);
    }

    /**
     * Nhận diện định dạng gom nhóm (mỗi hàng = 1 đáp án, nhiều hàng cho 1 câu hỏi).
     * Dấu hiệu: header có "option" hoặc "is_correct", hoặc cột đầu tiên lặp lại giá trị.
     */
    private function detectGroupedFormat(array $rows): bool
    {
        if (empty($rows)) {
            return false;
        }

        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0]);

        // Kiểm tra header có chứa các cột đặc trưng của định dạng gom nhóm
        $groupedKeywords = ['option_code', 'option_text', 'is_correct', 'option', 'correct'];
        foreach ($groupedKeywords as $kw) {
            foreach ($header as $col) {
                if (str_contains($col, $kw)) {
                    return true;
                }
            }
        }

        // Kiểm tra nếu cột A lặp lại giá trị (Q001, Q001, Q001...) → gom nhóm
        if (count($rows) > 3) {
            $col0Values = array_slice(array_column($rows, 0), 1, 6); // bỏ header
            $uniqueCount = count(array_unique($col0Values));
            $totalCount = count($col0Values);
            if ($totalCount > 0 && ($uniqueCount / $totalCount) < 0.7) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse định dạng gom nhóm:
     * Cột 0: question_code (text câu hỏi hoặc mã nhóm)
     * Cột 1: option_code (A/B/C/D/TRUE/FALSE...)
     * Cột 2: option_text (nội dung đáp án)
     * Cột 3: is_correct (TRUE/FALSE/1/0)
     */
    private function parseGroupedFormat(array $rows): array
    {
        // Bỏ qua header nếu hàng đầu không phải dữ liệu
        $startIndex = 0;
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0] ?? []);
        $hasHeader = collect($header)->contains(fn ($h) => str_contains($h, 'option') || str_contains($h, 'correct') || str_contains($h, 'code')
        );
        if ($hasHeader) {
            $startIndex = 1;
        }

        // Xác định vị trí các cột từ header (hoặc mặc định)
        $colQuestionCode = 0;
        $colOptionCode = 1;
        $colOptionText = 2;
        $colIsCorrect = 3;

        if ($hasHeader) {
            foreach ($header as $i => $h) {
                if (str_contains($h, 'question') && str_contains($h, 'code')) {
                    $colQuestionCode = $i;
                }
                if ($h === 'option_code' || ($i !== $colQuestionCode && str_contains($h, 'option') && str_contains($h, 'code'))) {
                    $colOptionCode = $i;
                }
                if ($h === 'option_text' || ($i !== $colQuestionCode && str_contains($h, 'option') && str_contains($h, 'text'))) {
                    $colOptionText = $i;
                }
                if (str_contains($h, 'is_correct') || str_contains($h, 'correct')) {
                    $colIsCorrect = $i;
                }
            }
        }

        // Gom nhóm các hàng theo question_code
        $grouped = [];
        $order = [];
        for ($i = $startIndex; $i < count($rows); $i++) {
            $row = $rows[$i];
            $code = trim((string) ($row[$colQuestionCode] ?? ''));
            if ($code === '') {
                continue;
            }
            if (! isset($grouped[$code])) {
                $grouped[$code] = [];
                $order[] = $code;
            }
            $grouped[$code][] = $row;
        }

        $questions = [];
        foreach ($order as $code) {
            $optionRows = $grouped[$code];

            // Xây dựng danh sách đáp án
            $optionData = [];
            $correctCount = 0;
            foreach ($optionRows as $sortOrder => $optRow) {
                $optCode = trim((string) ($optRow[$colOptionCode] ?? ''));
                $optText = trim((string) ($optRow[$colOptionText] ?? ''));
                $rawCorrect = mb_strtolower(trim((string) ($optRow[$colIsCorrect] ?? '')));

                if ($optText === '') {
                    continue;
                }

                $isCorrect = in_array($rawCorrect, ['true', '1', 'yes', 'đúng', 'correct'], true);
                if ($isCorrect) {
                    $correctCount++;
                }

                $optionEntry = [
                    'option_text' => $optText,
                    'is_correct' => $isCorrect,
                    'sort_order' => $sortOrder,
                ];

                // TRUE/FALSE identity
                $upperCode = mb_strtoupper($optCode);
                if ($upperCode === 'TRUE') {
                    $optionEntry['identity'] = 'TRUE';
                } elseif ($upperCode === 'FALSE') {
                    $optionEntry['identity'] = 'FALSE';
                }

                $optionData[] = $optionEntry;
            }

            if (empty($optionData)) {
                continue;
            }

            // Xác định loại câu hỏi
            $codes = array_map(
                fn ($r) => mb_strtoupper(trim((string) ($r[$colOptionCode] ?? ''))),
                $optionRows
            );
            $isTrueFalse = collect($codes)->contains(fn ($c) => in_array($c, ['TRUE', 'FALSE'], true));

            if ($isTrueFalse) {
                $type = QuizQuestion::TYPE_TRUE_FALSE;
                // Chuẩn hoá lại optionData cho true_false
                $optionData = [
                    [
                        'option_text' => 'Đúng',
                        'identity' => 'TRUE',
                        'is_correct' => collect($optionRows)
                            ->first(fn ($r) => mb_strtoupper(trim((string) ($r[$colOptionCode] ?? ''))) === 'TRUE')
                            ? in_array(
                                mb_strtolower(trim((string) (collect($optionRows)->first(fn ($r) => mb_strtoupper(trim((string) ($r[$colOptionCode] ?? ''))) === 'TRUE')[$colIsCorrect] ?? ''))),
                                ['true', '1', 'yes', 'đúng', 'correct'],
                                true
                            )
                            : true,
                        'sort_order' => 0,
                    ],
                    [
                        'option_text' => 'Sai',
                        'identity' => 'FALSE',
                        'is_correct' => collect($optionRows)
                            ->first(fn ($r) => mb_strtoupper(trim((string) ($r[$colOptionCode] ?? ''))) === 'FALSE')
                            ? in_array(
                                mb_strtolower(trim((string) (collect($optionRows)->first(fn ($r) => mb_strtoupper(trim((string) ($r[$colOptionCode] ?? ''))) === 'FALSE')[$colIsCorrect] ?? ''))),
                                ['true', '1', 'yes', 'đúng', 'correct'],
                                true
                            )
                            : false,
                        'sort_order' => 1,
                    ],
                ];
            } elseif ($correctCount > 1) {
                $type = QuizQuestion::TYPE_MULTIPLE;
            } else {
                $type = QuizQuestion::TYPE_SINGLE;
            }

            $questions[] = [
                'question_text' => $code,   // question_code được dùng làm nội dung câu hỏi
                'question_type' => $type,
                'score' => 1,
                'explanation' => null,
                'option_data' => $optionData,
            ];
        }

        return $questions;
    }

    /**
     * Parse định dạng phẳng (1 hàng = 1 câu hỏi đầy đủ):
     * Cột 0: nội dung | 1: loại | 2: điểm | 3: giải thích | 4-7: đáp án | 8: đáp án đúng
     */
    private function parseFlatFormat(array $rows): array
    {
        $questions = [];

        foreach ($rows as $index => $row) {
            // Bỏ qua header
            if ($index === 0 && (str_contains(mb_strtolower($row[0] ?? ''), 'nội dung') || str_contains(mb_strtolower($row[0] ?? ''), 'câu hỏi'))) {
                continue;
            }

            $questionText = trim((string) ($row[0] ?? ''));
            if ($questionText === '') {
                continue;
            }

            $type = $this->normalizeQuestionType(trim((string) ($row[1] ?? 'single_choice')));
            $score = max(1, (int) ($row[2] ?? 1));
            $explanation = trim((string) ($row[3] ?? '')) ?: null;

            $options = [];
            for ($col = 4; $col <= 7; $col++) {
                $optText = trim((string) ($row[$col] ?? ''));
                if ($optText !== '') {
                    $options[] = $optText;
                }
            }

            $rawCorrect = trim((string) ($row[8] ?? ''));
            $correctIndexes = $this->parseCorrectAnswers($rawCorrect, count($options), $type);

            if ($type === QuizQuestion::TYPE_TRUE_FALSE) {
                $correctIndex = in_array(2, $correctIndexes, true) ? 1 : 0;
                $optionData = [
                    ['option_text' => 'Đúng', 'identity' => 'TRUE',  'is_correct' => $correctIndex === 0, 'sort_order' => 0],
                    ['option_text' => 'Sai',  'identity' => 'FALSE', 'is_correct' => $correctIndex === 1, 'sort_order' => 1],
                ];
            } else {
                $optionData = collect($options)
                    ->values()
                    ->map(fn (string $opt, int $i) => [
                        'option_text' => $opt,
                        'is_correct' => in_array($i + 1, $correctIndexes, true)
                            || ($correctIndexes === [] && $i === 0),
                        'sort_order' => $i,
                    ])
                    ->all();
            }

            if (empty($optionData)) {
                continue;
            }

            $questions[] = [
                'question_text' => $questionText,
                'question_type' => $type,
                'score' => $score,
                'explanation' => $explanation,
                'option_data' => $optionData,
            ];
        }

        return $questions;
    }

    public function storeQuestion(Request $request, Quiz $quiz): RedirectResponse
    {
        $this->authorizeQuiz($quiz);
        $validated = $this->validatedQuestion($request);
        if ($request->hasFile('question_image')) {
            $validated['image_path'] = $request->file('question_image')->store('quiz-images', 'public');
        }

        $this->quizContent->createQuestion($quiz, $validated);

        return back()->with('success', 'Đã thêm câu hỏi vào bản nháp Quiz.');
    }

    public function updateQuestion(Request $request, QuizQuestion $question): RedirectResponse
    {
        $this->authorizeQuestion($question);
        $validated = $this->validatedQuestion($request);
        $authoringQuestion = $this->authoringQuestion($question);
        if ($request->boolean('remove_question_image')) {
            $validated['image_path'] = null;
        } elseif ($request->hasFile('question_image')) {
            $validated['image_path'] = $request->file('question_image')->store('quiz-images', 'public');
        } else {
            $validated['image_path'] = $authoringQuestion->image_path;
        }

        $this->quizContent->updateQuestion($question, $validated);

        return back()->with('success', 'Đã cập nhật câu hỏi trong bản nháp Quiz.');
    }

    public function destroyQuestion(QuizQuestion $question): RedirectResponse
    {
        $this->authorizeQuestion($question);
        $this->quizContent->deleteQuestion($question);

        return back()->with('success', 'Đã gỡ câu hỏi khỏi bản nháp Quiz.');
    }

    public function storeAnswer(Request $request, QuizQuestion $question): RedirectResponse
    {
        $this->authorizeQuestion($question);
        $validated = $this->validatedAnswer($request);

        $this->quizContent->createOption($question, $validated);

        return back()->with('success', 'Đã thêm đáp án vào bản nháp Quiz.');
    }

    public function updateAnswers(Request $request, QuizQuestion $question): RedirectResponse
    {
        $this->authorizeQuestion($question);
        $authoringQuestion = $this->authoringQuestion($question);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*.answer_text' => ['required', 'string', 'max:5000'],
            'answers.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'correct_answer' => ['nullable', 'integer'],
            'correct_answers' => ['nullable', 'array'],
            'correct_answers.*' => ['integer'],
            'delete_answers' => ['nullable', 'array'],
            'delete_answers.*' => ['integer'],
        ]);

        $selectedCorrectIds = $authoringQuestion->type === QuizQuestion::TYPE_MULTIPLE
            ? collect($request->input('correct_answers', []))->map(fn ($id) => (int) $id)
            : collect([$request->input('correct_answer')])->map(fn ($id) => (int) $id);

        $this->quizContent->updateOptions(
            $question,
            $validated['answers'],
            $request->input('delete_answers', []),
            $selectedCorrectIds->filter()->values()->all(),
        );

        return back()->with('success', 'Đã lưu đáp án trong bản nháp Quiz.');
    }

    public function updateAnswer(Request $request, QuizOption $answer): RedirectResponse
    {
        $this->authorizeAnswer($answer);
        $validated = $this->validatedAnswer($request);

        $this->quizContent->updateOption($answer, $validated);

        return back()->with('success', 'Đã cập nhật đáp án trong bản nháp Quiz.');
    }

    public function destroyAnswer(QuizOption $answer): RedirectResponse
    {
        $this->authorizeAnswer($answer);
        $this->quizContent->deleteOption($answer);

        return back()->with('success', 'Đã xóa đáp án khỏi bản nháp Quiz.');
    }

    private function validatedQuestion(Request $request): array
    {
        return $request->validate([
            'question_text' => ['required', 'string', 'max:10000'],
            'question_type' => ['required', Rule::in(array_keys($this->quizContent->questionTypes()))],
            'score' => ['required', 'integer', 'min:1', 'max:1000'],
            'explanation' => ['nullable', 'string', 'max:10000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'question_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_question_image' => ['nullable', 'boolean'],
        ], [
            'question_text.required' => 'Vui lòng nhập nội dung câu hỏi.',
            'question_text.string' => 'Nội dung câu hỏi phải là chuỗi ký tự.',
            'question_text.max' => 'Nội dung câu hỏi không được vượt quá 10.000 ký tự.',
            'question_type.required' => 'Vui lòng chọn loại câu hỏi.',
            'question_type.in' => 'Loại câu hỏi không hợp lệ.',
            'score.required' => 'Vui lòng nhập số điểm cho câu hỏi.',
            'score.integer' => 'Điểm phải là số nguyên.',
            'score.min' => 'Điểm tối thiểu là 1.',
            'score.max' => 'Điểm tối đa là 1000.',
        ]);
    }

    private function validatedAnswer(Request $request): array
    {
        $validated = $request->validate([
            'answer_text' => ['required', 'string', 'max:5000'],
            'is_correct' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ], [
            'answer_text.required' => 'Vui lòng nhập nội dung đáp án.',
            'answer_text.string' => 'Nội dung đáp án phải là chuỗi ký tự.',
            'answer_text.max' => 'Nội dung đáp án không được vượt quá 5000 ký tự.',
        ]);

        $validated['is_correct'] = $request->boolean('is_correct');

        return $validated;
    }

    private function authorizeLesson(Course $course, Lesson $lesson): void
    {
        abort_unless($course->isOwnedBy(auth()->user()), 403);
        abort_unless($lesson->belongsToCourse($course), 404);
    }

    private function authorizeQuiz(Quiz $quiz): void
    {
        $quiz->loadMissing('lesson.section.course', 'lesson.chapter.course', 'lesson.course');

        abort_unless($this->courseOwnedByInstructor($quiz->lesson?->owningCourse()), 403);
    }

    private function authorizeQuestion(QuizQuestion $question): void
    {
        $question->loadMissing('quiz.lesson.section.course', 'quiz.lesson.chapter.course', 'quiz.lesson.course');

        abort_unless($this->courseOwnedByInstructor($question->quiz?->lesson?->owningCourse()), 403);
    }

    private function authorizeAnswer(QuizOption $answer): void
    {
        $answer->loadMissing('question.quiz.lesson.section.course', 'question.quiz.lesson.chapter.course', 'question.quiz.lesson.course');

        abort_unless($this->courseOwnedByInstructor($answer->question?->quiz?->lesson?->owningCourse()), 403);
    }

    private function courseOwnedByInstructor(?Course $course): bool
    {
        return $course !== null && $course->isOwnedBy(auth()->user());
    }

    private function authoringQuestion(QuizQuestion $question): QuizQuestion
    {
        $question->loadMissing('quiz');
        $version = $this->quizVersioning->authoringVersion($question->quiz);

        if (! $version) {
            abort(422, 'Quiz chưa có phiên bản nội dung để chỉnh sửa.');
        }

        $projectedQuiz = $this->quizVersioning->projectVersion($question->quiz, $version);
        $projected = $projectedQuiz->questions->firstWhere('id', $question->id);

        abort_unless($projected, 422, 'Câu hỏi không thuộc phiên bản Quiz hiện tại.');

        return $projected;
    }

}
