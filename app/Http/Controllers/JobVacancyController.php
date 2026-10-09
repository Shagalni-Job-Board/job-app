<?php

namespace App\Http\Controllers;

use App\Http\Requests\AbblyJobRequest;
use App\Jobs\ProcessResumeAnalysis;
use App\Models\User;
use App\Models\job_application;
use App\Models\job_vacancy;
use App\Models\resume;
use App\Services\ResumesAnalysisServices;
use App\Notifications\newJobApply;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class JobVacancyController extends Controller
{
    protected ResumesAnalysisServices $resumeService;

    public function __construct(ResumesAnalysisServices $resumeService)
    {
        $this->resumeService = $resumeService;
    }

    public function show(string $id)
    {
        $job_vacancy = job_vacancy::findOrFail($id);
        return view('job-vacancies.show', compact('job_vacancy'));
    }

    public function apply(string $id)
    {
        $job_vacancy = job_vacancy::findOrFail($id);

        $alreadyApplied = job_application::where('userID', Auth::id())
            ->where('jobVacancyID', $job_vacancy->id)
            ->exists();

        if ($alreadyApplied) {
            return redirect()->route('job-vacancy.show', $job_vacancy->id)
                ->withErrors(['job_vacancy' => 'You have already applied to this job vacancy.']);
        }

        $resumes = Auth::user()->resume;

        return view('job-vacancies.apply', compact('job_vacancy', 'resumes'));
    }

    public function processApplications(AbblyJobRequest $request, string $id)
    {
        $job_vacancy  = job_vacancy::findOrFail($id);

        $resumeID     = null;
        $isNewResume  = false;

        /*
        |------------------------------------------------------------------
        | EXISTING RESUME
        |------------------------------------------------------------------
        */
        if (str_starts_with($request->resume_option, 'existing_')) {
            $existingId = str_replace('existing_', '', $request->resume_option);

            $resume = resume::where('id', $existingId)
                ->where('userID', Auth::id())
                ->first();

            if (!$resume) {
                return back()->withErrors(['resume_option' => 'Invalid resume selected']);
            }

            $resumeID    = $resume->id;
            $isNewResume = false;
        }

        /*
        |------------------------------------------------------------------
        | NEW RESUME — بس رفع الملف، مفيش AI هنا
        |------------------------------------------------------------------
        */
        elseif ($request->resume_option === 'new_resume') {
            $file     = $request->file('resume_file');
            $fileName = 'resume_' . time() . '.pdf';
            $path     = $file->storeAs('resume', $fileName, 'cloud');

            // بنحفظ الـ resume بقيم فاضية، الـ Queue هو اللي هيملاها
            $resume = resume::create([
                'filename'       => $file->getClientOriginalName(),
                'fileUri'        => $path,
                'userID'         => Auth::id(),
                'contactDetails' => [
                    'name'  => Auth::user()->name,
                    'email' => Auth::user()->email,
                ],
                'summary'    => '',
                'skills'     => [],
                'experience' => [],
                'education'  => [],
            ]);

            $resumeID    = $resume->id;
            $isNewResume = true;
        }

        /*
        |------------------------------------------------------------------
        | JOB APPLICATION — بنحفظها فوراً بـ score = 0 وstatus انتظار
        |------------------------------------------------------------------
        */
        try {
            $jobApplication = job_application::create([
                'status'              => 'pending',
                'aiGeneratedScore'    => 0,
                'aiGeneratedFeedback' => 'AI evaluation is in progress…',
                'jobVacancyID'        => $job_vacancy->id,
                'resumeID'            => $resumeID,
                'userID'              => Auth::id(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The request validation handles the usual case. This handles
            // concurrent submissions that reach the database together.
            throw ValidationException::withMessages([
                'job_vacancy' => 'You have already applied to this job vacancy.',
            ]);
        }

        /*
        |------------------------------------------------------------------
        | DISPATCH QUEUE JOB — بنبعت الشغل للـ Background
        |------------------------------------------------------------------
        */
        ProcessResumeAnalysis::dispatch(
            $jobApplication->id,
            $resumeID,
            $job_vacancy->id,
            $isNewResume,
        );

        /*
        |------------------------------------------------------------------
        | NOTIFICATIONS
        |------------------------------------------------------------------
        */
        $owner = $job_vacancy->company->Owner;
        if ($owner) {
            $owner->notify(new newJobApply(Auth::user(), $job_vacancy, $jobApplication, $jobApplication->id));
        }

        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            if ($owner && $admin->id === $owner->id) continue;
            $admin->notify(new newJobApply(Auth::user(), $job_vacancy, $jobApplication, $jobApplication->id));
        }

        // رد فوري! مش بنستنى Gemini خالص
        return redirect()->route('job-applications.index', $job_vacancy->id)->with('success', 'Your application has been submitted! AI evaluation is in progress and will be ready shortly.');
    }
}
