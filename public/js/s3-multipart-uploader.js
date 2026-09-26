/**
 * S3 Multipart Uploader for Laravel 12 + AWS S3 Direct Upload
 * Optimized for High-Speed Concurrent Uploads (Supports 50MB, 100MB, 500MB, 1GB - 5GB+)
 * Features: Multi-Thread Parallel Upload (4-5 workers), Dynamic Chunk Sizing, Live MB/s, Accurate ETA, Auto-Retry, Cancel
 */
const S3_UPLOAD_PUBLIC_ERROR = 'Không thể tải video lên lúc này. Vui lòng thử lại.';

function createS3UploadUserError(message) {
    const error = new Error(message);
    error.isUserFacing = true;

    return error;
}

function toS3UploadUserError(error) {
    if (error?.isUserFacing && typeof error.message === 'string' && error.message.trim() !== '') {
        return error;
    }

    return createS3UploadUserError(S3_UPLOAD_PUBLIC_ERROR);
}

class S3MultipartUploader {
    constructor(options = {}) {
        this.courseId = options.courseId;
        this.lessonId = options.lessonId || null;
        this.contentUpdateId = options.contentUpdateId || null;
        this.draftVersionNumber = options.draftVersionNumber || null;
        this.createUrl = options.createUrl;
        this.signPartUrl = options.signPartUrl;
        this.batchSignUrl = options.batchSignUrl;
        this.completeUrl = options.completeUrl;
        this.abortUrl = options.abortUrl;
        this.maxVideoBytes = Number(options.maxVideoBytes) || (5 * 1024 * 1024 * 1024);
        this.mediaType = typeof options.mediaType === 'string' && options.mediaType !== ''
            ? options.mediaType
            : null;
        this.maxDurationSeconds = Number(options.maxDurationSeconds) || 0;
        this.csrfToken = options.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // Số luồng upload song song (4 luồng giúp tận dụng tối đa băng thông quốc tế)
        this.concurrency = options.concurrency || 4;
        this.maxRetries = options.maxRetries || 3;

        this.onInit = options.onInit || (() => {});
        this.onProgress = options.onProgress || (() => {});
        this.onSuccess = options.onSuccess || (() => {});
        this.onError = options.onError || (() => {});
        this.onStatusChange = options.onStatusChange || (() => {});

        this.isCancelled = false;
        this.activeXhrs = [];
        this.uploadId = null;
        this.s3Key = null;
        this.startTime = null;
        this.partProgress = {};
        this.speedSamples = [];
    }

    /**
     * Tự động điều chỉnh kích thước Part tối ưu theo dung lượng file
     */
    getOptimalChunkSize(fileSize) {
        if (fileSize < 50 * 1024 * 1024) {
            return 6 * 1024 * 1024; // 6MB
        } else if (fileSize < 200 * 1024 * 1024) {
            return 8 * 1024 * 1024; // 8MB
        } else if (fileSize < 1024 * 1024 * 1024) {
            return 12 * 1024 * 1024; // 12MB
        } else {
            return 16 * 1024 * 1024; // 16MB
        }
    }

    async upload(file) {
        if (!file) {
            throw createS3UploadUserError('Vui lòng chọn tệp video để tải lên.');
        }
        if (file.size > this.maxVideoBytes) {
            throw createS3UploadUserError(`Dung lượng video tối đa là ${this.formatBytes(this.maxVideoBytes, 0)}.`);
        }

        this.isCancelled = false;
        this.activeXhrs = [];
        this.startTime = Date.now();
        this.partProgress = {};
        this.speedSamples = [];
        this.file = file;
        this.chunkSize = this.getOptimalChunkSize(file.size);

        this.onStatusChange('initializing', 'Đang khởi tạo phiên tải lên S3...');

        // 1. Lấy thời lượng video từ metadata
        let duration = await this.getVideoDuration(file).catch(() => 0);
        if (this.maxDurationSeconds > 0 && duration > this.maxDurationSeconds) {
            throw createS3UploadUserError(
                `Video không được dài quá ${Math.floor(this.maxDurationSeconds / 60)} phút.`
            );
        }

        try {
            // 2. Khởi tạo Multipart Upload trên S3 qua Laravel
            const initPayload = {
                filename: file.name,
                content_type: file.type || 'video/mp4',
                file_size: file.size,
                lesson_id: this.lessonId,
                content_update_id: this.contentUpdateId,
            };
            if (this.mediaType) initPayload.media_type = this.mediaType;
            const initResponse = await fetch(this.createUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify(initPayload),
            });

            if (!initResponse.ok) {
                const errData = await initResponse.json().catch(() => ({}));
                throw createS3UploadUserError(
                    typeof errData.message === 'string' && errData.message.trim() !== ''
                        ? errData.message
                        : S3_UPLOAD_PUBLIC_ERROR
                );
            }

            const initData = await initResponse.json();
            this.uploadId = initData.uploadId;
            this.s3Key = initData.key;
            this.lessonId = initData.lessonId || this.lessonId;
            this.contentUpdateId = initData.contentUpdateId || this.contentUpdateId;
            this.draftVersionNumber = initData.versionNumber || this.draftVersionNumber;

            if (typeof this.onInit === 'function') {
                this.onInit(initData);
            }

            if (this.isCancelled) return;

            // 3. Phân chia các Parts tối ưu
            const totalParts = Math.ceil(file.size / this.chunkSize);
            const parts = [];
            for (let i = 0; i < totalParts; i++) {
                const start = i * this.chunkSize;
                const end = Math.min(start + this.chunkSize, file.size);
                parts.push({
                    partNumber: i + 1,
                    start,
                    end,
                    blob: file.slice(start, end),
                    eTag: null,
                });
            }

            this.onStatusChange('uploading', `Đang tải song song ${this.concurrency} luồng (${parts.length} phần)...`);

            // 4. Upload các parts với Concurrency song song
            const completedParts = await this.uploadPartsWithConcurrency(parts);

            if (this.isCancelled) return;

            // 5. Hoàn tất Multipart Upload trên S3
            this.onStatusChange('completing', 'Đang xác thực và ghép file hoàn chỉnh trên S3...');

            const completePayload = {
                key: this.s3Key,
                uploadId: this.uploadId,
                parts: completedParts.map(p => ({
                    PartNumber: p.partNumber,
                    ETag: p.eTag,
                })),
                duration: duration,
                lesson_id: this.lessonId || null,
                content_update_id: this.contentUpdateId || null,
            };
            if (this.mediaType) completePayload.media_type = this.mediaType;
            const completeResponse = await fetch(this.completeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify(completePayload),
            });

