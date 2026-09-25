import { renderMath } from './math-renderer';

document.addEventListener('DOMContentLoaded', () => {
    initLearningSidebar();
    initVideoProgressV2();
    initYouTubeProgress();
    initQuizPlayer();
    initReadingProgress();
    initCertificateDropdown();
    initLessonNotes();
    initStudyNotesPage();
    initLessonAi();
    initAiStudyAssistant();
});

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function showToast(message, type = 'info') {
    const normalizedType = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
    const safeMessage = typeof message === 'string' && message.trim() !== ''
        ? message
        : 'Đã xảy ra sự cố. Vui lòng thử lại.';

    if (window.AppToast?.show) {
        window.AppToast.show({ type: normalizedType, message: safeMessage });
        return;
    }

    console.error('Shared toast API is unavailable.', { type: normalizedType, message: safeMessage });
}

function createUserFacingError(message) {
    const error = new Error(message);
    error.userFacingMessage = typeof message === 'string' && message.trim() !== '' ? message : null;

    return error;
}

function getUserFacingErrorMessage(error, fallback) {
    return typeof error?.userFacingMessage === 'string' && error.userFacingMessage.trim() !== ''
        ? error.userFacingMessage
        : fallback;
}

function updateHeaderProgress(percent) {
    const bar = document.querySelector('[data-header-progress-bar]');
    const text = document.querySelector('[data-header-progress-text]');
    const safe = Math.min(100, Math.max(0, Number(percent) || 0));

    if (bar) bar.style.width = `${safe}%`;
    if (text) text.textContent = `${Math.round(safe)}%`;
}

function initLearningSidebar() {
    const sidebar = document.querySelector('[data-learning-sidebar]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const main = document.querySelector('[data-learning-main]');
    if (!sidebar) return;

    const setOpen = (open) => {
        sidebar.dataset.sidebarOpen = open ? 'true' : 'false';
        sidebar.classList.toggle('learning-sidebar--closed', !open);
        if (main) main.classList.toggle('learning-main--expanded', !open);
        if (backdrop) backdrop.classList.toggle('hidden', open || window.innerWidth >= 1024);
    };

    document.querySelectorAll('[data-toggle-sidebar], [data-toggle-sidebar-desktop]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const isOpen = sidebar.dataset.sidebarOpen !== 'false';
            setOpen(!isOpen);
        });
    });

    document.querySelector('[data-close-sidebar]')?.addEventListener('click', () => setOpen(false));
    backdrop?.addEventListener('click', () => setOpen(false));

    if (window.innerWidth < 1024) {
        setOpen(false);
    }
}

function initVideoProgressV2() {
    const video = document.querySelector('[data-lesson-progress-video]');
    if (!video) return;

    const progressUrl = video.dataset.progressUrl;
    const durationHint = Number(video.dataset.durationSeconds || 0);
    const resumeThreshold = 5;
    let lastSavedPosition = Math.floor(Number(video.dataset.initialLastPosition || video.dataset.initialWatched || 0));
    let furthestPosition = Math.floor(Number(video.dataset.initialFurthestPosition || video.dataset.initialWatched || 0));
    let unsavedPlayedSeconds = 0;
    let lastPlayhead = null;
    let lastSaveStartedAt = Date.now();
    let requestInFlight = false;
    let pendingSave = false;

    const durationSeconds = () => Math.floor(Number.isFinite(video.duration) && video.duration > 0 ? video.duration : durationHint);
    const currentPosition = () => clampVideoTime(video.currentTime || 0, durationSeconds());
    const nowIso = () => new Date().toISOString();

    const notePlayedSegment = () => {
        const current = currentPosition();
        if (lastPlayhead === null) {
            lastPlayhead = current;
            return;
        }

        const delta = current - lastPlayhead;
        if (!video.paused && !video.seeking && delta > 0 && delta <= 2.5) {
            unsavedPlayedSeconds += delta;
            furthestPosition = Math.max(furthestPosition, current);
        }
        lastPlayhead = current;
    };

    const payload = () => ({
        last_position_seconds: currentPosition(),
        furthest_position_seconds: Math.floor(furthestPosition),
        played_seconds: Math.floor(unsavedPlayedSeconds),
        video_duration_seconds: durationSeconds(),
        client_updated_at: nowIso(),
    });

    const applyProgressResponse = (data) => {
        if (typeof data.course_progress === 'number') {
            updateHeaderProgress(data.course_progress);
        }

        if (typeof data.lesson_progress === 'number') {
            updateCurrentLessonProgress(data.lesson_progress, data.lesson_completed);
        }

        if (typeof data.last_position_seconds === 'number') {
            lastSavedPosition = data.last_position_seconds;
        }

        if (typeof data.furthest_position_seconds === 'number') {
            furthestPosition = Math.max(furthestPosition, data.furthest_position_seconds);
        }

    };

    const sendProgress = async (forceSend = false, options = {}) => {
        if (!progressUrl) return;

        notePlayedSegment();
        const body = payload();
        const hasPlayed = body.played_seconds > 0;
        const elapsed = Date.now() - lastSaveStartedAt;

        if (!forceSend && (!hasPlayed || elapsed < 10000)) {
            return;
        }

        if (requestInFlight) {
            pendingSave = true;
            return;
        }

        requestInFlight = true;
        lastSaveStartedAt = Date.now();

        try {
            const response = await fetch(progressUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: Boolean(options.keepalive),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(body),
            });

            const data = await response.json().catch(() => ({}));
            if (response.status === 409) {
                applyProgressResponse(data);
                return;
            }

            if (!response.ok) throw new Error('progress_failed');

            unsavedPlayedSeconds = Math.max(0, unsavedPlayedSeconds - body.played_seconds);
            applyProgressResponse(data);
        } catch {
            if (!options.silent) {
                showToast('Chưa lưu được tiến độ. Hệ thống sẽ thử lại.', 'error');
            }
        } finally {
            requestInFlight = false;
            if (pendingSave) {
                pendingSave = false;
                sendProgress(true, { silent: true });
            }
        }
    };

    const sendBeaconProgress = () => {
        if (!progressUrl) return;

        notePlayedSegment();
        const body = payload();
        if (body.played_seconds <= 0 && Math.abs(body.last_position_seconds - lastSavedPosition) < 1) return;

        const formData = new FormData();
        formData.append('_token', getCsrfToken());
        Object.entries(body).forEach(([key, value]) => formData.append(key, value));
        navigator.sendBeacon?.(progressUrl, formData);
    };

    const showResumePrompt = () => {
        const saved = clampVideoTime(lastSavedPosition, durationSeconds());
        if (saved < resumeThreshold) return;

        const stage = video.closest('.learning-video-stage');
        if (!stage || stage.querySelector('[data-video-resume-panel]')) return;

        const panel = document.createElement('div');
        panel.dataset.videoResumePanel = '1';
        panel.className = 'absolute left-4 top-4 z-[60] max-w-sm rounded border border-white/20 bg-black/75 p-4 text-white shadow-lg';
        panel.innerHTML = `
            <p class="text-sm font-semibold">Bạn đã học đến ${formatTime(saved)}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" data-resume-video class="rounded bg-[#0056D2] px-3 py-2 text-sm font-bold text-white hover:bg-[#0046B8]">Tiếp tục học</button>
                <button type="button" data-replay-video class="rounded border border-white/30 px-3 py-2 text-sm font-semibold text-white hover:bg-white/10">Xem lại từ đầu</button>
            </div>
        `;
        stage.appendChild(panel);

        panel.querySelector('[data-resume-video]')?.addEventListener('click', () => {
            video.currentTime = saved;
            lastPlayhead = saved;
            panel.remove();
            video.play().catch(() => {});
        });

        panel.querySelector('[data-replay-video]')?.addEventListener('click', () => {
            video.currentTime = 0;
            lastPlayhead = 0;
            panel.remove();
            sendProgress(true, { silent: true });
        });
    };

    video.addEventListener('loadedmetadata', () => {
        if (requestedStartTimeFromUrl() !== null) return;
        showResumePrompt();
    }, { once: true });

    video.addEventListener('play', () => {
        lastPlayhead = currentPosition();
        sendProgress(true, { silent: true });
    });
    video.addEventListener('timeupdate', () => {
        notePlayedSegment();
        sendProgress(false, { silent: true });
    });
    video.addEventListener('seeking', () => {
        lastPlayhead = null;
        sendProgress(true, { silent: true });
    });
    video.addEventListener('pause', () => sendProgress(true));
    video.addEventListener('ended', () => sendProgress(true));
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) sendProgress(true, { keepalive: true, silent: true });
    });
    window.addEventListener('pagehide', sendBeaconProgress);
    window.addEventListener('beforeunload', sendBeaconProgress);
}

function updateCurrentLessonProgress(percent, completed = false) {
    const item = document.querySelector('[data-current-lesson-item]');
    if (!item) return;

    const safe = Math.min(100, Math.max(0, Number(percent) || 0));
    const percentEl = item.querySelector('[data-lesson-progress-percent]');
    const statusEl = item.querySelector('[data-lesson-progress-status]');

    if (percentEl) percentEl.textContent = `${Math.round(safe)}%`;
    if (statusEl) statusEl.textContent = completed ? 'Hoàn thành' : (safe > 0 ? 'Đang học' : 'Chưa học');
}

