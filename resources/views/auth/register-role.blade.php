@extends('layouts.app')

@php
    $isStudent = $role === 'student';
    $pageTitle = $isStudent ? 'Đăng ký học viên' : 'Đăng ký giảng viên';
    $pageSubtitle = $isStudent
        ? 'Tạo tài khoản miễn phí và bắt đầu học ngay hôm nay.'
        : 'Tạo tài khoản giảng viên để xây dựng và quản lý khóa học của bạn.';
    $submitLabel = $isStudent ? 'Tạo tài khoản học viên' : 'Tạo tài khoản giảng viên';
@endphp

@section('title', $pageTitle.' - Website học online FEA')

@section('content')
<x-auth.layout>
    <x-auth.card
        x-data="{
            showTermsModal: false,
            showPassword: false,
            showConfirm: false,
            loading: false,
            passwordVal: '',
            emailMessage: '',
            emailOk: null,
            phoneMessage: '',
            phoneOk: null,
            termsModalOpen: false,
            availabilityUrl: '{{ route('auth.availability') }}',
            get strength() {
                let score = 0;
                let pwd = this.passwordVal || '';
                if (pwd.length >= 8) score++;
                if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
                if (/[0-9]/.test(pwd)) score++;
                if (/[^A-Za-z0-9]/.test(pwd)) score++;
                return score;
            },
            async check(field, value) {
                const minLength = field === 'phone' ? 8 : 3;
                if (!value || value.length < minLength) return;
                const response = await fetch(`${this.availabilityUrl}?field=${field}&value=${encodeURIComponent(value)}`, { headers: { 'Accept': 'application/json' }});
                const data = await response.json();
                this[`${field}Ok`] = data.available;
                this[`${field}Message`] = data.message;
            }
        }"
    >
        <div class="mb-6">
            <a href="{{ route('register') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 transition hover:text-[#0056D2] dark:text-slate-400 dark:hover:text-blue-300">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 19-7-7 7-7"/></svg>
                Chọn loại tài khoản khác
            </a>
        </div>

        <x-auth.header :title="$pageTitle" :subtitle="$pageSubtitle" />

        @if($isStudent)
            <div class="mb-5 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-200">
                Bạn sẽ có thể đăng ký khóa học, theo dõi tiến độ học tập và nhận chứng chỉ hoàn thành.
            </div>
        @else
            <div class="mb-5 rounded-lg border border-violet-100 bg-violet-50 px-4 py-3 text-sm text-violet-800 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-200">
                Sau khi đăng ký, bạn có thể tạo khóa học, quản lý nội dung và theo dõi học viên.
            </div>
        @endif

        {{-- <x-auth.errors /> --}}

        @if($isStudent && \App\Enums\SocialProvider::anyConfigured())
            <x-auth.social-buttons />

            <x-auth.divider>Hoặc tiếp tục bằng email</x-auth.divider>
        @endif

        @unless($isStudent)
            <p class="mb-4 text-center text-xs text-slate-500 dark:text-slate-400">
                Đăng ký bằng Google hoặc Facebook chỉ tạo tài khoản học viên. Để đăng ký giảng viên, vui lòng dùng form bên dưới.
            </p>
        @endunless

        <form method="POST" action="{{ route('register.role', $role) }}" enctype="multipart/form-data" class="space-y-4" x-on:submit="loading = true">
            @csrf
            <input type="hidden" name="captcha_token" value="{{ $captcha['token'] }}">

            <x-auth.input
                label="Họ và tên"
                name="name"
                :value="old('name')"
                placeholder="Nguyễn Văn A"
                autofocus
            />

            <x-auth.input
                label="Email"
                name="email"
                type="email"
                :value="old('email')"
                placeholder="email@example.com"
                x-on:input.debounce.500ms="check('email', $event.target.value)"
            >
                <x-slot:hint>
                    <p x-show="emailMessage" x-text="emailMessage" class="text-xs font-semibold" :class="emailOk ? 'text-emerald-600' : 'text-red-600'"></p>
                </x-slot:hint>
            </x-auth.input>

            <x-auth.input
                label="Số điện thoại"
                name="phone"
                :value="old('phone')"
                placeholder="0912345678"
                x-on:input.debounce.500ms="check('phone', $event.target.value)"
            >
                <x-slot:hint>
                    <p x-show="phoneMessage" x-text="phoneMessage" class="text-xs font-semibold" :class="phoneOk ? 'text-emerald-600' : 'text-red-600'"></p>
                </x-slot:hint>
            </x-auth.input>

            <x-auth.input
                label="Mật khẩu"
                name="password"
                x-bind:type="showPassword ? 'text' : 'password'"
                x-model="passwordVal"
                placeholder="Tối thiểu 8 ký tự"
                inputClass="pr-14"
            >
                <x-slot:trailing>
                    <x-auth.password-toggle />
                </x-slot:trailing>
            </x-auth.input>

            <x-auth.input
                label="Xác nhận mật khẩu"
                name="password_confirmation"
                x-bind:type="showConfirm ? 'text' : 'password'"
                placeholder="Nhập lại mật khẩu"
                inputClass="pr-14"
            >
                <x-slot:trailing>
                    <x-auth.password-toggle toggle="showConfirm = !showConfirm" visible="showConfirm" />
                </x-slot:trailing>
            </x-auth.input>

            <div>
                <div class="mb-2 flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400">
                    <span>Độ mạnh mật khẩu</span>
                    <span x-text="['Yếu','Trung bình','Khá','Mạnh','Rất mạnh'][strength]"></span>
                </div>
                <div class="grid grid-cols-4 gap-2">
                    <template x-for="i in 4" :key="i">
                        <div class="h-2 rounded-full transition duration-200" :class="strength >= i ? 'bg-[#0056D2]' : 'bg-slate-200 dark:bg-slate-800'"></div>
                    </template>
                </div>
            </div>

            @unless($isStudent)
                <div class="space-y-4 rounded-xl border border-violet-200 bg-violet-50/50 p-4 dark:border-violet-800/40 dark:bg-violet-900/10">
                    <h3 class="text-sm font-bold text-violet-900 dark:text-violet-200 flex items-center gap-2">
                        <svg class="h-5 w-5 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0112 20.055a11.952 11.952 0 01-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/></svg>
                        Hồ sơ Giảng viên
                    </h3>

                    @if(!empty($categories) && $categories->isNotEmpty())
                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                                Ngành / Lĩnh vực giảng dạy *
                            </label>
                            <select name="category_id" required class="w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-800 focus:border-[#0056D2] focus:ring-[#0056D2] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                <option value="">-- Chọn ngành / lĩnh vực giảng dạy --</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>
                                        {{ $cat->name }}
                                    </option>
                                    @if($cat->children->isNotEmpty())
                                        @foreach($cat->children as $child)
                                            <option value="{{ $child->id }}" @selected(old('category_id') == $child->id)>
                                                &nbsp;&nbsp;↳ {{ $child->name }}
                                            </option>
                                        @endforeach
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <x-auth.input
                        label="Lĩnh vực chuyên môn chi tiết *"
                        name="specialty"
                        :value="old('specialty')"
                        placeholder="Ví dụ: Lập trình Web Fullstack, Data Science, AI..."
                    />

                    <div>
                        <label for="experience" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                            Kinh nghiệm giảng dạy / làm việc *
                        </label>
                        <textarea
                            id="experience"
                            name="experience"
                            rows="3"
                            placeholder="Mô tả kinh nghiệm thực tế, dự án đã làm hoặc kinh nghiệm giảng dạy..."
                            class="w-full rounded-lg border border-slate-300 bg-white p-3 text-sm text-slate-800 focus:border-[#0056D2] focus:ring-[#0056D2] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 @error('experience') border-red-500 focus:border-red-500 focus:ring-red-500 @enderror"
                        >{{ old('experience') }}</textarea>
                        @error('experience')
                            <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="bio" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                            Giới thiệu bản thân *
                        </label>
                        <textarea
                            id="bio"
                            name="bio"
                            rows="3"
                            placeholder="Giới thiệu đôi nét về bản thân, phong cách giảng dạy..."
                            class="w-full rounded-lg border border-slate-300 bg-white p-3 text-sm text-slate-800 focus:border-[#0056D2] focus:ring-[#0056D2] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 @error('bio') border-red-500 focus:border-red-500 focus:ring-red-500 @enderror"
                        >{{ old('bio') }}</textarea>
                        @error('bio')
                            <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>


                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                            Upload CV (Định dạng PDF, tối đa 5MB)
                        </label>
                        @if(! empty($pendingCv['original_name'] ?? null))
                            <p class="mb-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200">
                                CV đã tải lên: {{ $pendingCv['original_name'] }}. Bạn có thể tiếp tục sửa form mà không cần tải lại.
                            </p>
                        @endif
                        <input
                            type="file"
                            name="cv"
                            accept="application/pdf"
                            class="w-full rounded-lg border border-slate-300 bg-white p-2 text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-violet-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:bg-violet-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 @error('cv') border-red-500 @enderror"
                        >
                        @error('cv')
                            <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                </div>
            @endunless

            <x-auth.captcha :question="$captcha['question']" />

            @if($isStudent)
                <div>
                    <div class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 transition duration-200 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-400 @error('terms') border-red-400 dark:border-red-500 @enderror">
                        <input
                            id="student-terms"
                            type="checkbox"
                            name="terms"
                            value="1"
                            data-student-terms-checkbox
                            @checked(old('terms'))
                            class="mt-1 rounded border-slate-300 text-[#0056D2] focus:ring-[#0056D2] dark:border-slate-700"
                        >
                        <div class="min-w-0 leading-5">
                            <label for="student-terms" class="cursor-pointer">
                                Tôi đồng ý với điều khoản sử dụng, chính sách bảo mật và quy định cộng đồng của Website học online FEA.
                            </label>
                            <button
                                type="button"
                                data-student-terms-trigger
                                x-on:click="termsModalOpen = true; $nextTick(() => $refs.termsCloseButton.focus())"
                                class="mt-2 inline-flex items-center gap-1 font-semibold text-[#0056D2] underline decoration-blue-300 underline-offset-2 transition hover:text-[#0046B8] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0056D2] focus-visible:ring-offset-2 dark:text-blue-300 dark:hover:text-blue-200"
                                aria-haspopup="dialog"
                                aria-controls="student-terms-modal"
                            >
                                Xem chi tiết điều khoản đăng ký
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6"/></svg>
                            </button>
                        </div>
                    </div>
                    @error('terms')
                        <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            @else
                <div class="space-y-4" x-data="{ showTermsModal: false }">
                    {{-- Compact Card Điều khoản dành cho Giảng viên --}}
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4 text-center dark:border-slate-700 dark:bg-slate-800/60">
                        <div class="mb-1.5 flex items-center justify-center gap-2 text-sm font-bold text-slate-800 dark:text-slate-100">
                            <span>Điều khoản dành cho Giảng viên</span>
                        </div>
                        <p class="mb-3 text-xs text-slate-600 dark:text-slate-300">
                            Vui lòng đọc và đồng ý với các điều khoản trước khi đăng ký làm giảng viên.
                        </p>
                        <button
                            type="button"
                            @click="showTermsModal = true"
                            class="inline-flex items-center justify-center rounded-lg bg-violet-100 px-4 py-2 text-xs font-bold text-violet-700 transition duration-200 hover:bg-violet-200 hover:text-violet-800 dark:bg-violet-950/80 dark:text-violet-300 dark:hover:bg-violet-900 cursor-pointer"
                        >
                            Xem điều khoản đầy đủ
                        </button>
                    </div>

                    <div>
                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3.5 text-sm text-slate-600 transition duration-200 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                            <input type="checkbox" name="agree_information" value="1" @checked(old('agree_information')) class="mt-1 rounded border-slate-300 text-violet-600 focus:ring-violet-500 dark:border-slate-700">
                            <span>Tôi cam kết thông tin trên là chính xác.</span>
                        </label>
                        @error('agree_information')
                            <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3.5 text-sm text-slate-600 transition duration-200 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                            <input type="checkbox" name="agree_terms" value="1" @checked(old('agree_terms')) class="mt-1 rounded border-slate-300 text-violet-600 focus:ring-violet-500 dark:border-slate-700">
                            <span>Tôi đã đọc và đồng ý với Điều khoản dành cho Giảng viên.</span>
                        </label>
                        @error('agree_terms')
                            <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Modal Popup Điều khoản dành cho Giảng viên --}}
                    <template x-teleport="body">
                        <div
                            x-show="showTermsModal"
                            x-cloak
                            style="display: none;"
                            class="fixed inset-0 z-[9999] overflow-y-auto"
                            x-init="$watch('showTermsModal', value => document.body.classList.toggle('overflow-hidden', value))"
                            @keydown.escape.window="showTermsModal = false"
                        >
                            {{-- Modal Overlay / Backdrop --}}
                            <div
                                x-show="showTermsModal"
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0"
                                x-transition:enter-end="opacity-100"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm"
                                @click="showTermsModal = false"
                            ></div>

                            {{-- Centered Flex Wrapper --}}
                            <div class="flex min-h-full items-center justify-center p-3 sm:p-6 text-center">
                                {{-- Solid White Popup Box --}}
                                <div
                                    x-show="showTermsModal"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 scale-95 translate-y-2 sm:translate-y-0"
                                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                                    x-transition:leave-end="opacity-0 scale-95 translate-y-2 sm:translate-y-0"
                                    class="relative z-10 w-full max-w-[600px] rounded-2xl bg-white dark:bg-slate-900 shadow-2xl shadow-slate-950/40 border border-slate-200 dark:border-slate-800 text-left overflow-hidden flex flex-col my-auto"
                                    style="background-color: #ffffff;"
                                    @click.stop
                                >
                                    {{-- Header (Fixed, Solid Background) --}}
                                    <div class="flex shrink-0 items-center justify-between border-b border-slate-100 bg-white px-5 sm:px-6 py-4.5 dark:border-slate-800 dark:bg-slate-900" style="background-color: #ffffff;">
                                        <h3 class="flex items-center gap-2.5 text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                                            <svg class="h-5 w-5 text-violet-600 dark:text-violet-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            <span>Điều khoản dành cho Giảng viên</span>
                                        </h3>
                                        <button
                                            type="button"
                                            @click="showTermsModal = false"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200 cursor-pointer"
                                            aria-label="Đóng"
                                        >
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>

                                    {{-- Body (Scrollable, Solid Background) --}}
                                    <div class="flex-1 overflow-y-auto p-5 sm:p-6 space-y-5 text-slate-700 dark:text-slate-200 custom-modal-scrollbar max-h-[55vh] bg-white dark:bg-slate-900" style="background-color: #ffffff;">
                                        <div>
                                            <h4 class="text-[15px] font-semibold text-slate-900 dark:text-white">1. Cam kết về thông tin cá nhân</h4>
                                            <p class="mt-1.5 text-sm leading-[1.6] text-slate-600 dark:text-slate-300">Giảng viên cam kết cung cấp thông tin đăng ký, hồ sơ chuyên môn, CV và chứng chỉ chính xác, trung thực và không sử dụng giấy tờ giả mạo.</p>
                                        </div>
                                        <div>
                                            <h4 class="text-[15px] font-semibold text-slate-900 dark:text-white">2. Trách nhiệm của giảng viên</h4>
                                            <p class="mt-1.5 text-sm leading-[1.6] text-slate-600 dark:text-slate-300">Giảng viên có trách nhiệm cung cấp nội dung giáo dục chất lượng, chính xác và phù hợp với mô tả khóa học.</p>
                                        </div>
                                        <div>
                                            <h4 class="text-[15px] font-semibold text-slate-900 dark:text-white">3. Quy tắc sử dụng nền tảng</h4>
                                            <p class="mt-1.5 text-sm leading-[1.6] text-slate-600 dark:text-slate-300">Giảng viên không được sử dụng nền tảng cho các hành vi lừa đảo, spam, quảng cáo trái phép hoặc hoạt động vi phạm pháp luật.</p>
                                        </div>
                                        <div>
                                            <h4 class="text-[15px] font-semibold text-slate-900 dark:text-white">4. Nội dung bị cấm</h4>
                                            <p class="mt-1.5 text-sm leading-[1.6] text-slate-600 dark:text-slate-300">Không đăng tải nội dung khiêu dâm, bạo lực, kích động thù hận, phân biệt đối xử hoặc nội dung vi phạm pháp luật.</p>
                                        </div>
                                        <div>
                                            <h4 class="text-[15px] font-semibold text-slate-900 dark:text-white">5. Bảo mật tài khoản</h4>
                                            <p class="mt-1.5 text-sm leading-[1.6] text-slate-600 dark:text-slate-300">Giảng viên có trách nhiệm bảo mật tài khoản và không chia sẻ tài khoản cho người khác sử dụng.</p>
                                        </div>
                                        <div>
                                            <h4 class="text-[15px] font-semibold text-slate-900 dark:text-white">6. Ứng xử với học viên</h4>
                                            <p class="mt-1.5 text-sm leading-[1.6] text-slate-600 dark:text-slate-300">Giảng viên phải giao tiếp chuyên nghiệp, tôn trọng học viên và không được lợi dụng thông tin học viên cho mục đích trái phép.</p>
                                        </div>
                                        <div>
                                            <h4 class="text-[15px] font-semibold text-slate-900 dark:text-white">7. Xử lý vi phạm</h4>
                                            <p class="mt-1.5 text-sm leading-[1.6] text-slate-600 dark:text-slate-300">Nền tảng có quyền cảnh báo, hạn chế, tạm khóa hoặc chấm dứt quyền giảng viên nếu phát hiện vi phạm.</p>
                                        </div>
                                        <div>
                                            <h4 class="text-[15px] font-semibold text-slate-900 dark:text-white">8. Cập nhật điều khoản</h4>
                                            <p class="mt-1.5 text-sm leading-[1.6] text-slate-600 dark:text-slate-300">Nền tảng có thể cập nhật điều khoản dành cho giảng viên khi cần thiết.</p>
                                        </div>

                                        {{-- Khối xem file PDF gốc --}}
                                        <div class="rounded-xl border border-violet-100 bg-violet-50/70 p-3.5 dark:border-violet-900/40 dark:bg-violet-950/30">
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="text-xs text-violet-900 dark:text-violet-200">
                                                    <span class="font-bold block">Tài liệu pháp lý PDF</span>
                                                    <span>Xem văn bản PDF chính thức có dấu và điều khoản chi tiết.</span>
                                                </div>
                                                <a
                                                    href="{{ route('legal.registration-terms') }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-violet-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-violet-700"
                                                >
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                    <span>Mở PDF trong tab mới</span>
                                                </a>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Footer (Fixed, Solid Background) --}}
                                    <div class="flex shrink-0 items-center justify-end border-t border-slate-100 bg-slate-50 px-5 sm:px-6 py-4 dark:border-slate-800 dark:bg-slate-900" style="background-color: #f8fafc;">
                                        <button
                                            type="button"
                                            @click="showTermsModal = false"
                                            class="inline-flex h-10 items-center justify-center rounded-[10px] bg-violet-600 px-6 text-sm font-semibold text-white shadow-sm transition duration-200 hover:bg-violet-700 active:bg-violet-800 cursor-pointer"
                                        >
                                            Đóng
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            @endif

            <x-auth.button x-bind:disabled="loading" loading-text="Đang tạo tài khoản...">
                {{ $submitLabel }}
            </x-auth.button>
        </form>

        @if($isStudent)
            <x-auth.student-terms-modal />
        @endif

        <x-auth.footer-link
            text="Đã có tài khoản?"
            link-text="Đăng nhập"
            :href="route('login')"
        />
    </x-auth.card>
</x-auth.layout>
@endsection