            if (!completeResponse.ok) {
                const errData = await completeResponse.json().catch(() => ({}));
                throw createS3UploadUserError(
                    typeof errData.message === 'string' && errData.message.trim() !== ''
                        ? errData.message
                        : S3_UPLOAD_PUBLIC_ERROR
                );
            }

            const completeData = await completeResponse.json();

            this.onStatusChange('completed', 'Tải lên S3 hoàn tất thành công!');
            this.onSuccess({
                key: this.s3Key,
                uploadId: this.uploadId,
                filename: file.name,
                size: file.size,
                mime: file.type || 'video/mp4',
                duration: duration,
                location: completeData.location,
                contentUpdateId: completeData.contentUpdateId || this.contentUpdateId,
                versionNumber: completeData.versionNumber || this.draftVersionNumber,
            });

        } catch (error) {
            if (this.isCancelled) {
                this.onStatusChange('cancelled', 'Đã hủy tải lên.');
                return;
            }
            const userFacingError = toS3UploadUserError(error);
            this.onStatusChange('error', userFacingError.message);
            this.onError(userFacingError);
            throw error;
        }
    }

    async uploadPartsWithConcurrency(parts) {
        const results = [];
        let index = 0;

        const worker = async () => {
            while (index < parts.length && !this.isCancelled) {
                const currentPart = parts[index++];
                const completedPart = await this.uploadSinglePartWithRetry(currentPart);
                results.push(completedPart);
            }
        };

        const workers = [];
        const threadCount = Math.min(this.concurrency, parts.length);
        for (let i = 0; i < threadCount; i++) {
            workers.push(worker());
        }

        await Promise.all(workers);

        return results.sort((a, b) => a.partNumber - b.partNumber);
    }

    async uploadSinglePartWithRetry(part, attempt = 1) {
        if (this.isCancelled) return null;

        try {
            // Lấy presigned URL cho part
            const signResp = await fetch(this.signPartUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({
                    key: this.s3Key,
                    uploadId: this.uploadId,
                    partNumber: part.partNumber,
                    ...(this.mediaType ? { media_type: this.mediaType } : {}),
                }),
            });

            if (!signResp.ok) {
                throw new Error(`Lỗi ký upload part ${part.partNumber}`);
            }

            const signData = await signResp.json();
            const presignedUrl = signData.url;

            // Upload binary part trực tiếp lên S3
            const eTag = await this.putBlobToS3(presignedUrl, part.blob, part.partNumber);
            part.eTag = eTag;
            return part;

        } catch (err) {
            if (this.isCancelled) return null;

            if (attempt < this.maxRetries) {
                console.warn(`Part ${part.partNumber} thất bại, thử lại lần ${attempt + 1}...`, err);
                await new Promise(res => setTimeout(res, 1000 * attempt));
                return this.uploadSinglePartWithRetry(part, attempt + 1);
            }
            throw new Error(`Tải part ${part.partNumber} thất bại sau ${this.maxRetries} lần thử: ${err.message}`);
        }
    }

    putBlobToS3(url, blob, partNumber) {
        return new Promise((resolve, reject) => {
            if (this.isCancelled) {
                return reject(new Error('Cancelled'));
            }

            const xhr = new XMLHttpRequest();
            this.activeXhrs.push(xhr);

            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    this.partProgress[partNumber] = e.loaded;
                    this.calculateOverallProgress();
                }
            };

            xhr.onload = () => {
                this.activeXhrs = this.activeXhrs.filter(x => x !== xhr);
                if (xhr.status >= 200 && xhr.status < 300) {
                    let eTag = xhr.getResponseHeader('ETag') || xhr.getResponseHeader('etag');
                    if (eTag) {
                        eTag = eTag.replace(/["']/g, '');
                    }
                    this.partProgress[partNumber] = blob.size;
                    this.calculateOverallProgress();
                    resolve(eTag);
                } else {
                    reject(new Error(`S3 returned HTTP ${xhr.status}`));
                }
            };

            xhr.onerror = () => {
                this.activeXhrs = this.activeXhrs.filter(x => x !== xhr);
                reject(new Error('Mạng gián đoạn khi tải lên S3'));
            };

            xhr.onabort = () => {
                this.activeXhrs = this.activeXhrs.filter(x => x !== xhr);
                reject(new Error('Cancelled'));
            };

            xhr.open('PUT', url, true);
            xhr.send(blob);
        });
    }

    calculateOverallProgress() {
        if (!this.file || this.isCancelled) return;

        let uploadedBytes = 0;
        for (const key in this.partProgress) {
            uploadedBytes += this.partProgress[key] || 0;
        }

        const totalBytes = this.file.size;
        const percent = Math.min(100, Math.round((uploadedBytes / totalBytes) * 100));

        const now = Date.now();
        const elapsedTime = (now - this.startTime) / 1000; // seconds

        // Tính tốc độ trung bình theo cửa sổ trượt (Sliding Window)
        this.speedSamples.push({ time: now, bytes: uploadedBytes });
        if (this.speedSamples.length > 10) {
            this.speedSamples.shift();
        }

        let speed = 0;
        if (this.speedSamples.length >= 2) {
            const first = this.speedSamples[0];
            const last = this.speedSamples[this.speedSamples.length - 1];
            const timeDiff = (last.time - first.time) / 1000;
            const bytesDiff = last.bytes - first.bytes;
            speed = timeDiff > 0 ? (bytesDiff / timeDiff) : (uploadedBytes / elapsedTime);
        } else {
            speed = elapsedTime > 0 ? (uploadedBytes / elapsedTime) : 0;
        }

        const remainingBytes = Math.max(0, totalBytes - uploadedBytes);
        const etaSeconds = speed > 0 ? Math.ceil(remainingBytes / speed) : 0;

        this.onProgress({
            percent,
            uploadedBytes,
            totalBytes,
            uploadedFormatted: this.formatBytes(uploadedBytes),
            totalFormatted: this.formatBytes(totalBytes),
            speedFormatted: this.formatSpeed(speed),
            etaFormatted: this.formatEta(etaSeconds),
            speed,
            etaSeconds,
        });
    }

    async abortMultipartUpload() {
        if (!this.uploadId || !this.s3Key) return;
        try {
            await fetch(this.abortUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({
                    key: this.s3Key,
                    uploadId: this.uploadId,
                    ...(this.mediaType ? { media_type: this.mediaType } : {}),
                }),
            });
        } catch (e) {
            console.warn('S3 AbortMultipartUpload failed:', e);
        }
    }

    cancel() {
        this.isCancelled = true;
        this.activeXhrs.forEach(xhr => {
            try { xhr.abort(); } catch (e) {}
        });
        this.activeXhrs = [];
        this.abortMultipartUpload();
        this.onStatusChange('cancelled', 'Đã hủy tải lên.');
    }

    getVideoDuration(file) {
        return new Promise((resolve) => {
            const video = document.createElement('video');
            video.preload = 'metadata';
            const url = URL.createObjectURL(file);

            video.onloadedmetadata = function () {
                URL.revokeObjectURL(url);
                const dur = Math.round(video.duration || 0);
                resolve(dur);
            };

            video.onerror = function () {
                URL.revokeObjectURL(url);
                resolve(0);
            };

            video.src = url;
        });
    }

    formatBytes(bytes, decimals = 2) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    formatSpeed(bytesPerSecond) {
        if (!bytesPerSecond || bytesPerSecond <= 0) return '0 B/s';
        return this.formatBytes(bytesPerSecond) + '/s';
    }

    formatEta(seconds) {
        if (!seconds || seconds <= 0) return '0s';
        if (seconds < 60) return seconds + 's';
        const minutes = Math.floor(seconds / 60);
        const remainingSec = seconds % 60;
        return minutes + 'm ' + (remainingSec > 0 ? remainingSec + 's' : '');
    }
}

