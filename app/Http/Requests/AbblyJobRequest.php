<?php

namespace App\Http\Requests;

use App\Models\job_application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AbblyJobRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resume_option' => 'required|string',
            'resume_file' => 'required_if:resume_option,new_resume|file|mimes:pdf|max:5120',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $vacancyId = $this->route('id');

            $alreadyApplied = job_application::where('userID', $this->user()?->id)
                ->where('jobVacancyID', $vacancyId)
                ->exists();

            if ($alreadyApplied) {
                $validator->errors()->add('job_vacancy', 'You have already applied to this job vacancy.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'resume_option.required' => 'Please select a resume option.',

            'resume_file.required_if' => 'Please upload your resume when choosing a new resume.',
            'resume_file.file' => 'The resume must be a valid file.',
            'resume_file.mimes' => 'Only PDF files are allowed.',
            'resume_file.max' => 'The resume size must not exceed 5MB.',
        ];
    }
}