function initReadingProgress() {
    const root = document.querySelector('[data-learning-player]');
    if (root?.dataset.lessonType !== 'document' || !root.dataset.progressUrl) return;
    let seconds = 0;
    let saving = false;
    let done = false;
    const timer = setInterval(async () => {
        if (done || saving || document.hidden) return;
        seconds++;
        if (seconds < 30) return;
        saving = true;
        try {
            const response = await fetch(root.dataset.progressUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ watched_seconds: 0, completed: true }),
            });
            if (!response.ok) {
                if ([401, 403, 404, 419].includes(response.status)) {
                    clearInterval(timer);
                    showToast('Không thể lưu tiến độ. Vui lòng tải lại trang để kiểm tra quyền truy cập.', 'error');
                }
                seconds = 25;
                return;
            }
            const data = await response.json();
            if (data.lesson_completed) {
                done = true;
                clearInterval(timer);
                updateHeaderProgress(data.course_progress);
                updateCurrentLessonProgress(100, true);
                showToast('Bạn đã hoàn thành bài đọc.');
            }
        } catch {
            seconds = 25;
        } finally {
            saving = false;
        }
    }, 1000);
    window.addEventListener('pagehide', () => clearInterval(timer), { once: true });
}

function initQuizPlayer() {
    const root = document.querySelector('[data-quiz-player]');
    if (!root) return;

    const quiz = JSON.parse(root.dataset.quiz || '{}');
    if (!quiz.questions?.length) return;
    const isStandalone = root.dataset.quizStandalone === 'true';

    // Một lượt đang diễn ra luôn tiếp tục ở màn hình Quiz riêng, không nằm trong lesson player.
    if (!isStandalone && quiz.attempt_id && quiz.standalone_url) {
        window.location.replace(quiz.standalone_url);
        return;
    }

    const intro = root.querySelector('[data-quiz-intro]');
    const active = root.querySelector('[data-quiz-active]');
    const result = root.querySelector('[data-quiz-result]');
    const terminatedEl = root.querySelector('[data-quiz-terminated]');
    const terminatedMsg = root.querySelector('[data-quiz-terminated-msg]');
    const terminatedAttempts = root.querySelector('[data-quiz-terminated-attempts]');
    const terminatedRetryBtn = root.querySelector('[data-quiz-terminated-retry]');
    const questionContainer = root.querySelector('[data-quiz-question-container]');
    const progressLabel = root.querySelector('[data-quiz-progress-label]');
    const progressBar = root.querySelector('[data-quiz-progress-bar]');
    const timerEl = root.querySelector('[data-quiz-timer]');
    const prevBtn = root.querySelector('[data-quiz-prev]');
    const nextBtn = root.querySelector('[data-quiz-next]');
    const agreeRulesCheckbox = root.querySelector('[data-quiz-agree-rules]');
    const startButton = root.querySelector('[data-quiz-start]');
    const watermarkLayer = root.querySelector('[data-quiz-watermark]');
    const watermarkPattern = root.querySelector('[data-quiz-watermark-pattern]');
    const offlineAlert = root.querySelector('[data-quiz-offline-alert]');

    let currentIndex = 0;
    const answers = quiz.saved_answers && typeof quiz.saved_answers === 'object' ? { ...quiz.saved_answers } : {};
    let timerId = null;
    let autoSaveTimer = null;
    let remainingSeconds = quiz.remaining_seconds ?? (quiz.time_limit_minutes ? quiz.time_limit_minutes * 60 : null);
    let isQuizActive = false;
    let isTerminated = false;

    // Checkbox đồng ý quy định
    if (agreeRulesCheckbox && startButton) {
        agreeRulesCheckbox.addEventListener('change', () => {
            startButton.disabled = !agreeRulesCheckbox.checked;
        });
    }

    // Khởi tạo Watermark động
    const initWatermark = () => {
        if (!watermarkLayer || !watermarkPattern) return;
        const user = quiz.user_info || {};
        const userName = user.name || 'Học viên';
        const userEmail = user.email || (user.id ? `ID: ${user.id}` : '');
        const now = new Date();
        const timeStr = `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')} ${now.toLocaleDateString('vi-VN')}`;
        const stamp = `${userName} • ${userEmail} • ${timeStr} • Quiz #${quiz.id}`;

        let items = '';
        for (let i = 0; i < 24; i++) {
            items += `<span class="inline-block m-4 select-none opacity-60">${escapeHtml(stamp)}</span>`;
        }
        watermarkPattern.innerHTML = items;
        watermarkLayer.hidden = false;
    };

    const formatPayloadAnswers = () => {
        const payloadAnswers = {};
        Object.entries(answers).forEach(([questionId, ids]) => {
            const question = quiz.questions.find((q) => String(q.id) === String(questionId));
            payloadAnswers[questionId] = question?.type === 'multiple' ? ids : (Array.isArray(ids) ? ids[0] : ids);
        });
        return payloadAnswers;
    };

    // Tự động lưu đáp án với Debounce
    const autoSave = (delay = 400) => {
        if (!quiz.attempt_id || !isQuizActive || !quiz.save_progress_url) return;
        if (autoSaveTimer) clearTimeout(autoSaveTimer);

        autoSaveTimer = setTimeout(async () => {
            try {
                const payload = {
                    attempt_id: quiz.attempt_id,
                    answers: formatPayloadAnswers(),
                    remaining_seconds: remainingSeconds,
                };
                sessionStorage.setItem(`quiz_answers_${quiz.attempt_id}`, JSON.stringify(payload.answers));

                await fetch(quiz.save_progress_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    body: JSON.stringify(payload),
                });
            } catch (err) {
                // Tạm thời giữ trong sessionStorage nếu mất mạng
            }
        }, delay);
    };

    const renderQuestion = () => {
        const question = quiz.questions[currentIndex];
        const isMultiple = question.type === 'multiple';
        const rawSelected = answers[question.id] || [];
        const selected = Array.isArray(rawSelected) ? rawSelected : [rawSelected];

        progressLabel.textContent = `Câu ${currentIndex + 1} / ${quiz.questions.length}`;
        progressBar.style.width = `${((currentIndex + 1) / quiz.questions.length) * 100}%`;
        prevBtn.disabled = currentIndex === 0;
        nextBtn.disabled = false;
        nextBtn.textContent = currentIndex === quiz.questions.length - 1 ? 'Nộp bài' : 'Câu tiếp theo';

        questionContainer.innerHTML = `
            <div class="rounded border border-white/10 bg-white/5 p-5 relative z-20">
                <p class="text-xs font-semibold uppercase tracking-wide text-violet-300">${question.form_type || question.type}</p>
                <h3 class="mt-2 text-lg font-bold"><span data-math-content>${escapeHtml(question.question)}</span></h3>
                ${question.image_url ? `<img src="${escapeHtml(question.image_url)}" alt="Minh họa câu hỏi" class="mt-4 max-h-80 w-full rounded-lg object-contain bg-black/20">` : ''}
                <p class="mt-1 text-xs text-white/60">${question.points} điểm</p>
                ${question.is_excluded ? '<div role="status" class="mt-4 rounded border border-amber-300/40 bg-amber-500/10 p-3 text-sm font-semibold text-amber-100">Câu hỏi này đã bị hủy và sẽ không được tính điểm.</div>' : ''}
                <div class="mt-4 space-y-2">
                    ${question.options.map((option) => {
                        const checked = selected.map(Number).includes(Number(option.id));
                        const inputType = isMultiple ? 'checkbox' : 'radio';
                        const name = isMultiple ? `q_${question.id}[]` : `q_${question.id}`;
                        return `
                            <label class="flex cursor-pointer items-start gap-3 rounded border border-white/10 p-3 hover:bg-white/5 transition">
                                <input type="${inputType}" name="${name}" value="${option.id}" ${checked ? 'checked' : ''} class="mt-1" data-option-input data-question-id="${question.id}">
                                <span class="text-sm leading-6" data-math-content>${escapeHtml(option.text)}</span>
                            </label>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
        window.MathJax?.typesetPromise?.([questionContainer]);

        renderMath(questionContainer);

        questionContainer.querySelectorAll('[data-option-input]').forEach((input) => {
            input.addEventListener('change', () => {
                const qid = Number(input.dataset.questionId);
                const q = quiz.questions.find((item) => item.id === qid);
                if (!q) return;

                if (q.type === 'multiple') {
                    const checked = [...questionContainer.querySelectorAll(`[data-question-id="${qid}"]:checked`)].map((el) => Number(el.value));
                    answers[qid] = checked;
                } else {
                    answers[qid] = [Number(input.value)];
                }

                autoSave(300);
            });
        });
    };

    const startTimer = () => {
        if (remainingSeconds === null || !timerEl) return;
        timerEl.hidden = false;
        timerEl.textContent = formatTime(remainingSeconds);
        if (remainingSeconds <= 0) {
            terminateAttempt('time_expired');
            return;
        }

        timerId = window.setInterval(() => {
            remainingSeconds -= 1;
            timerEl.textContent = formatTime(remainingSeconds);
            if (remainingSeconds <= 0) {
                window.clearInterval(timerId);
                terminateAttempt('time_expired');
            }
        }, 1000);
    };

    // Hủy lượt làm bài do vi phạm (Terminated)
    const terminateAttempt = (reason = 'tab_switch') => {
        if (!isQuizActive || isTerminated) return;
        isTerminated = true;
        isQuizActive = false;

        if (timerId) window.clearInterval(timerId);
        if (autoSaveTimer) clearTimeout(autoSaveTimer);

        if (document.fullscreenElement || document.webkitFullscreenElement) {
            try {
                if (document.exitFullscreen) document.exitFullscreen();
                else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
            } catch (e) {}
        }

        // Ẩn vùng làm bài và hiển thị màn hình thông báo vi phạm ngay lập tức
        active.hidden = true;
        intro.hidden = true;
        result.hidden = true;
        if (terminatedEl) terminatedEl.hidden = false;

        const reasonLabels = {
            tab_switch: 'Chuyển sang tab hoặc ứng dụng khác trong quá trình làm bài.',
            window_blur: 'Màn hình làm bài bị mất tiêu điểm (mất focus).',
            fullscreen_exit: 'Thoát khỏi chế độ toàn màn hình (Fullscreen).',
            page_exit: 'Rời khỏi trang làm bài.',
            time_expired: 'Hết thời gian làm bài kiểm tra.',
        };

        if (terminatedMsg) {
            terminatedMsg.textContent = `Hệ thống phát hiện vi phạm: ${reasonLabels[reason] || 'Không tuân thủ quy định làm bài'}. Theo quy định, lần làm bài hiện tại đã bị kết thúc và ghi nhận kết quả.`;
        }

        const payload = {
            attempt_id: quiz.attempt_id,
            reason: reason,
            answers: formatPayloadAnswers(),
            remaining_seconds: remainingSeconds,
        };

        const endpoint = quiz.terminate_url;
        if (!endpoint) return;

        // Ưu tiên dùng fetch với keepalive hoặc sendBeacon để gửi chắc chắn
        try {
            fetch(endpoint, {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(payload),
            })
                .then((res) => res.json())
                .then((data) => {
                    if (data && data.success) {
                        const remaining = data.remaining_attempts;
                        if (terminatedAttempts) {
                            terminatedAttempts.textContent = remaining !== null
                                ? `Bạn còn ${remaining}/${quiz.max_attempts ?? 3} lần làm bài.`
                                : 'Bạn có thể làm lại bài kiểm tra.';
                        }
                        if (terminatedRetryBtn && (remaining === null || remaining > 0)) {
                            terminatedRetryBtn.hidden = false;
                        }
                        if (isStandalone && data.attempt?.result_url) {
                            window.location.replace(data.attempt.result_url);
                        }
                    }
                })
                .catch(() => {});
        } catch (e) {
            try {
                const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
                navigator.sendBeacon(endpoint, blob);
            } catch (err) {}
        }
    };

    // Thiết lập các cơ chế giám sát vi phạm
    const setupProctoring = () => {
        // 1. Chống chụp màn hình / in trang (Ctrl+P, Ctrl+S)
        document.addEventListener('keydown', (e) => {
            if (!isQuizActive) return;
            if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P' || e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                showToast('Thao tác này bị vô hiệu hóa trong khi làm bài kiểm tra.', 'warning');
            }
        });

        // 2. Chặn context menu
        root.addEventListener('contextmenu', (e) => {
            if (isQuizActive) e.preventDefault();
        });

        // 3. Giám sát Chuyển Tab (visibilitychange)
        document.addEventListener('visibilitychange', () => {
            if (isQuizActive && document.visibilityState === 'hidden') {
                terminateAttempt('tab_switch');
            }
        });

        // 4. Giám sát Rời màn hình / Window Blur
        window.addEventListener('blur', () => {
            if (isQuizActive) {
                terminateAttempt('window_blur');
            }
        });

        // 5. Giám sát Pagehide
        window.addEventListener('pagehide', () => {
            if (isQuizActive) {
                terminateAttempt('page_exit');
            }
        });

        // 6. Giám sát Thoát Fullscreen
        const handleFullscreenChange = () => {
            const isFull = !!(document.fullscreenElement || document.webkitFullscreenElement);
            if (isQuizActive && !isFull) {
                terminateAttempt('fullscreen_exit');
            }
        };
        document.addEventListener('fullscreenchange', handleFullscreenChange);
        document.addEventListener('webkitfullscreenchange', handleFullscreenChange);

        // 7. Xử lý Mất mạng tạm thời
        window.addEventListener('offline', () => {
            if (offlineAlert) offlineAlert.classList.remove('hidden');
        });
        window.addEventListener('online', () => {
            if (offlineAlert) offlineAlert.classList.add('hidden');
            if (isQuizActive) autoSave(0);
        });
    };

    const activateQuiz = () => {
        intro.hidden = true;
        if (terminatedEl) terminatedEl.hidden = true;
        result.hidden = true;
        active.hidden = false;
        currentIndex = 0;
        isQuizActive = true;
        isTerminated = false;

        initWatermark();
        renderQuestion();
        startTimer();
    };

    const startQuiz = async () => {
        // Lesson chỉ là màn hình khởi động. Attempt vẫn được tạo bằng endpoint hiện có,
        // sau đó toàn bộ phiên làm bài tiếp tục ở route Quiz độc lập.
        if (!isStandalone && quiz.standalone_url) {
            if (!quiz.start_url || !startButton) return;
            startButton.disabled = true;
            try {
                const response = await fetch(quiz.start_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw createUserFacingError(data.message || 'Không thể bắt đầu quiz.');
                window.location.assign(quiz.standalone_url);
            } catch (error) {
                startButton.disabled = false;
                showToast(getUserFacingErrorMessage(error, 'Không thể bắt đầu quiz.'), 'error');
            }
            return;
        }

        // Yêu cầu Fullscreen trước khi bắt đầu
        try {
            if (document.documentElement.requestFullscreen) {
                await document.documentElement.requestFullscreen();
            } else if (document.documentElement.webkitRequestFullscreen) {
                await document.documentElement.webkitRequestFullscreen();
            }
        } catch (err) {
            showToast('Vui lòng cho phép chế độ toàn màn hình để bắt đầu bài kiểm tra.', 'warning');
        }

        if (quiz.attempt_id) {
            activateQuiz();
            return;
        }

        if (!quiz.start_url || !startButton) return;
        startButton.disabled = true;
        try {
            const response = await fetch(quiz.start_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw createUserFacingError(data.message || 'Không thể bắt đầu quiz.');
            window.location.reload();
        } catch (error) {
            startButton.disabled = false;
            showToast(getUserFacingErrorMessage(error, 'Không thể bắt đầu quiz.'), 'error');
        }
    };

    const showConfirmSubmitModal = (unansweredCount) => {
        return new Promise((resolve) => {
            const modal = document.getElementById('quiz-confirm-submit-modal');
            const msgEl = document.getElementById('quiz-confirm-submit-message');
            const okBtn = document.getElementById('quiz-confirm-submit-ok');
            const cancelBtn = document.getElementById('quiz-confirm-submit-cancel');
            const backdrop = document.getElementById('quiz-confirm-submit-backdrop');

            window.dispatchEvent(new CustomEvent('quiz:submitting'));

            if (!modal || !okBtn || !cancelBtn) {
                window.dispatchEvent(new CustomEvent('quiz:deactivate'));
                const ok = window.confirm(`Bạn còn ${unansweredCount} câu chưa trả lời. Bạn có chắc muốn nộp bài?`);
                resolve(ok);
                return;
            }

            if (msgEl) {
                msgEl.textContent = `Bạn còn ${unansweredCount} câu hỏi chưa trả lời. Bạn có chắc chắn muốn nộp bài kiểm tra ngay không?`;
            }

            modal.classList.remove('hidden');

            const cleanup = () => {
                modal.classList.add('hidden');
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                backdrop?.removeEventListener('click', onCancel);
            };

            const onOk = () => {
                cleanup();
                window.dispatchEvent(new CustomEvent('quiz:deactivate'));
                resolve(true);
            };

            const onCancel = () => {
                cleanup();
                resolve(false);
            };

            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            backdrop?.addEventListener('click', onCancel);
        });
    };

    const submitQuiz = async (auto = false) => {
        const unanswered = quiz.questions.filter((q) => !answers[q.id]?.length);
        if (!auto && unanswered.length > 0) {
            const ok = await showConfirmSubmitModal(unanswered.length);
            if (!ok) return;
        }

        isQuizActive = false;
        window.dispatchEvent(new CustomEvent('quiz:deactivate'));
        nextBtn.disabled = true;
        nextBtn.textContent = 'Đang nộp bài...';
        prevBtn.disabled = true;
        if (timerId) window.clearInterval(timerId);
        if (autoSaveTimer) clearTimeout(autoSaveTimer);

        if (document.fullscreenElement || document.webkitFullscreenElement) {
            try {
                if (document.exitFullscreen) document.exitFullscreen();
                else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
            } catch (e) {}
        }

        const payload = {
            attempt_id: quiz.attempt_id,
            answers: formatPayloadAnswers(),
            remaining_seconds: remainingSeconds,
        };

        try {
            const response = await fetch(quiz.submit_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw createUserFacingError(data.message || 'Không thể nộp bài quiz.');
            }

            active.hidden = true;
            result.hidden = false;
            renderQuizResult(data);

            if (typeof data.course_progress === 'number') {
                updateHeaderProgress(data.course_progress);
            }

            if (isStandalone && data.attempt?.result_url) {
                window.location.replace(data.attempt.result_url);
            }
        } catch (error) {
            nextBtn.disabled = false;
            nextBtn.textContent = 'Nộp bài';
            prevBtn.disabled = currentIndex === 0;
            showToast(getUserFacingErrorMessage(error, 'Không thể kết nối tới máy chủ. Vui lòng thử lại.'), 'error');
        }
    };

    const renderQuizResult = (data) => {
        const secWarning = root.querySelector('[data-quiz-security-warning]');
        if (secWarning) {
            secWarning.textContent = '';
            secWarning.classList.add('hidden');
        }
        const attempt = data.attempt;
        const passed = attempt.passed;
        const hasExcludedQuestion = data.graded?.questions?.some((question) => question.is_excluded) ?? false;
        result.innerHTML = `
            <div class="rounded border ${passed ? 'border-emerald-400/30 bg-emerald-500/10' : 'border-rose-400/30 bg-rose-500/10'} p-6">
                <p class="text-sm font-semibold uppercase tracking-wide ${passed ? 'text-emerald-300' : 'text-rose-300'}">${passed ? 'Đạt' : 'Chưa đạt'}</p>
                <h3 class="mt-2 text-2xl font-bold">${attempt.percent}%</h3>
                <p class="mt-2 text-sm text-white/80">${attempt.correct_count}/${attempt.total_questions} câu đúng · Điểm ${attempt.score}/${attempt.total_score} · Yêu cầu ${attempt.pass_score}%</p>
                ${hasExcludedQuestion ? '<p role="status" class="mt-4 rounded border border-amber-300/40 bg-amber-500/10 p-3 text-sm font-semibold text-amber-100">Câu hỏi đã bị hủy — không tính điểm. Điểm hiện tại được tính trên các câu hỏi hợp lệ.</p>' : ''}
                <div class="mt-5 flex flex-wrap gap-3">
                    ${attempt.review_url
                        ? `<a href="${attempt.review_url}" class="rounded border border-white/20 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10 inline-flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            Xem lại bài làm
                           </a>`
                        : ''}
                    ${data.remaining_attempts === null || data.remaining_attempts > 0
                        ? '<button type="button" data-quiz-retry class="rounded border border-white/20 px-4 py-2 text-sm font-semibold hover:bg-white/10">Làm lại</button>'
                        : ''}
                    ${data.next_lesson_url
                        ? `<a href="${data.next_lesson_url}" class="rounded bg-[#0056D2] px-4 py-2 text-sm font-bold text-white hover:bg-[#0046B8]">Bài tiếp theo</a>`
                        : ''}
                </div>
            </div>
        `;

        result.querySelector('[data-quiz-retry]')?.addEventListener('click', () => window.location.reload());
    };

    // Khởi tạo listeners
    setupProctoring();

    startButton?.addEventListener('click', startQuiz);
    terminatedRetryBtn?.addEventListener('click', () => window.location.reload());

    if (quiz.attempt_id) {
        if (isStandalone) {
            activateQuiz();
        } else {
            startQuiz();
        }
    }

    prevBtn?.addEventListener('click', () => {
        if (currentIndex > 0) {
            currentIndex -= 1;
            renderQuestion();
        }
    });

    nextBtn?.addEventListener('click', () => {
        if (currentIndex < quiz.questions.length - 1) {
            currentIndex += 1;
            renderQuestion();
        } else {
            submitQuiz(false);
        }
    });
}

function formatTime(totalSeconds) {
    const safeSeconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const hours = Math.floor(safeSeconds / 3600);
    const minutes = Math.floor((safeSeconds % 3600) / 60);
    const seconds = safeSeconds % 60;

    if (hours > 0) {
        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderSafeMarkdown(value) {
    const raw = typeof value === 'string' ? value.replace(/\*\*/g, '') : (value || '');
    const text = escapeHtml(raw).replace(/\r\n/g, '\n');
    const codeBlocks = [];
    const withPlaceholders = text.replace(/```([\s\S]*?)```/g, (_, code) => {
        const index = codeBlocks.length;
        codeBlocks.push(`<pre class="my-2 overflow-x-auto rounded-lg bg-slate-950 p-3 text-xs leading-5 text-slate-50"><code>${code.trim()}</code></pre>`);

        return `\n@@CODE_BLOCK_${index}@@\n`;
    });

    const inlineCode = (line) => line.replace(/`([^`]+)`/g, '<code class="rounded bg-slate-100 px-1 py-0.5 text-[0.85em] font-semibold text-slate-900">$1</code>');
    const lines = withPlaceholders.split('\n');
    const html = [];
    let listType = null;

    const closeList = () => {
        if (!listType) return;
        html.push(listType === 'ol' ? '</ol>' : '</ul>');
        listType = null;
    };

    lines.forEach((rawLine) => {
        const line = rawLine.trim();

        if (!line) {
            closeList();
            return;
        }

        const codeMatch = line.match(/^@@CODE_BLOCK_(\d+)@@$/);
        if (codeMatch) {
            closeList();
            html.push(codeBlocks[Number(codeMatch[1])] || '');
            return;
        }

        const heading = line.match(/^(#{1,3})\s+(.+)$/);
        if (heading) {
            closeList();
            html.push(`<h3 class="mt-3 text-sm font-bold text-slate-950">${inlineCode(heading[2])}</h3>`);
            return;
        }

        const unordered = line.match(/^[-*]\s+(.+)$/);
        if (unordered) {
            if (listType !== 'ul') {
                closeList();
                html.push('<ul class="my-2 list-disc space-y-1 pl-5">');
                listType = 'ul';
            }
            html.push(`<li>${inlineCode(unordered[1])}</li>`);
            return;
        }

        const ordered = line.match(/^\d+[.)]\s+(.+)$/);
        if (ordered) {
            if (listType !== 'ol') {
                closeList();
                html.push('<ol class="my-2 list-decimal space-y-1 pl-5">');
                listType = 'ol';
            }
            html.push(`<li>${inlineCode(ordered[1])}</li>`);
            return;
        }

        closeList();
        html.push(`<p class="my-2">${inlineCode(line)}</p>`);
    });

    closeList();

    return html.join('');
}

function initCertificateDropdown() {
    const dropdown = document.querySelector('[data-certificate-dropdown]');
    if (!dropdown) return;

    const trigger = dropdown.querySelector('[data-cert-dropdown-trigger]');
    const panel = dropdown.querySelector('[data-cert-dropdown-panel]');
    if (!trigger || !panel) return;

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        const isHidden = panel.classList.contains('hidden');
        if (isHidden) {
            panel.classList.remove('hidden');
        } else {
            panel.classList.add('hidden');
        }
    });

    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target)) {
            panel.classList.add('hidden');
        }
    });
}

function requestedStartTimeFromUrl() {
    const raw = new URLSearchParams(window.location.search).get('t');
    if (raw === null || raw === '') return null;

    const value = Number(raw);
    if (!Number.isFinite(value) || value < 0) return null;

    return Math.floor(value);
}

function clampVideoTime(seconds, duration = 0) {
    const safe = Math.max(0, Math.floor(Number(seconds) || 0));
    const max = Math.max(0, Math.floor(Number(duration) || 0));

    return max > 0 ? Math.min(safe, max) : safe;
}

function parseJsonScript(root, selector) {
    const script = root.querySelector(selector);
    if (!script?.textContent) return [];

    try {
        return JSON.parse(script.textContent);
    } catch {
        return [];
    }
}

async function parseJsonResponse(response) {
    const raw = await response.text();
    if (!raw) return {};

    try {
        return JSON.parse(raw);
    } catch {
        return { success: false, message: 'Máy chủ trả về phản hồi không hợp lệ.' };
    }
}

function validationMessage(data, fallback = 'Dữ liệu ghi chú không hợp lệ.') {
    const errors = data?.errors || {};
    const firstError = Object.values(errors)[0];

    if (Array.isArray(firstError) && firstError.length) {
        return firstError[0];
    }

    return data?.message || fallback;
}

function initLessonNotes() {
    document.querySelectorAll('[data-lesson-notes]').forEach((root) => {
        if (root.dataset.canUseNotes !== '1') return;

        const isVideo = root.dataset.lessonType === 'video';
        const video = document.querySelector('video');
        const videoStage = document.querySelector('.learning-video-stage');
        const durationHint = Number(root.dataset.videoDuration || 0);
        const form = root.querySelector('[data-note-create-form]');
        const textarea = root.querySelector('[data-note-content]');
        const timestampInput = root.querySelector('[data-note-timestamp]');
        const timestampLabel = root.querySelector('[data-note-timestamp-label]');
        const submitButton = root.querySelector('[data-note-submit]');
        const statusEl = root.querySelector('[data-note-form-status]');
        const errorEl = root.querySelector('[data-note-form-error]');
        const charCount = root.querySelector('[data-note-char-count]');
        const list = root.querySelector('[data-note-list]');
        const empty = root.querySelector('[data-note-empty]');
        const count = root.querySelector('[data-note-count]');
        const storeUrl = root.dataset.storeUrl;
        let notes = parseJsonScript(root, '[data-lesson-notes-json]');
        let createInFlight = false;

        const updateTimestampLabel = () => {
            if (timestampLabel && timestampInput) {
                timestampLabel.textContent = formatTime(timestampInput.value || 0);
            }
        };

        const syncTimestampFromVideo = () => {
            if (!isVideo || !video) return;
            const duration = Number.isFinite(video.duration) && video.duration > 0 ? video.duration : durationHint;
            const currentSec = clampVideoTime(video.currentTime || 0, duration);
            if (timestampInput) {
                timestampInput.value = currentSec;
            }
            if (timestampLabel) {
                timestampLabel.textContent = formatTime(currentSec);
            }
        };

        if (isVideo && video) {
            video.addEventListener('pause', syncTimestampFromVideo);
            video.addEventListener('seeked', syncTimestampFromVideo);
            video.addEventListener('loadedmetadata', syncTimestampFromVideo, { once: true });
            syncTimestampFromVideo();
        }

        const seekVideoTo = async (seconds, shouldPlay = true) => {
            if (!video) return;

            const seek = () => {
                const duration = Number.isFinite(video.duration) && video.duration > 0 ? video.duration : durationHint;
                video.currentTime = clampVideoTime(seconds, duration);
                if (shouldPlay) {
                    video.play().catch(() => {});
                }
                videoStage?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            if (video.readyState >= 1) {
                seek();
            } else {
                video.addEventListener('loadedmetadata', seek, { once: true });
            }
        };

        const applyRequestedTimestamp = () => {
            const requested = requestedStartTimeFromUrl();
            if (requested !== null && isVideo) {
                seekVideoTo(requested, false);
            }
        };

        const updateCharCount = () => {
            if (charCount && textarea) {
                charCount.textContent = `${textarea.value.length}/2000`;
            }
        };

        const setError = (message = '') => {
            if (!errorEl) return;
            errorEl.textContent = message;
            errorEl.classList.toggle('hidden', !message);
            if (message) {
                textarea?.classList.add('border-rose-500', 'focus:ring-rose-500');
                textarea?.classList.remove('border-[#d1d7dc]', 'focus:ring-[#0056D2]');
            } else {
                textarea?.classList.remove('border-rose-500', 'focus:ring-rose-500');
                textarea?.classList.add('border-[#d1d7dc]', 'focus:ring-[#0056D2]');
            }
        };

        const sortNotes = () => {
            if (isVideo) {
                notes.sort((a, b) => Number(a.timestamp_seconds ?? 0) - Number(b.timestamp_seconds ?? 0) || Number(a.id) - Number(b.id));
            } else {
                notes.sort((a, b) => Number(b.id) - Number(a.id));
            }
        };

        const renderNote = (note) => {
            const item = document.createElement('article');
            item.className = 'rounded border border-[#d1d7dc] bg-white p-4 text-sm';
            item.dataset.noteId = note.id;

            const meta = document.createElement('div');
            meta.className = 'mb-2 flex flex-wrap items-center justify-between gap-2 text-xs font-semibold text-[#6a6f73]';

            const leftMeta = document.createElement('div');
            leftMeta.className = 'flex flex-wrap items-center gap-2';
            if (note.timestamp_seconds !== null && note.timestamp_seconds !== undefined) {
                const timeButton = document.createElement('button');
                timeButton.type = 'button';
                timeButton.className = 'rounded bg-[#eef5ff] px-2 py-1 font-bold text-[#0056D2] hover:bg-[#dbeafe]';
                timeButton.textContent = note.timestamp_label || formatTime(note.timestamp_seconds);
                timeButton.addEventListener('click', () => seekVideoTo(note.timestamp_seconds, true));
                leftMeta.appendChild(timeButton);
            }
            const updated = document.createElement('span');
            updated.textContent = note.updated_at ? `Cập nhật ${note.updated_at}` : '';
            leftMeta.appendChild(updated);

            const actions = document.createElement('div');
            actions.className = 'flex items-center gap-2';
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'font-bold text-[#0056D2] hover:underline';
            editButton.textContent = 'Sửa';
            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'font-bold text-red-600 hover:underline';
            deleteButton.textContent = 'Xóa';
            actions.append(editButton, deleteButton);

            meta.append(leftMeta, actions);

            const content = document.createElement('p');
            content.className = 'whitespace-pre-line leading-6 text-[#1c1d1f]';
            content.textContent = note.content;

            item.append(meta, content);

            editButton.addEventListener('click', () => openInlineEditor(item, note, content));
            deleteButton.addEventListener('click', () => deleteNote(note));

            return item;
        };

        const render = () => {
            sortNotes();
            list.innerHTML = '';
            notes.forEach((note) => list.appendChild(renderNote(note)));
            empty.hidden = notes.length > 0;
            count.textContent = `${notes.length} ghi chú`;
        };

        const openInlineEditor = (item, note, contentEl) => {
            if (item.querySelector('[data-note-edit-form]')) return;

            contentEl.hidden = true;
            const editForm = document.createElement('form');
            editForm.className = 'mt-3 space-y-3';
            editForm.dataset.noteEditForm = '1';

            const editTextarea = document.createElement('textarea');
            editTextarea.name = 'content';
            editTextarea.maxLength = 2000;
            editTextarea.rows = 4;
            editTextarea.className = 'w-full rounded border border-[#d1d7dc] px-3 py-2 text-sm leading-6 outline-none focus:ring-2 focus:ring-[#0056D2]';
            editTextarea.value = note.content;
            editTextarea.placeholder = 'Nhập ghi chú của bạn...';
            editTextarea.addEventListener('input', () => {
                if (editTextarea.value.trim()) {
                    editStatus.textContent = '';
                    editTextarea.classList.remove('border-rose-500', 'focus:ring-rose-500');
                }
            });
            editForm.appendChild(editTextarea);

            let editTimestamp = null;
            let editTimeLabel = null;
            if (isVideo) {
                const row = document.createElement('label');
                row.className = 'flex flex-wrap items-center gap-2 text-sm font-semibold text-[#6a6f73]';
                row.textContent = 'Mốc giây';
                editTimestamp = document.createElement('input');
                editTimestamp.type = 'number';
                editTimestamp.min = '0';
                if (durationHint > 0) editTimestamp.max = String(durationHint);
                editTimestamp.name = 'timestamp_seconds';
                editTimestamp.value = note.timestamp_seconds ?? 0;
                editTimestamp.className = 'h-9 w-24 rounded border border-[#d1d7dc] px-2 text-sm text-[#1c1d1f] outline-none focus:ring-2 focus:ring-[#0056D2]';
                editTimeLabel = document.createElement('span');
                editTimeLabel.textContent = formatTime(editTimestamp.value);
                editTimestamp.addEventListener('input', () => {
                    editTimeLabel.textContent = formatTime(editTimestamp.value);
                });
                row.append(editTimestamp, editTimeLabel);
                editForm.appendChild(row);
            }

            const footer = document.createElement('div');
            footer.className = 'flex flex-wrap items-center justify-between gap-3';
            const editStatus = document.createElement('p');
            editStatus.className = 'text-xs text-[#6a6f73]';
            const buttons = document.createElement('div');
            buttons.className = 'flex gap-2';
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'rounded border border-[#d1d7dc] px-3 py-2 text-sm font-semibold hover:bg-[#f7f9fa]';
            cancel.textContent = 'Hủy';
            const save = document.createElement('button');
            save.type = 'submit';
            save.className = 'rounded bg-[#1c1d1f] px-3 py-2 text-sm font-bold text-white hover:bg-black disabled:opacity-60';
            save.textContent = 'Lưu';
            buttons.append(cancel, save);
            footer.append(editStatus, buttons);
            editForm.appendChild(footer);
            item.appendChild(editForm);

            cancel.addEventListener('click', () => {
                editForm.remove();
                contentEl.hidden = false;
            });

            editForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const contentVal = editTextarea.value.trim();
                if (!contentVal) {
                    editStatus.textContent = 'Vui lòng nhập nội dung ghi chú.';
                    editStatus.className = 'text-xs font-semibold text-rose-600';
                    editTextarea.classList.add('border-rose-500', 'focus:ring-rose-500');
                    editTextarea.focus();
                    return;
                }

                if (save.disabled) return;
                save.disabled = true;
                editStatus.className = 'text-xs text-[#6a6f73]';
                editStatus.textContent = 'Đang lưu...';

                try {
                    const payload = { content: contentVal };
                    if (isVideo) payload.timestamp_seconds = editTimestamp?.value === '' ? null : Number(editTimestamp?.value || 0);
                    const updateUrl = note.update_url || `/lesson-notes/${note.id}`;
                    const response = await fetch(updateUrl, {
                        method: 'PATCH',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(payload),
                    });
                    const data = await parseJsonResponse(response);
                    if (!response.ok || !data.success) {
                        throw createUserFacingError(validationMessage(data, 'Không thể cập nhật ghi chú.'));
                    }
                    notes = notes.map((itemNote) => Number(itemNote.id) === Number(note.id) ? data.note : itemNote);
                    showToast('Đã cập nhật ghi chú.');
                    render();
                } catch (error) {
                    editStatus.className = 'text-xs font-semibold text-rose-600';
                    editStatus.textContent = getUserFacingErrorMessage(error, 'Không thể kết nối tới máy chủ. Vui lòng thử lại.');
                    save.disabled = false;
                }
            });
        };

        const deleteNote = async (note) => {
            if (!window.confirm('Xóa ghi chú này?')) return;

            try {
                const deleteUrl = note.delete_url || `/lesson-notes/${note.id}`;
                const response = await fetch(deleteUrl, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await parseJsonResponse(response);
                if (!response.ok || !data.success) {
                    throw createUserFacingError(validationMessage(data, 'Không thể xóa ghi chú.'));
                }
                notes = notes.filter((itemNote) => Number(itemNote.id) !== Number(note.id));
                showToast('Đã xóa ghi chú.');
                render();
            } catch (error) {
                showToast(getUserFacingErrorMessage(error, 'Không thể kết nối tới máy chủ. Vui lòng thử lại.'), 'error');
            }
        };

        textarea?.addEventListener('focus', () => {
            if (isVideo && video && !video.paused) {
                video.pause();
            }
            syncTimestampFromVideo();
        });
        textarea?.addEventListener('input', () => {
            if (textarea.value.trim()) {
                setError('');
            }
            updateCharCount();
        });

        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (createInFlight || !storeUrl) return;

            const content = textarea.value.trim();
            if (!content) {
                setError('Vui lòng nhập nội dung ghi chú.');
                textarea.focus();
                return;
            }

            let finalTimestamp = null;
            if (isVideo) {
                if (video) {
                    const duration = Number.isFinite(video.duration) && video.duration > 0 ? video.duration : durationHint;
                    finalTimestamp = clampVideoTime(video.currentTime || 0, duration);
                } else {
                    finalTimestamp = timestampInput?.value === '' ? null : Number(timestampInput?.value || 0);
                }
                if (timestampInput) timestampInput.value = finalTimestamp;
                if (timestampLabel) timestampLabel.textContent = formatTime(finalTimestamp);
            }

            createInFlight = true;
            submitButton.disabled = true;
            statusEl.textContent = 'Đang lưu...';
            setError('');

            try {
                const payload = { content };
                if (isVideo) {
                    payload.timestamp_seconds = finalTimestamp;
                }

                const response = await fetch(storeUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                const data = await parseJsonResponse(response);
                if (!response.ok || !data.success) {
                    throw createUserFacingError(validationMessage(data, 'Không thể lưu ghi chú.'));
                }

                notes.push(data.note);
                textarea.value = '';
                updateCharCount();
                statusEl.textContent = 'Đã lưu thành công.';
                showToast('Đã lưu ghi chú.');
                render();
                syncTimestampFromVideo();
            } catch (error) {
                statusEl.textContent = '';
                const message = getUserFacingErrorMessage(error, 'Không thể kết nối tới máy chủ. Vui lòng thử lại.');
                setError(message);
                showToast(message, 'error');
            } finally {
                createInFlight = false;
                submitButton.disabled = false;
            }
        });

        applyRequestedTimestamp();
        updateCharCount();
        updateTimestampLabel();
        render();
    });
}

function initStudyNotesPage() {
    document.querySelectorAll('[data-study-note-card]').forEach((card) => {
        const content = card.querySelector('[data-study-note-content]');
        const form = card.querySelector('[data-study-note-edit-form]');
        const editButton = card.querySelector('[data-study-note-edit]');
        const cancelButton = card.querySelector('[data-study-note-cancel]');
        const deleteButton = card.querySelector('[data-study-note-delete]');
        const saveButton = card.querySelector('[data-study-note-save]');
        const textarea = card.querySelector('[data-study-note-edit-content]');
        const timestamp = card.querySelector('[data-study-note-edit-timestamp]');
        const timeLabel = card.querySelector('[data-study-note-edit-time-label]');
        const status = card.querySelector('[data-study-note-status]');
        const displayTime = card.querySelector('[data-study-note-time]');
        const updateUrl = card.dataset.updateUrl;
        const deleteUrl = card.dataset.deleteUrl;
        const isVideo = card.dataset.isVideo === '1';
        let inFlight = false;

        timestamp?.addEventListener('input', () => {
            if (timeLabel) timeLabel.textContent = formatTime(timestamp.value || 0);
        });

        editButton?.addEventListener('click', () => {
            form?.classList.remove('hidden');
            content.hidden = true;
            textarea?.focus();
        });

        cancelButton?.addEventListener('click', () => {
            form?.classList.add('hidden');
            content.hidden = false;
        });

        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (inFlight) return;

            inFlight = true;
            saveButton.disabled = true;
            status.textContent = 'Đang lưu...';

            try {
                const payload = { content: textarea.value.trim() };
                if (isVideo) payload.timestamp_seconds = timestamp?.value === '' ? null : Number(timestamp?.value || 0);

                const response = await fetch(updateUrl, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                const data = await parseJsonResponse(response);
                if (!response.ok || !data.success) {
                    throw createUserFacingError(validationMessage(data, 'Không thể cập nhật ghi chú.'));
                }

                content.textContent = data.note.content;
                if (displayTime && data.note.timestamp_label) displayTime.textContent = data.note.timestamp_label;
                form.classList.add('hidden');
                content.hidden = false;
                status.textContent = '';
                showToast('Đã cập nhật ghi chú.');
            } catch (error) {
                status.textContent = getUserFacingErrorMessage(error, 'Không thể kết nối tới máy chủ. Vui lòng thử lại.');
            } finally {
                inFlight = false;
                saveButton.disabled = false;
            }
        });

        deleteButton?.addEventListener('click', async () => {
            if (inFlight || !window.confirm('Xóa ghi chú này?')) return;

            inFlight = true;
            deleteButton.disabled = true;

            try {
                const response = await fetch(deleteUrl, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await parseJsonResponse(response);
                if (!response.ok || !data.success) {
                    throw createUserFacingError(validationMessage(data, 'Không thể xóa ghi chú.'));
                }
                card.remove();
                showToast('Đã xóa ghi chú.');
            } catch (error) {
                deleteButton.disabled = false;
                showToast(getUserFacingErrorMessage(error, 'Không thể kết nối tới máy chủ. Vui lòng thử lại.'), 'error');
            } finally {
                inFlight = false;
            }
        });
    });
}

function initLessonAi() {
    const root = document.querySelector('[data-lesson-ai]');
    if (!root || root.getAttribute('data-can-use-ai') !== '1') return;

    const summaryUrl = root.dataset.aiSummaryUrl;
    const explainUrl = root.dataset.aiExplainUrl;
    const summaryBox = root.querySelector('[data-ai-summary-box]');
    const keyPointsEl = root.querySelector('[data-ai-key-points]');
    const takeawaysEl = root.querySelector('[data-ai-takeaways]');
    const summaryStatus = root.querySelector('[data-ai-summary-status]');
    const summaryError = root.querySelector('[data-ai-summary-error]');
    const generateBtn = root.querySelector('[data-ai-generate-summary]');
    const askForm = root.querySelector('[data-ai-ask-form]');
    const askInput = root.querySelector('[data-ai-question-input]');
    const askSubmit = root.querySelector('[data-ai-ask-submit]');
    const askStatus = root.querySelector('[data-ai-ask-status]');
    const askError = root.querySelector('[data-ai-ask-error]');
    const chatLog = root.querySelector('[data-ai-chat-log]');

    let summaryInFlight = false;
    let askInFlight = false;

    const aiErrorMessage = (data, fallback) => {
        const codeMessages = {
            missing_api_key: 'Tính năng AI hiện chưa khả dụng. Vui lòng thử lại sau.',
            invalid_api_key: 'Tính năng AI hiện chưa khả dụng. Vui lòng thử lại sau.',
            invalid_model: 'Tính năng AI hiện chưa khả dụng. Vui lòng thử lại sau.',
            quota_exceeded: 'Tính năng AI hiện chưa khả dụng. Vui lòng thử lại sau.',
            timeout: 'Kết nối AI bị quá thời gian chờ. Vui lòng thử lại.',
            ssl_error: 'Tính năng AI hiện chưa khả dụng. Vui lòng thử lại sau.',
            connection_error: 'Tính năng AI hiện chưa khả dụng. Vui lòng thử lại sau.',
            no_source: 'Bài học chưa có đủ nội dung văn bản để dùng AI.',
            content_blocked: 'Nội dung này chưa thể được AI xử lý. Vui lòng thử câu hỏi khác.',
            response_truncated: 'Phản hồi AI bị cắt vì quá dài. Hãy hỏi ngắn hơn.',
            empty_response: 'AI không trả về nội dung. Vui lòng thử lại.',
            invalid_response: 'Phản hồi AI không hợp lệ. Vui lòng thử lại.',
            invalid_request: 'Yêu cầu gửi tới AI không hợp lệ.',
            ai_unavailable: 'Tính năng AI hiện chưa khả dụng. Vui lòng thử lại sau.',
            forbidden: 'Bạn không có quyền dùng AI hỗ trợ bài học.',
            lesson_mismatch: 'Bài học không thuộc khóa học này.',
            validation: 'Dữ liệu câu hỏi không hợp lệ.',
            too_many_requests: 'Bạn thao tác quá nhanh. Hãy đợi một lát rồi thử lại.',
        };

        if (data?.code && codeMessages[data.code]) {
            return codeMessages[data.code];
        }

        return fallback;
    };

    const parseJsonSafe = async (response) => {
        const raw = await response.text();
        if (!raw) return {};
        try {
            return JSON.parse(raw);
        } catch (error) {
            return {
                success: false,
                code: 'invalid_response',
                message: response.status === 429
                    ? 'Bạn thao tác quá nhanh. Hãy đợi một lát rồi thử lại.'
                    : 'Máy chủ trả về phản hồi không hợp lệ.',
            };
        }
    };

    const renderList = (el, items) => {
        if (!el) return;
        el.innerHTML = '';
        (items || []).forEach((item) => {
            const li = document.createElement('li');
            li.textContent = item;
            el.appendChild(li);
        });
    };

    const showSummaryError = (message) => {
        if (!summaryError) return;
        if (!message) {
            summaryError.textContent = '';
            summaryError.classList.add('hidden');
            return;
        }
        summaryError.textContent = message;
        summaryError.classList.remove('hidden');
    };

    const cleanAiText = (str) => {
        if (typeof str !== 'string') return str || '';
        return str.replace(/\*\*/g, '');
    };

    const renderSummary = (data) => {
        if (summaryBox) {
            summaryBox.textContent = cleanAiText(data.summary || data.message || 'Chưa có bản tóm tắt.');
        }
        renderList(keyPointsEl, (data.key_points || []).map(cleanAiText));
        renderList(takeawaysEl, (data.takeaways || []).map(cleanAiText));
        if (summaryStatus) {
            summaryStatus.textContent = data.summary
                ? (data.cached ? 'Đang dùng bản tóm tắt đã lưu.' : 'Đã tạo tóm tắt mới.')
                : '';
        }
    };

    const appendChat = (role, text) => {
        if (!chatLog) return;
        const item = document.createElement('div');
        item.className = role === 'user'
            ? 'rounded bg-[#eef5ff] px-3 py-2 text-sm text-[#1c1d1f]'
            : 'rounded bg-[#f7f9fa] px-3 py-2 text-sm text-[#1c1d1f]';
        const displayText = role === 'user' ? text : cleanAiText(text);
        item.innerHTML = `<strong class="block text-xs uppercase tracking-wide text-[#6a6f73]">${role === 'user' ? 'Bạn' : 'AI'}</strong><span class="mt-1 block whitespace-pre-line">${escapeHtml(displayText)}</span>`;
        chatLog.appendChild(item);
        chatLog.scrollTop = chatLog.scrollHeight;
    };

    const fetchSummary = async (generate = false) => {
        if (!summaryUrl) return { response: null, data: { success: false, message: 'Thiếu URL tóm tắt.' } };
        const url = generate ? `${summaryUrl}${summaryUrl.includes('?') ? '&' : '?'}generate=1` : summaryUrl;
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await parseJsonSafe(response);
        if (response.status === 429 && !data.code) {
            data.code = 'too_many_requests';
            data.message = data.message || aiErrorMessage({ code: 'too_many_requests' }, 'Thao tác quá nhanh.');
        }
        return { response, data };
    };

    const loadSummary = async () => {
        try {
            const { response, data } = await fetchSummary(false);
            if (!response?.ok || !data.success) {
                if (summaryBox) summaryBox.textContent = aiErrorMessage(data, 'Không tải được tóm tắt.');
                return;
            }
            renderSummary(data);
        } catch (error) {
            if (summaryBox) summaryBox.textContent = 'Không tải được tóm tắt do lỗi mạng. Vui lòng thử lại.';
        }
    };

    generateBtn?.addEventListener('click', async () => {
        if (summaryInFlight) return;
        summaryInFlight = true;
        generateBtn.disabled = true;
        showSummaryError('');
        if (summaryStatus) summaryStatus.textContent = 'Đang tạo tóm tắt...';

        try {
            const { response, data } = await fetchSummary(true);
            if (!response?.ok || !data.success) {
                const message = aiErrorMessage(data, 'Không tạo được tóm tắt.');
                showSummaryError(message);
                if (summaryStatus) summaryStatus.textContent = '';
                showToast(message, 'error');
                return;
            }
            renderSummary(data);
            showSummaryError('');
            showToast(data.cached ? 'Đã tải bản tóm tắt đã lưu.' : 'Đã tạo tóm tắt bài học.');
        } catch (error) {
            showSummaryError('Không tạo được tóm tắt do lỗi mạng hoặc máy chủ.');
            if (summaryStatus) summaryStatus.textContent = '';
            showToast('Không tạo được tóm tắt do lỗi mạng.', 'error');
        } finally {
            summaryInFlight = false;
            generateBtn.disabled = false;
        }
    });

    askInput?.addEventListener('input', () => {
        if (askError) {
            askError.textContent = '';
            askError.classList.add('hidden');
        }
        if (askStatus && askStatus.textContent === 'Vui lòng nhập câu hỏi.') {
            askStatus.textContent = '';
        }
    });

    askForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!explainUrl || askInFlight || !askInput) return;

        const question = askInput.value.trim();
        if (!question) {
            if (askError) {
                askError.textContent = 'Vui lòng nhập câu hỏi.';
                askError.classList.remove('hidden');
            }
            if (askStatus) askStatus.textContent = '';
            askInput.focus();
            return;
        }
        if (question.length > 1000) {
            if (askError) {
                askError.textContent = 'Câu hỏi tối đa 1000 ký tự.';
                askError.classList.remove('hidden');
            }
            if (askStatus) askStatus.textContent = '';
            return;
        }

        if (askError) {
            askError.textContent = '';
            askError.classList.add('hidden');
        }

        askInFlight = true;
        if (askSubmit) askSubmit.disabled = true;
        if (askStatus) askStatus.textContent = 'Đang giải thích...';
        appendChat('user', question);

        try {
            const response = await fetch(explainUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ question }),
            });
            const data = await parseJsonSafe(response);
            if (response.status === 429 && !data.code) {
                data.code = 'too_many_requests';
            }
            if (!response.ok || !data.success) {
                const message = aiErrorMessage(data, 'Không nhận được giải thích từ AI.');
                appendChat('assistant', message);
                if (askStatus) askStatus.textContent = message;
                showToast(message, 'error');
                return;
            }
            appendChat('assistant', data.answer);
            askInput.value = '';
            if (askStatus) askStatus.textContent = '';
        } catch (error) {
            appendChat('assistant', 'Không kết nối được AI. Vui lòng thử lại.');
            if (askStatus) askStatus.textContent = 'Lỗi kết nối mạng.';
            showToast('Không hỏi được AI do lỗi mạng.', 'error');
        } finally {
            askInFlight = false;
            if (askSubmit) askSubmit.disabled = false;
        }
    });

    loadSummary();
}

function initAiStudyAssistant() {
    const root = document.querySelector('[data-ai-study-assistant]');
    if (!root) return;

    const chatUrl = root.dataset.aiChatUrl;
    const historyUrl = root.dataset.aiHistoryUrl || chatUrl;
    const openButton = root.querySelector('[data-ai-assistant-open]');
    const panel = root.querySelector('[data-ai-assistant-panel]');
    const minimizeButton = root.querySelector('[data-ai-assistant-minimize]');
    const closeButton = root.querySelector('[data-ai-assistant-close]');
    const form = root.querySelector('[data-ai-assistant-form]');
    const input = root.querySelector('[data-ai-assistant-input]');
    const submitButton = root.querySelector('[data-ai-assistant-submit]');
    const status = root.querySelector('[data-ai-assistant-status]');
    const messages = root.querySelector('[data-ai-assistant-messages]');
    const quickActions = root.querySelector('[data-ai-assistant-quick-actions]');
    const quickActionButtons = root.querySelectorAll('[data-ai-assistant-quick-action]');
    const count = root.querySelector('[data-ai-assistant-count]');

    if (!chatUrl || !openButton || !panel || !form || !input || !messages) return;

    let inFlight = false;
    let historyLoaded = false;
    let conversationId = null;
    let currentAbortController = null;

    const parseJsonSafe = async (response) => {
        const raw = await response.text();
        if (!raw) return {};
        try {
            return JSON.parse(raw);
        } catch (error) {
            return {
                success: false,
                code: response.status === 429 ? 'too_many_requests' : 'invalid_response',
                message: response.status === 429
                    ? 'Bạn đang gửi câu hỏi quá nhanh. Vui lòng thử lại sau.'
                    : 'Máy chủ trả về phản hồi không hợp lệ.',
            };
        }
    };

    const aiErrorMessage = (response, data) => {
        if (response?.status === 401 || response?.status === 419) {
            return 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.';
        }

        if (response?.status === 429) {
            return 'Bạn đang gửi câu hỏi quá nhanh. Vui lòng thử lại sau.';
        }

        const codeMessages = {
            timeout: 'AI đang phản hồi chậm. Vui lòng thử lại.',
            ai_unavailable: 'Trợ lý AI hiện chưa khả dụng.',
            missing_api_key: 'Trợ lý AI hiện chưa khả dụng.',
            invalid_api_key: 'Trợ lý AI hiện chưa khả dụng.',
            invalid_model: 'Trợ lý AI hiện chưa khả dụng.',
            ssl_error: 'Trợ lý AI hiện chưa khả dụng.',
            connection_error: 'Trợ lý AI hiện chưa khả dụng.',
            quota_exceeded: 'Bạn đang gửi câu hỏi quá nhanh. Vui lòng thử lại sau.',
            forbidden: 'Bạn không có quyền dùng AI hỗ trợ bài học.',
            conversation_mismatch: 'Cuộc hội thoại không thuộc bài học hiện tại.',
            lesson_mismatch: 'Bài học không thuộc khóa học này.',
            validation: 'Câu hỏi chưa hợp lệ.',
            content_blocked: 'Nội dung này chưa thể được AI xử lý. Vui lòng thử câu hỏi khác.',
            response_truncated: 'Phản hồi AI bị cắt vì quá dài. Hãy hỏi ngắn hơn.',
            empty_response: 'AI không trả về nội dung. Vui lòng thử lại.',
            invalid_response: 'Phản hồi AI không hợp lệ. Vui lòng thử lại.',
        };

        return codeMessages[data?.code] || 'Trợ lý AI hiện chưa khả dụng.';
    };

    const setOpen = (open) => {
        panel.classList.toggle('hidden', !open);
        openButton.classList.toggle('hidden', open);
        if (open) {
            loadHistory();
            window.setTimeout(() => input.focus(), 80);
        }
    };

    const setBusy = (busy) => {
        inFlight = busy;
        input.disabled = busy;
        if (submitButton) submitButton.disabled = busy;
        quickActionButtons.forEach((button) => {
            button.disabled = busy;
        });
    };

    const syncCount = () => {
        if (count) count.textContent = `${input.value.length}/2000`;
    };

    const scrollToBottom = () => {
        messages.scrollTop = messages.scrollHeight;
    };

    const syncQuickActions = () => {
        if (!quickActions) return;
        quickActions.classList.toggle('hidden', messages.querySelector('[data-ai-message]') !== null);
    };

    const appendMessage = (role, text, loading = false, retryPrompt = null) => {
        const row = document.createElement('div');
        row.dataset.aiMessage = role;
        row.className = role === 'user' ? 'flex justify-end' : 'flex justify-start';

        const bubble = document.createElement('div');
        bubble.className = role === 'user'
            ? 'max-w-[86%] rounded-2xl rounded-br-md bg-[#0056D2] px-3.5 py-2 text-sm leading-6 text-white'
            : 'max-w-[86%] rounded-2xl rounded-bl-md bg-slate-100 px-3.5 py-2 text-sm leading-6 text-slate-800 dark:bg-slate-800 dark:text-slate-200';

        if (loading) {
            bubble.innerHTML = '<span class="inline-flex items-center gap-2"><span>AI đang suy nghĩ</span><span class="animate-pulse">...</span><button type="button" data-ai-cancel class="ml-2 font-bold text-xs text-rose-600 hover:text-rose-700 underline">Hủy</button></span>';
            const cancelBtn = bubble.querySelector('[data-ai-cancel]');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => {
                    if (currentAbortController) {
                        currentAbortController.abort();
                    }
                });
            }
        } else if (role === 'assistant') {
            bubble.innerHTML = renderSafeMarkdown(text);
            if (retryPrompt) {
                const retryBtn = document.createElement('button');
                retryBtn.type = 'button';
                retryBtn.className = 'mt-2 block rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200';
                retryBtn.textContent = '🔄 Thử lại';
                retryBtn.addEventListener('click', () => sendMessage(retryPrompt));
                bubble.appendChild(retryBtn);
            }
        } else {
            bubble.textContent = text;
        }

        row.appendChild(bubble);
        messages.appendChild(row);
        syncQuickActions();
        scrollToBottom();

        return row;
    };

    const removeMessage = (row) => {
        if (row && row.parentNode) {
            row.parentNode.removeChild(row);
        }
    };

    const loadHistory = async () => {
        if (historyLoaded || inFlight) return;

        historyLoaded = true;
        setBusy(true);
        if (status) status.textContent = 'Đang tải lịch sử chat...';

        try {
            const response = await fetch(historyUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await parseJsonSafe(response);

            if (!response.ok || !data.success) {
                if (status) status.textContent = aiErrorMessage(response, data);
                return;
            }

            conversationId = data.conversation_id || conversationId;
            messages.innerHTML = '';

            (data.messages || []).forEach((message) => {
                appendMessage(message.role === 'assistant' ? 'assistant' : 'user', message.content || '');
            });

            if (status) {
                status.textContent = data.messages?.length
                    ? 'Đã tải lịch sử chat của bài học này.'
                    : 'Sẵn sàng hỗ trợ bạn học bài.';
            }
            syncQuickActions();
        } catch (error) {
            if (status) status.textContent = 'Không tải được lịch sử chat.';
        } finally {
            setBusy(false);
            input.focus();
        }
    };

    const sendMessage = async (rawMessage) => {
        const message = rawMessage.trim();
        if (!message || inFlight) return;

        if (message.length > 2000) {
            if (status) status.textContent = 'Câu hỏi quá dài. Vui lòng rút gọn nội dung.';
            return;
        }

        appendMessage('user', message);
        input.value = '';
        syncCount();
        setBusy(true);
        if (status) status.textContent = 'AI đang suy nghĩ...';
        const loadingRow = appendMessage('assistant', '', true);

        currentAbortController = new AbortController();

        try {
            const body = { message };
            if (conversationId) {
                body.conversation_id = conversationId;
            }

            const response = await fetch(chatUrl, {
                method: 'POST',
                signal: currentAbortController.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });
            const data = await parseJsonSafe(response);
            removeMessage(loadingRow);

            if (!response.ok || !data.success) {
                const messageText = aiErrorMessage(response, data);
                appendMessage('assistant', messageText, false, message);
                if (status) status.textContent = messageText;
                return;
            }

            conversationId = data.conversation_id || conversationId;
            appendMessage('assistant', data.message || data.answer || '');
            if (status) status.textContent = 'Sẵn sàng hỗ trợ bạn học bài.';
        } catch (error) {
            removeMessage(loadingRow);
            if (error.name === 'AbortError') {
                appendMessage('assistant', 'Đã hủy câu hỏi.');
                if (status) status.textContent = 'Đã hủy yêu cầu.';
            } else {
                appendMessage('assistant', 'Trợ lý AI hiện chưa khả dụng. Bạn có thể nhấn Thử lại.', false, message);
                if (status) status.textContent = 'Trợ lý AI hiện chưa khả dụng.';
            }
        } finally {
            currentAbortController = null;
            setBusy(false);
            input.focus();
            scrollToBottom();
        }
    };

    openButton.addEventListener('click', () => setOpen(true));
    minimizeButton?.addEventListener('click', () => setOpen(false));
    closeButton?.addEventListener('click', () => setOpen(false));

    input.addEventListener('input', syncCount);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        sendMessage(input.value);
    });

    quickActionButtons.forEach((button) => {
        button.addEventListener('click', () => {
            sendMessage(button.dataset.prompt || button.textContent || '');
        });
    });

    syncCount();
    syncQuickActions();
}

function initYouTubeProgress() {
    const iframe = document.querySelector('iframe[data-lesson-progress-youtube]');
    if (!iframe || !iframe.dataset.progressUrl) return;
    if (!window.YT) {
        const tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
    }

    const initPlayer = () => {
        let unsaved = 0;
        let previousPosition = null;
        let previousTime = performance.now();
        let lastSave = 0;
        let inFlight = false;
        let interval = null;
        let completed = iframe.dataset.initialCompleted === '1';
        const sample = () => {
            const position = player.getCurrentTime();
            const now = performance.now();
            const delta = previousPosition === null ? 0 : position - previousPosition;
            if (delta > 0 && delta <= 2.5) unsaved += Math.min(delta, (now - previousTime) / 1000);
            previousPosition = position;
            previousTime = now;
        };
        const save = async (force = false) => {
            if (inFlight || (!force && Date.now() - lastSave < 10000)) return;
            inFlight = true;
            lastSave = Date.now();
            const delta = Math.floor(unsaved);
            try {
                const response = await fetch(iframe.dataset.progressUrl, {
                    method: 'POST', credentials: 'same-origin', keepalive: true,
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                    body: JSON.stringify({
                        played_seconds: delta,
                        last_position_seconds: Math.floor(player.getCurrentTime()),
                        client_updated_at: new Date().toISOString(),
                    }),
                });
                if (!response.ok) throw new Error('progress_failed');
                const data = await response.json();
                unsaved = Math.max(0, unsaved - delta);
                updateCurrentLessonProgress(data.lesson_progress, data.lesson_completed);
                if (typeof data.course_progress === 'number') updateHeaderProgress(data.course_progress);
                if (data.lesson_completed && !completed) showToast('Bạn đã hoàn thành bài học!', 'success');
                completed = Boolean(data.lesson_completed);
            } catch {
                showToast('Chưa lưu được tiến độ. Hệ thống sẽ thử lại.', 'error');
            } finally {
                inFlight = false;
            }
        };
        const player = new window.YT.Player(iframe.id, {
            events: {
                onStateChange: (event) => {
                    if (event.data === window.YT.PlayerState.PLAYING) {
                        previousPosition = player.getCurrentTime();
                        previousTime = performance.now();
                        save(true); // Establish a server heartbeat before accruing watch time.
                        if (!interval) interval = setInterval(() => { sample(); save(); }, 500);
                    } else {
                        if (interval) { sample(); clearInterval(interval); interval = null; }
                        save(true);
                    }
                },
            },
        });
        window.addEventListener('pagehide', () => { sample(); save(true); });
    };
    const checkAndInit = () => {
        if (window.YT?.Player) initPlayer();
        else setTimeout(checkAndInit, 100);
    };
    checkAndInit();
}