class CourseUploadQueueManager {
    constructor() {
        this.queue = [];
        this.activeUploader = null;
        if (typeof window !== 'undefined') {
            window.CourseUploadQueue = this;
        }
        this.initEventListeners();
    }

    initEventListeners() {
        if (typeof document === 'undefined') return;
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-cancel-queue-id]');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                const id = btn.getAttribute('data-cancel-queue-id');
                if (id) {
                    this.cancelUpload(id);
                }
            }
        });
    }

    addUpload(item) {
        const queueItem = {
            id: item.id || ('upl_' + Date.now() + '_' + Math.random().toString(36).substring(2, 7)),
            title: item.title || item.file.name,
            filename: item.file.name,
            file: item.file,
            size: item.file.size,
            percent: 0,
            status: 'queued', // 'queued' | 'uploading' | 'completed' | 'error'
            statusText: 'Chờ tải',
            key: item.key || '',
            uploadId: item.uploadId || '',
            config: item,
            uploader: null
        };

        this.queue.push(queueItem);
        this.render();
        this.processNext();
        return queueItem;
    }

    processNext() {
        const maxConcurrent = 4;
        const activeCount = this.queue.filter(i => i.status === 'uploading').length;
        if (activeCount >= maxConcurrent) return;

        const next = this.queue.find(i => i.status === 'queued');
        if (!next) {
            this.render();
            return;
        }

        next.status = 'uploading';
        next.statusText = 'Đang tải';
        this.render();

        const uploader = new S3MultipartUploader({
            courseId: next.config.courseId,
            lessonId: next.config.lessonId,
            contentUpdateId: next.config.contentUpdateId,
            draftVersionNumber: next.config.draftVersionNumber,
            createUrl: next.config.createUrl,
            signPartUrl: next.config.signPartUrl,
            completeUrl: next.config.completeUrl,
            abortUrl: next.config.abortUrl,
            maxVideoBytes: next.config.maxVideoBytes,
            concurrency: 4,
            onInit: (initData) => {
                next.key = initData.key;
                next.uploadId = initData.uploadId;
                next.config.lessonId = initData.lessonId || next.config.lessonId;
                next.config.contentUpdateId = initData.contentUpdateId || next.config.contentUpdateId;
                next.config.draftVersionNumber = initData.versionNumber || next.config.draftVersionNumber;
                if (typeof next.config.onInit === 'function') {
                    next.config.onInit(initData);
                }
            },
            onProgress: (prog) => {
                next.percent = prog.percent;
                next.statusText = `${prog.percent}%`;
                this.updateItemProgress(next);

                if (typeof next.config.onProgress === 'function') {
                    next.config.onProgress(prog);
                }
            },
            onSuccess: (data) => {
                next.status = 'completed';
                next.key = data.key;

                if (typeof next.config.onSuccess === 'function') {
                    next.config.onSuccess(data);
                }

                this.queue = this.queue.filter(i => i.id !== next.id);
                this.render();

                if (window.triggerHlsPolling) {
                    window.triggerHlsPolling();
                }

                this.processNext();
            },
            onError: (err) => {
                next.status = 'error';
                next.statusText = 'Lỗi tải lên';
                this.render();

                if (typeof next.config.onError === 'function') {
                    next.config.onError(err);
                }

                this.processNext();
            }
        });

        uploader.s3Key = next.key || null;
        next.uploader = uploader;
        this.activeUploader = uploader;

        uploader.upload(next.file).catch(err => {
            console.error('Queue upload error for', next.filename, err);
        });

        // Tiếp tục kiểm tra nếu còn slot trống để upload song song video tiếp theo
        if (this.queue.some(i => i.status === 'queued') && this.queue.filter(i => i.status === 'uploading').length < maxConcurrent) {
            this.processNext();
        }
    }

    updateItemProgress(item) {
        const percentEl = document.getElementById(`upload-percent-${item.id}`);
        const barEl = document.getElementById(`upload-bar-${item.id}`);
        if (percentEl) {
            percentEl.textContent = `${item.percent}%`;
        }
        if (barEl) {
            barEl.style.width = `${item.percent}%`;
        }
    }

    cancelUpload(itemId) {
        const item = this.queue.find(i => i.id === itemId);
        if (!item) return;

        if (item.uploader) {
            item.uploader.cancel();
        }

        this.queue = this.queue.filter(i => i.id !== itemId);
        this.render();
        this.processNext();

        if (window.showCurriculumToast) {
            window.showCurriculumToast(`Đã hủy tải lên video "${item.title || item.filename}"`);
        }
    }

    render() {
        const panel = document.getElementById('global-video-upload-queue-panel');
        if (!panel) return;

        const activeItems = this.queue.filter(i => i.status === 'uploading' || i.status === 'queued');

        if (activeItems.length === 0) {
            panel.classList.add('hidden');
            panel.innerHTML = '';
            return;
        }

        panel.classList.remove('hidden');

        let html = `
            <div class="rounded-xl border border-indigo-200 bg-indigo-50/80 p-4 shadow-sm space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-black uppercase tracking-wider text-indigo-950 flex items-center gap-2">
                        <svg class="animate-spin h-3.5 w-3.5 text-indigo-600 shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>VIDEO ĐANG TẢI</span>
                    </h4>
                    <span class="text-xs font-bold text-indigo-700 bg-indigo-100 px-2 py-0.5 rounded-full">${activeItems.length} video</span>
                </div>
                <div class="space-y-2">
        `;

        activeItems.forEach(item => {
            const isUploading = item.status === 'uploading';
            const safeTitle = typeof escapeHtml === 'function' ? escapeHtml(item.title) : item.title;
            html += `
                <div id="upload-item-${item.id}" class="flex items-center justify-between bg-white rounded-lg p-3 border border-indigo-100 shadow-2xs text-xs">
                    <div class="min-w-0 flex-1 pr-3">
                        <div class="flex items-center justify-between font-bold text-slate-800 mb-1">
                            <span class="truncate max-w-xs">${safeTitle}</span>
                            <span id="upload-percent-${item.id}" class="font-mono text-indigo-600">${isUploading ? (item.percent + '%') : 'Chờ tải'}</span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                            <div id="upload-bar-${item.id}" class="bg-gradient-to-r from-indigo-500 to-emerald-500 h-2 rounded-full transition-all duration-150" style="width: ${item.percent}%"></div>
                        </div>
                    </div>
                    <div class="shrink-0 flex items-center gap-2">
                        <span class="rounded-full px-2.5 py-0.5 text-[11px] font-bold ${isUploading ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600'}">
                            ${isUploading ? 'Đang tải' : 'Đang chờ'}
                        </span>
                        <button type="button" data-cancel-queue-id="${item.id}" onclick="window.CourseUploadQueue.cancelUpload('${item.id}')" class="text-rose-600 hover:text-rose-700 font-bold hover:underline cursor-pointer text-[11px] px-1.5 py-0.5 select-none">Hủy</button>
                    </div>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;

        panel.innerHTML = html;
    }
}

const CourseUploadQueue = new CourseUploadQueueManager();

function createLessonFormState(config) {
    return {
        selectedType: config.selectedType || '',
        isUploading: false,
        uploadProgress: 0,
        uploadedBytesFormatted: '0 B',
        totalBytesFormatted: '0 B',
        uploadSpeedFormatted: '',
        uploadEtaFormatted: '',
        uploadStatus: 'idle',
        uploadStatusMessage: '',
        s3Key: config.s3Key || '',
        videoOriginalName: config.videoOriginalName || '',
        videoSize: config.videoSize || '',
        videoMime: config.videoMime || '',
        processingStatus: config.processingStatus || '',
        hlsManifestKey: config.hlsManifestKey || '',
        videoPath: config.videoPath || '',
        courseId: config.courseId,
        lessonId: config.lessonId || null,
        contentUpdateId: config.contentUpdateId || null,
        draftVersionNumber: config.draftVersionNumber || null,
        createUrl: config.createUrl,
        signPartUrl: config.signPartUrl,
        completeUrl: config.completeUrl,
        abortUrl: config.abortUrl,
        maxVideoBytes: Number(config.maxVideoBytes) || (5 * 1024 * 1024 * 1024),
        currentQueueId: null,
        pendingVideoFile: null,
        pendingVideoForm: null,
        formBaseline: null,
        isDirty: false,
        isSaving: false,

        init() {
            this.$nextTick(() => {
                const form = this.$root;
                if (!(form instanceof HTMLFormElement)) return;

                this.formBaseline = this.serializeForm(form);
                const updateDirtyState = () => this.updateDirtyState(form);
                form.addEventListener('input', updateDirtyState);
                form.addEventListener('change', updateDirtyState);
                this.updateDirtyState(form);
            });
        },

        serializeForm(form) {
            const entries = [];
            for (const [name, value] of new FormData(form).entries()) {
                if (['_token', '_method'].includes(name)) continue;

                const serializedValue = typeof File !== 'undefined' && value instanceof File
                    ? [value.name, value.size, value.lastModified]
                    : String(value);
                entries.push([name, serializedValue]);
            }

            return JSON.stringify(entries);
        },

        updateDirtyState(form = this.$root) {
            this.$nextTick(() => {
                if (!(form instanceof HTMLFormElement) || this.formBaseline === null) return;

                this.isDirty = this.serializeForm(form) !== this.formBaseline;
                form.dataset.dirty = this.isDirty ? 'true' : 'false';
                const submitButton = form.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = this.isSaving || !this.isDirty;
                }
            });
        },

        markFormSaved(form = this.$root) {
            this.$nextTick(() => {
                if (!(form instanceof HTMLFormElement) || !form.isConnected) return;

                this.formBaseline = this.serializeForm(form);
                this.isDirty = false;
                form.dataset.dirty = 'false';
                this.updateDirtyState(form);
            });
        },

        generateS3Key(filename) {
            const ext = (filename.split('.').pop() || 'mp4').toLowerCase();
            const safeExt = ['mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v'].includes(ext) ? ext : 'mp4';
            const uuid = 'u' + Date.now().toString(36) + Math.random().toString(36).substring(2, 7);
            const lessonPart = this.lessonId ? this.lessonId : ('temp_' + Date.now().toString(36));
            return `originals/courses/${this.courseId}/lessons/${lessonPart}/${uuid}.${safeExt}`;
        },

        startS3Upload(event) {
            const file = event.target.files && event.target.files[0];
            if (!file) return;
            if (file.size > this.maxVideoBytes) {
                this.uploadStatus = 'error';
                this.uploadStatusMessage = `Dung lượng video tối đa là ${Math.round(this.maxVideoBytes / 1048576)}MB.`;
                event.target.value = '';
                if (window.showCurriculumToast) {
                    window.showCurriculumToast(this.uploadStatusMessage, true);
                }
                return;
            }

            const formElement = event.target.closest?.('form') || this.pendingVideoForm || null;
            const titleInput = formElement ? formElement.querySelector("input[name='title']") : null;
            const lessonTitle = (titleInput && titleInput.value.trim()) ? titleInput.value.trim() : file.name;

            // A new lesson in an approved course has no server identity until
            // Save is pressed. Keep the file locally, persist the lesson first,
            // then queue this exact file with the returned lesson_id.
            if (!this.lessonId) {
                this.pendingVideoFile = file;
                this.pendingVideoForm = formElement;
                this.videoOriginalName = file.name;
                this.videoSize = file.size;
                this.videoMime = file.type || 'video/mp4';
                this.uploadStatus = 'pending_save';
                this.uploadStatusMessage = 'Video sẽ được tải lên nền ngay sau khi lưu bài học.';
                this.updateDirtyState(formElement);
                return;
            }

            const preKey = this.generateS3Key(file.name);
            this.videoOriginalName = file.name;
            this.videoSize = file.size;
            this.videoMime = file.type || 'video/mp4';

            this.isUploading = true;
            this.uploadProgress = 0;
            this.uploadStatus = 'uploading';
            this.uploadStatusMessage = 'Đã đưa vào hàng chờ tải lên...';

            // Tự động đọc và điền thời lượng video ngay khi chọn file
            try {
                const tempVideo = document.createElement('video');
                tempVideo.preload = 'metadata';
                tempVideo.onloadedmetadata = () => {
                    window.URL.revokeObjectURL(tempVideo.src);
                    const dur = Math.round(tempVideo.duration);
                    if (dur > 0 && formElement) {
                        const durationInput = formElement.querySelector("input[name='duration']");
                        if (durationInput && (!durationInput.value || durationInput.value === '0' || durationInput.value === '')) {
                            durationInput.value = dur;
                            durationInput.classList.add('ring-2', 'ring-emerald-500', 'border-emerald-500');
                            setTimeout(() => durationInput.classList.remove('ring-2', 'ring-emerald-500'), 2000);
                        }
                    }
                };
                tempVideo.src = URL.createObjectURL(file);
            } catch (e) {
                console.warn('Could not read video metadata client-side:', e);
            }

            const queueItem = CourseUploadQueue.addUpload({
                title: lessonTitle,
                file: file,
                key: preKey,
                courseId: this.courseId,
                lessonId: this.lessonId,
                contentUpdateId: this.contentUpdateId,
                draftVersionNumber: this.draftVersionNumber,
                createUrl: this.createUrl,
                signPartUrl: this.signPartUrl,
                completeUrl: this.completeUrl,
                abortUrl: this.abortUrl,
                maxVideoBytes: this.maxVideoBytes,
                onInit: (initData) => {
                    if (this.currentQueueId === queueItem.id) {
                        this.s3Key = initData.key;
                        this.lessonId = initData.lessonId || this.lessonId;
                        this.contentUpdateId = initData.contentUpdateId || this.contentUpdateId;
                        this.draftVersionNumber = initData.versionNumber || this.draftVersionNumber;
                    }
                },
                onProgress: (prog) => {
                    if (this.currentQueueId === queueItem.id) {
                        this.uploadProgress = prog.percent;
                        this.uploadedBytesFormatted = prog.uploadedFormatted;
                        this.totalBytesFormatted = prog.totalFormatted;
                        this.uploadSpeedFormatted = prog.speedFormatted;
                        this.uploadEtaFormatted = prog.etaFormatted;
                    }
                },
                onSuccess: (data) => {
                    if (this.currentQueueId === queueItem.id) {
                        this.isUploading = false;
                        this.uploadStatus = 'completed';
                        this.uploadStatusMessage = '';
                        this.s3Key = data.key;
                        this.videoOriginalName = data.filename;
                        this.videoSize = data.size;
                        this.videoMime = data.mime;
                        this.contentUpdateId = data.contentUpdateId || this.contentUpdateId;
                        this.draftVersionNumber = data.versionNumber || this.draftVersionNumber;

                        if (data.duration && data.duration > 0 && formElement) {
                            const durationInput = formElement.querySelector("input[name='duration']");
                            if (durationInput) {
                                durationInput.value = data.duration;
                                durationInput.classList.add('ring-2', 'ring-emerald-500', 'border-emerald-500');
                                setTimeout(() => durationInput.classList.remove('ring-2', 'ring-emerald-500'), 2500);
                            }
                        }
                    }
                },
                onError: (err) => {
                    if (this.currentQueueId === queueItem.id) {
                        this.isUploading = false;
                        this.uploadStatus = 'error';
                        this.uploadStatusMessage = toS3UploadUserError(err).message;
                        // Không giữ key tạm khi S3 chưa xác nhận phiên upload.
                        // Nếu giữ key này, form có thể tạo một lesson pending dù file không tồn tại.
                        this.s3Key = '';
                    }
                }
            });

            this.currentQueueId = queueItem.id;
        },

        async submitLessonForm(event) {
            const form = event.target;
            const isCreateForm = !this.lessonId;

            if (!this.isDirty) {
                event.preventDefault();
                this.updateDirtyState(form);

                return;
            }

            if (this.selectedType === 'video' && this.uploadStatus === 'error') {
                event.preventDefault();
                if (window.showCurriculumToast) {
                    window.showCurriculumToast(
                        this.uploadStatusMessage || 'Video tải lên thất bại. Vui lòng chọn lại video.',
                        true
                    );
                }

                return;
            }

            // Chỉ gửi s3_key sau khi API multipart đã khởi tạo thành công và trả key thật.
            if (this.selectedType === 'video' && this.isUploading && !this.s3Key) {
                event.preventDefault();
                if (window.showCurriculumToast) {
                    window.showCurriculumToast('Đang khởi tạo tải video. Vui lòng chờ vài giây rồi lưu lại.', true);
                }

                return;
            }

            // Submit qua AJAX cho cả Create và Edit để trang không reload làm đứt kết nối upload video
            if (window.fetch) {
                event.preventDefault();

                const submitBtn = form.querySelector('button[type="submit"]');
                const origBtnText = submitBtn ? submitBtn.innerHTML : '';
                this.isSaving = true;
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="flex items-center gap-1.5"><svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span>Đang lưu...</span></span>';
                }

                try {
                    const formData = new FormData(form);
                    if (this.pendingVideoFile && !this.s3Key) {
                        formData.set('pending_video_upload', '1');
                    }
                    if (this.s3Key) {
                        formData.set('s3_key', this.s3Key);
                    }
                    if (this.videoOriginalName) {
                        formData.set('video_original_name', this.videoOriginalName);
                    }
                    if (this.videoSize) {
                        formData.set('video_size', this.videoSize);
                    }
                    if (this.videoMime) {
                        formData.set('video_mime', this.videoMime);
                    }

                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    });

                    if (response.ok) {
                        const resData = await response.json();
                        const lessonId = resData.lesson_id || (resData.lesson ? resData.lesson.id : this.lessonId);
                        const lessonTitle = resData.title || (resData.lesson ? resData.lesson.title : '');
                        this.contentUpdateId = resData.content_update_id || this.contentUpdateId;
                        this.draftVersionNumber = resData.version_number || this.draftVersionNumber;

                        refreshCurriculumReviewState(resData);

                        // 1. Đồng bộ và duy trì hàng chờ CourseUploadQueue cho cả Tạo mới và Sửa bài học
                        if (this.currentQueueId) {
                            const qItem = CourseUploadQueue.queue.find(q => q.id === this.currentQueueId);
                            if (qItem) {
                                if (lessonId) {
                                    qItem.lessonId = lessonId;
                                    if (qItem.uploader) {
                                        qItem.uploader.lessonId = lessonId;
                                    }
                                }
                                if (lessonTitle) {
                                    qItem.title = lessonTitle;
                                }
                                if (qItem.config) {
                                    qItem.config.lessonId = lessonId;
                                    qItem.config.onProgress = null;
                                    qItem.config.onInit = null;
                                    qItem.config.onSuccess = null;
                                    qItem.config.onError = null;
                                }
                            }
                            CourseUploadQueue.render();
                        }

                        if (isCreateForm) {
                            const pendingVideoFile = this.pendingVideoFile;
                            // 2. Render ngay lập tức bài học vào danh sách Curriculum DOM
                            if (resData.lesson || resData.html) {
                                appendLessonToCurriculumDOM(resData);
                            }

                            // 3. Reset các ô nhập liệu của form tạo mới
                            const titleInput = form.querySelector('input[name="title"]');
                            if (titleInput) titleInput.value = '';

                            const durationInput = form.querySelector('input[name="duration"]');
                            if (durationInput) durationInput.value = '';

                            const contentInput = form.querySelector('textarea[name="content"]');
                            if (contentInput) contentInput.value = '';

                            const fileInput = this.$refs.s3FileInput;
                            if (fileInput) fileInput.value = '';

                            this.s3Key = '';
                            this.videoOriginalName = '';
                            this.videoSize = '';
                            this.videoMime = '';
                            this.isUploading = false;
                            this.uploadProgress = 0;
                            this.uploadStatus = 'idle';
                            this.uploadStatusMessage = '';
                            this.currentQueueId = null;

                            // Start the existing multipart queue only after the
                            // server has returned the persisted Lesson identity.
                            if (pendingVideoFile && lessonId) {
                                this.lessonId = lessonId;
                                this.pendingVideoFile = null;
                                this.startS3Upload({
                                    target: {
                                        files: [pendingVideoFile],
                                        closest: () => form,
                                    },
                                });
                            }

                            // 4. Đóng accordion "+ Thêm bài học" để sẵn sàng cho bài tiếp theo
                            const parentDetails = form.closest('details');
                            if (parentDetails) {
                                parentDetails.removeAttribute('open');
                            }
                        } else {
                            // Khi sửa bài học thành công, cập nhật ngay lập tức DOM bài học
                            if (lessonId && resData.html) {
                                const existingItem = document.getElementById(`lesson-item-${lessonId}`);
                                if (existingItem) {
                                    const tempDiv = document.createElement('div');
                                    tempDiv.innerHTML = resData.html.trim();
                                    const newEl = tempDiv.firstElementChild;
                                    if (newEl) {
                                        existingItem.replaceWith(newEl);
                                        if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                                            window.Alpine.initTree(newEl);
                                        }
                                    }
                                }
                            } else {
                                const modal = form.closest('[id^="edit-lesson-modal-"]') || form.closest('.fixed');
                                if (modal) {
                                    modal.classList.add('hidden');
                                }
                                if (lessonId && lessonTitle) {
                                    const titleEl = document.querySelector(`#lesson-item-${lessonId} h4`)
                                        || document.querySelector(`[data-lesson-title-key="lesson_${lessonId}"]`);
                                    if (titleEl) {
                                        titleEl.textContent = lessonTitle;
                                    }
                                }
                            }

                            const parentDetails = form.closest('details');
                            if (parentDetails) {
                                parentDetails.removeAttribute('open');
                            }

                            this.currentQueueId = null;

                            // Nếu video vẫn đang tải dở, hiển thị trạng thái chờ tải ở bài học ngoài danh sách
                            if (this.isUploading && lessonId) {
                                const statusEl = document.querySelector(`[data-hls-status-key="lesson_${lessonId}"]`);
                                if (statusEl) {
                                    statusEl.className = 'font-semibold text-amber-600';
                                    statusEl.textContent = 'Video đang tải lên trong hàng chờ...';
                                    statusEl.setAttribute('data-hls-processing', 'true');
                                }
                            }
                        }

                        if (!isCreateForm && lessonId) {
                            try {
                                await refreshCurriculumLesson(lessonId);
                            } catch (refreshError) {
                                window.showCurriculumToast?.('Bài học đã lưu nhưng chưa cập nhật được danh sách. Vui lòng tải lại trang.', true);
                            }
                        }

                        if (window.triggerHlsPolling) {
                            window.triggerHlsPolling();
                        }

                        this.markFormSaved(form);

                        // Hiển thị thông báo thành công
                        if (window.showCurriculumToast) {
                            const isStillUploading = CourseUploadQueue.queue.some(i => i.status === 'uploading' || i.status === 'queued');
                            const msg = isCreateForm
                                ? (lessonTitle ? `Đã lưu bài học "${lessonTitle}" thành công! ${isStillUploading ? 'Video đang tiếp tục tải lên trong hàng chờ.' : ''}` : 'Đã lưu bài học thành công!')
                                : `Đã cập nhật bài học thành công! ${isStillUploading ? 'Video đang tiếp tục tải lên trong hàng chờ.' : ''}`;
                            window.showCurriculumToast(msg);
                        }
                    } else {
                        const errData = await response.json().catch(() => ({}));
                        let errMsg = errData.message || 'Đã có lỗi xảy ra khi lưu bài học. Vui lòng kiểm tra lại.';
                        if (errData.errors) {
                            errMsg = Object.values(errData.errors).flat().join(' ');
                        }
                        if (window.showCurriculumToast) {
                            window.showCurriculumToast(errMsg, true);
                        } else {
                            alert(errMsg);
                        }
                    }
                } catch (e) {
                    console.error('AJAX lesson submit error:', e);
                    if (window.showCurriculumToast) {
                        window.showCurriculumToast('Không thể kết nối máy chủ để lưu bài học. Vui lòng thử lại.', true);
                    }
                } finally {
                    this.isSaving = false;
                    if (submitBtn) {
                        submitBtn.innerHTML = origBtnText;
                    }
                    this.updateDirtyState(form);
                }
            }
        },

        cancelS3Upload() {
            if (this.currentQueueId) {
                CourseUploadQueue.cancelUpload(this.currentQueueId);
                this.currentQueueId = null;
            }
            this.isUploading = false;
            this.uploadProgress = 0;
            this.uploadStatus = 'idle';
            this.uploadStatusMessage = '';
            const fileInput = this.$refs.s3FileInput;
            if (fileInput) fileInput.value = '';
        },

        resetVideoSelection() {
            this.cancelS3Upload();
            this.s3Key = '';
            this.videoOriginalName = '';
            this.videoSize = '';
            this.videoMime = '';
            this.uploadStatus = 'idle';
            this.uploadStatusMessage = '';
            this.updateDirtyState();
        }
    };
}

