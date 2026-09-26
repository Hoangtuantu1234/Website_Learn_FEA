<?php

namespace App\Http\Requests\Auth;

use App\Services\CaptchaService;
use App\Services\RegistrationPendingCvService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class RegisterInstructorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\-\s().]{8,20}$/', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'category_id' => ['nullable', 'exists:categories,id'],
            'teaching_field' => ['nullable', 'string', 'max:255'],
            'specialty' => ['required', 'string', 'max:255'],
            'experience' => ['required', 'string', 'max:2000'],
            'bio' => ['required', 'string', 'max:2000'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'github_url' => ['nullable', 'url', 'max:255'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'cv' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            'agree_information' => ['accepted'],
            'agree_terms' => ['accepted'],
            'role' => ['prohibited'],
            'captcha_token' => ['required', 'string'],
            'captcha_answer' => ['required', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập họ và tên.',
            'name.string' => 'Họ và tên không hợp lệ.',
            'name.max' => 'Họ và tên không được vượt quá 255 ký tự.',
            'email.required' => 'Vui lòng nhập địa chỉ email.',
            'email.email' => 'Địa chỉ email không đúng định dạng.',
            'email.max' => 'Email không được vượt quá 255 ký tự.',
            'email.unique' => 'Email này đã được sử dụng, vui lòng chọn email khác.',
            'phone.required' => 'Vui lòng nhập số điện thoại.',
            'phone.string' => 'Số điện thoại không hợp lệ.',
            'phone.regex' => 'Số điện thoại không đúng định dạng (8–20 ký tự số).',
            'phone.unique' => 'Số điện thoại này đã được sử dụng, vui lòng chọn số khác.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
            'password.confirmed' => 'Xác nhận mật khẩu không khớp.',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
            'password.mixed' => 'Mật khẩu phải có cả chữ hoa và chữ thường.',
            'password.numbers' => 'Mật khẩu phải chứa ít nhất một chữ số.',
            'password.symbols' => 'Mật khẩu phải chứa ít nhất một ký tự đặc biệt.',
            'specialty.required' => 'Vui lòng nhập lĩnh vực chuyên môn.',
            'specialty.string' => 'Lĩnh vực chuyên môn không hợp lệ.',
            'specialty.max' => 'Chuyên môn không được vượt quá 255 ký tự.',
            'experience.required' => 'Vui lòng nhập kinh nghiệm giảng dạy/làm việc.',
            'experience.string' => 'Kinh nghiệm không hợp lệ.',
            'experience.max' => 'Kinh nghiệm không được vượt quá 2000 ký tự.',
            'bio.required' => 'Vui lòng giới thiệu bản thân.',
            'bio.string' => 'Giới thiệu bản thân không hợp lệ.',
            'bio.max' => 'Giới thiệu bản thân không được vượt quá 2000 ký tự.',
            'linkedin_url.url' => 'Địa chỉ LinkedIn không hợp lệ.',
            'github_url.url' => 'Địa chỉ GitHub không hợp lệ.',
            'website_url.url' => 'Địa chỉ Website không hợp lệ.',
            'cv.file' => 'Tệp CV không hợp lệ.',
            'cv.mimes' => 'CV phải ở định dạng PDF.',
            'cv.max' => 'Dung lượng CV tối đa là 5MB.',
            'agree_information.accepted' => 'Vui lòng cam kết các thông tin đăng ký là chính xác.',
            'agree_terms.accepted' => 'Bạn phải đọc và đồng ý với Điều khoản dành cho Giảng viên.',
            'role.prohibited' => 'Không thể chỉ định vai trò khi đăng ký.',
            'captcha_token.required' => 'Phiên xác nhận đã hết hạn, vui lòng tải lại trang.',
            'captcha_answer.required' => 'Vui lòng nhập kết quả phép tính xác nhận.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'họ và tên',
            'email' => 'email',
            'phone' => 'số điện thoại',
            'password' => 'mật khẩu',
            'specialty' => 'chuyên môn',
            'experience' => 'kinh nghiệm',
            'bio' => 'giới thiệu bản thân',
            'linkedin_url' => 'đường dẫn LinkedIn',
            'github_url' => 'đường dẫn GitHub',
            'website_url' => 'trang web',
            'cv' => 'tệp CV',
            'agree_information' => 'cam kết thông tin',
            'agree_terms' => 'điều khoản giảng viên',
            'captcha_answer' => 'mã xác nhận',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->has('cv') && $this->hasFile('cv')) {
                app(RegistrationPendingCvService::class)->persistIfValid($this->file('cv'), $this);
            }
        });
    }

    public function validateCaptcha(): void
    {
        if (! CaptchaService::verify($this->input('captcha_token'), $this->input('captcha_answer'), 'register')) {
            throw ValidationException::withMessages([
                'captcha_answer' => 'Mã xác nhận không chính xác hoặc đã hết hạn.',
            ]);
        }
    }
}