function refreshCurriculumReviewState(data) {
    if (!data?.reviewStateHtml) return;

    const reviewStateRoot = document.getElementById('curriculum-review-state-root');
    if (reviewStateRoot) {
        reviewStateRoot.outerHTML = data.reviewStateHtml;
    }
}

function showCurriculumToast(message, isError = false) {
    let toast = document.getElementById('curriculum-floating-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'curriculum-floating-toast';
        toast.className = 'fixed bottom-5 right-5 z-50 transition-all duration-300 transform translate-y-10 opacity-0 pointer-events-none';
        document.body.appendChild(toast);
    }

    toast.innerHTML = `
        <div class="flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border text-sm font-bold ${isError ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-emerald-600 text-white border-emerald-700'}">
            <span>${isError ? '⚠️' : '✅'}</span>
            <span>${message}</span>
        </div>
    `;

    toast.classList.remove('translate-y-10', 'opacity-0', 'pointer-events-none');
    toast.classList.add('translate-y-0', 'opacity-100');

    setTimeout(() => {
        toast.classList.remove('translate-y-0', 'opacity-100');
        toast.classList.add('translate-y-10', 'opacity-0', 'pointer-events-none');
    }, 4000);
}

/**
 * Polling trạng thái HLS tự động trên trang Curriculum cấp Course
 * Cập nhật DUY NHẤT 1 Banner thông báo chung và khóa/mở nút "Gửi duyệt"
 */
function initCurriculumHlsPolling(hlsStatusUrl) {
    if (!hlsStatusUrl) return;

    let pollInterval = null;

    async function checkStatus() {
        try {
            const response = await fetch(hlsStatusUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) return;

            const data = await response.json();
            const commonState = data.common_state || 'completed'; // 'completed' | 'processing' | 'failed'
            const commonMessage = data.common_message || '';
            const canSubmit = data.reviewState
                ? !!data.reviewState.canSubmitCourse
                : !!data.can_submit;
            const submissionMessage = data.reviewState?.submissionBlockedReason
                || data.submission_message
                || 'Khóa học chưa đủ điều kiện để gửi duyệt.';

            refreshCurriculumReviewState(data);

            // 1. CẬP NHẬT BANNER HLS CHUNG TỔNG THỂ
            const bannerWrapper = document.getElementById('common-hls-banner-wrapper');
            const messageEl = document.getElementById('common-hls-message');
            const iconEl = document.getElementById('common-hls-icon');

            if (bannerWrapper && messageEl) {
                if (commonState === 'no_videos') {
                    bannerWrapper.classList.add('hidden');
                } else {
                    bannerWrapper.classList.remove('hidden');
                    messageEl.textContent = commonMessage;

                    if (commonState === 'completed') {
                        bannerWrapper.className = 'rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900 shadow-sm transition-all duration-300 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200';
                        if (iconEl) iconEl.textContent = '✅';
                    } else if (commonState === 'failed') {
                        bannerWrapper.className = 'rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-900 shadow-sm transition-all duration-300 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-200';
                        if (iconEl) iconEl.textContent = '⚠️';
                    } else {
                        // processing
                        bannerWrapper.className = 'rounded-2xl border border-amber-300 bg-amber-50 p-4 text-amber-900 shadow-sm transition-all duration-300 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200';
                        if (iconEl) iconEl.textContent = '⏳';
                    }
                }
            }

            // 2. CẬP NHẬT TRẠNG THÁI HLS CHI TIẾT VÀ THỜI LƯỢNG VIDEO BÀI HỌC
            const statuses = data.statuses || {};
            for (const key in statuses) {
                const info = statuses[key];

                // Tự động cập nhật thời lượng theo video hoàn tất
                if (info.duration_formatted && info.duration > 0) {
                    const durElements = document.querySelectorAll(`[data-lesson-duration-key="${key}"]`);
                    durElements.forEach(el => {
                        el.textContent = `Thời lượng: ${info.duration_formatted}`;
                    });

                    // Cập nhật số giây vào ô input thời lượng nếu có
                    const durationInputs = document.querySelectorAll(`input[name="duration"][data-lesson-id="${info.id}"]`);
                    durationInputs.forEach(inp => {
                        if (!inp.value || inp.value === '0') {
                            inp.value = info.duration;
                        }
                    });
                }

                const elements = document.querySelectorAll(`[data-hls-status-key="${key}"]`);
                elements.forEach(el => {
                    if (info.is_ready) {
                        el.className = 'font-semibold text-emerald-600';
                        el.textContent = 'Video đã được xử lý bảo mật thành công.';
                        el.removeAttribute('data-hls-processing');
                    } else if (info.is_uploading || info.upload_status === 'pending') {
                        el.className = 'font-semibold text-amber-600';
                        el.textContent = 'Video đang tải lên trong hàng chờ...';
                        el.setAttribute('data-hls-processing', 'true');
                    } else if (info.is_failed) {
                        el.className = 'font-semibold text-rose-600';
                        el.textContent = 'Video xử lý bảo mật thất bại.';
                        el.removeAttribute('data-hls-processing');
                    } else if (info.is_processing) {
                        el.className = 'font-semibold text-amber-600';
                        el.textContent = 'Video đang trong quá trình xử lý bảo mật. Vui lòng chờ trong giây lát.';
                        el.setAttribute('data-hls-processing', 'true');
                    }
                });
            }

            // 2. KHÓA / MỞ NÚT "GỬI DUYỆT" TRÊN TOÀN BỘ TRANG (Requirement 15, 16)
            const submitButtons = document.querySelectorAll('#curriculum-submit-review-btn, #readinessSubmitForm button[type="submit"], [data-submit-review-btn]');
            submitButtons.forEach(btn => {
                if (canSubmit) {
                    btn.removeAttribute('disabled');
                    btn.classList.remove('bg-slate-200', 'bg-slate-300', 'text-slate-400', 'text-slate-500', 'cursor-not-allowed', 'bg-emerald-600', 'hover:bg-emerald-700', 'dark:bg-slate-800', 'dark:text-slate-600');
                    btn.classList.add('bg-blue-600', 'hover:bg-blue-700', 'text-white', 'cursor-pointer', 'shadow-lg', 'shadow-blue-500/20');
                    btn.removeAttribute('title');
                } else {
                    btn.setAttribute('disabled', 'disabled');
                    btn.classList.remove('bg-blue-600', 'hover:bg-blue-700', 'bg-emerald-600', 'hover:bg-emerald-700', 'text-white', 'cursor-pointer', 'shadow-lg', 'shadow-blue-500/20');
                    btn.classList.add('bg-slate-200', 'text-slate-400', 'cursor-not-allowed', 'dark:bg-slate-800', 'dark:text-slate-600');
                    btn.setAttribute('title', submissionMessage);
                }
            });

            // Nếu toàn bộ HLS đã hoàn tất hoặc thất bại và không có upload nào đang chạy thì ngắt polling
            const hasActiveUploads = CourseUploadQueue.queue.some(i => i.status === 'uploading' || i.status === 'queued');
            if (commonState === 'completed' && !hasActiveUploads && pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        } catch (e) {
            console.warn('HLS status poll error:', e);
        }
    }

    window.triggerHlsPolling = () => {
        checkStatus();
        if (!pollInterval) {
            pollInterval = setInterval(checkStatus, 5000);
        }
    };

    // Bắt đầu polling ngay khi tải trang
    checkStatus();
    pollInterval = setInterval(checkStatus, 5000);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function refreshCurriculumLesson(lessonId) {
    const current = document.getElementById(`lesson-item-${lessonId}`);
    if (!current) throw new Error('Lesson row not found');
    const response = await fetch(window.location.href, {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' },
    });
    if (!response.ok || response.redirected) throw new Error('Could not refresh lesson');
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const fresh = page.getElementById(current.id);
    if (!fresh) throw new Error('Updated lesson row not found');
    // Only replace this lesson, preserving other open forms and active upload queues.
    window.Alpine?.destroyTree?.(current);
    current.replaceWith(document.importNode(fresh, true));
    // Alpine's mutation observer initializes the inserted subtree automatically.
}

function appendLessonToCurriculumDOM(data) {
    const lesson = data.lesson || data;
    const html = data.html;
    if (!lesson || !lesson.section_id) return;

    const container = document.getElementById('section-lessons-' + lesson.section_id)
        || document.querySelector(`[data-section-lessons-container="${lesson.section_id}"]`);
    if (!container) return;

    // Xóa empty notice nếu có
    const emptyNotice = container.querySelector('[data-empty-lessons-notice]');
    if (emptyNotice) {
        emptyNotice.remove();
    }

    if (html) {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html.trim();
        const newEl = tempDiv.firstElementChild;
        if (newEl) {
            container.appendChild(newEl);
            if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                window.Alpine.initTree(newEl);
            }
        }
    } else {
        const typeLabels = {
            'video': 'Video',
            'document': 'Tài liệu',
            'quiz': 'Quiz',
            'assignment': 'Bài tập'
        };
        const typeClass = 'bg-indigo-50 text-indigo-700 border-indigo-200';
        const isVideo = lesson.type === 'video';

        const lessonItem = document.createElement('div');
        lessonItem.className = 'p-5 border-t border-slate-100 first:border-t-0';
        lessonItem.id = 'lesson-item-' + lesson.id;

        let videoBadgeHtml = '';
        if (isVideo) {
            videoBadgeHtml = `<span class="rounded-full border px-2.5 py-1 text-xs font-bold border-emerald-200 bg-emerald-50 text-emerald-700">Đã có video</span>`;
        }

        let previewBadgeHtml = '';
        if (lesson.is_preview) {
            previewBadgeHtml = `<span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">Xem thử</span>`;
        }

        let hlsStatusHtml = '';
        if (isVideo) {
            hlsStatusHtml = `<span data-hls-status-key="lesson_${lesson.id}" data-hls-processing="true" class="font-semibold text-amber-600">Video đang tải lên...</span>`;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            || document.querySelector('input[name="_token"]')?.value || '';

        const durFormatted = lesson.duration_formatted || (lesson.duration > 0 ? `${lesson.duration} giây` : 'Chưa đặt');

        lessonItem.innerHTML = `
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border px-2.5 py-1 text-xs font-bold ${typeClass}">${typeLabels[lesson.type] || 'Video'}</span>
                        <span class="rounded-full border px-2.5 py-1 text-xs font-bold bg-slate-100 text-slate-700 border-slate-200">Nháp</span>
                        ${previewBadgeHtml}
                        ${videoBadgeHtml}
                    </div>
                    <h4 class="mt-2 font-bold text-slate-950">${escapeHtml(lesson.title)}</h4>
                    <div class="mt-1 flex flex-wrap gap-3 text-xs text-slate-500">
                        <span data-lesson-duration-key="lesson_${lesson.id}">Thời lượng: ${durFormatted}</span>
                        <span>Bài ${lesson.sort_order ?? 0}</span>
                        ${hlsStatusHtml}
                    </div>
                    ${lesson.content ? `<p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-600">${escapeHtml(lesson.content)}</p>` : ''}
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    ${lesson.destroy_url ? `
                        <form method="POST" action="${lesson.destroy_url}" onsubmit="return confirm('Bạn chắc chắn muốn xóa bài học này?')">
                            <input type="hidden" name="_token" value="${csrfToken}">
                            <input type="hidden" name="_method" value="DELETE">
                            <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-rose-200 px-4 py-2 text-sm font-bold text-rose-700 transition-colors duration-200 hover:bg-rose-50 cursor-pointer">
                                Xóa
                            </button>
                        </form>
                    ` : ''}
                </div>
            </div>
        `;

        container.appendChild(lessonItem);
    }

    // Cập nhật số lượng bài học trên thống kê đầu trang
    const totalLessonsEl = document.getElementById('overview-total-lessons');
    if (totalLessonsEl) {
        const count = parseInt(totalLessonsEl.textContent.trim()) || 0;
        totalLessonsEl.textContent = count + 1;
    }
}

if (typeof window !== 'undefined') {
    window.S3MultipartUploader = S3MultipartUploader;
    window.CourseUploadQueue = CourseUploadQueue;
    window.createLessonFormState = createLessonFormState;
    window.initCurriculumHlsPolling = initCurriculumHlsPolling;
    window.showCurriculumToast = showCurriculumToast;
    window.appendLessonToCurriculumDOM = appendLessonToCurriculumDOM;

    document.addEventListener('submit', (event) => {
        if (event.target?.id !== 'curriculumSubmitForm') return;

        const button = event.target.querySelector('button[type="submit"]');
        if (!button || button.disabled) return;

        button.disabled = true;
        button.textContent = 'Đang gửi...';
    });
}
