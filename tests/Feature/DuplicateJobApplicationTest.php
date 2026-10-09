<?php

use App\Models\User;
use App\Models\company;
use App\Models\job_application;
use App\Models\job_category;
use App\Models\job_vacancy;
use App\Models\resume;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake('cloud');

    // Create a job-seeker user
    $this->user = User::factory()->create(['role' => 'job-seeker']);

    // Create a company owner
    $this->companyOwner = User::factory()->create(['role' => 'company-owner']);

    // Create a job category
    $this->category = job_category::create([
        'name' => 'Technology',
    ]);

    // Create a company
    $this->company = company::create([
        'name' => 'Test Company',
        'address' => '123 Test St',
        'industry' => 'Technology',
        'description' => 'A test company',
        'ownerID' => $this->companyOwner->id,
    ]);

    // Create a job vacancy
    $this->vacancy = job_vacancy::create([
        'title' => 'Laravel Developer',
        'description' => 'We need a Laravel developer',
        'location' => 'Remote',
        'salary' => '5000',
        'type' => 'Remote',
        'companyID' => $this->company->id,
        'categoryID' => $this->category->id,
    ]);

    // Create an existing resume for the user
    $this->resume = resume::create([
        'filename' => 'test_resume.pdf',
        'fileUri' => 'resume/test_resume.pdf',
        'userID' => $this->user->id,
        'contactDetails' => ['name' => 'Test User', 'email' => 'test@example.com'],
        'summary' => 'A test resume',
        'skills' => ['PHP', 'Laravel'],
        'experience' => [],
        'education' => [],
    ]);
});

test('a job seeker can apply to a vacancy successfully', function () {
    $response = $this->actingAs($this->user)
        ->post(route('job-vacancy.processApplications', $this->vacancy->id), [
            'resume_option' => 'existing_' . $this->resume->id,
        ]);

    $response->assertRedirect(route('job-applications.index', $this->vacancy->id));
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('job_applications', [
        'userID' => $this->user->id,
        'jobVacancyID' => $this->vacancy->id,
    ]);
});

test('a job seeker cannot apply to the same vacancy twice via POST', function () {
    // First application — should succeed
    job_application::create([
        'status' => 'pending',
        'aiGeneratedScore' => 0,
        'aiGeneratedFeedback' => 'AI evaluation is in progress…',
        'jobVacancyID' => $this->vacancy->id,
        'resumeID' => $this->resume->id,
        'userID' => $this->user->id,
    ]);

    $this->assertDatabaseCount('job_applications', 1);

    // Second application attempt — should be rejected
    $response = $this->actingAs($this->user)
        ->post(route('job-vacancy.processApplications', $this->vacancy->id), [
            'resume_option' => 'existing_' . $this->resume->id,
        ]);

    $response->assertSessionHasErrors('job_vacancy');

    // Still only one application in the database
    $this->assertDatabaseCount('job_applications', 1);
});

test('the database rejects duplicate active applications', function () {
    job_application::create([
        'status' => 'pending',
        'aiGeneratedScore' => 0,
        'aiGeneratedFeedback' => 'AI evaluation is in progress…',
        'jobVacancyID' => $this->vacancy->id,
        'resumeID' => $this->resume->id,
        'userID' => $this->user->id,
    ]);

    expect(fn () => job_application::create([
        'status' => 'pending',
        'aiGeneratedScore' => 0,
        'aiGeneratedFeedback' => 'AI evaluation is in progress…',
        'jobVacancyID' => $this->vacancy->id,
        'resumeID' => $this->resume->id,
        'userID' => $this->user->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('a user can reapply after their previous application is soft deleted', function () {
    $application = job_application::create([
        'status' => 'pending',
        'aiGeneratedScore' => 0,
        'aiGeneratedFeedback' => 'AI evaluation is in progress…',
        'jobVacancyID' => $this->vacancy->id,
        'resumeID' => $this->resume->id,
        'userID' => $this->user->id,
    ]);

    $application->delete();

    $response = $this->actingAs($this->user)
        ->post(route('job-vacancy.processApplications', $this->vacancy->id), [
            'resume_option' => 'existing_' . $this->resume->id,
        ]);

    $response->assertRedirect(route('job-applications.index', $this->vacancy->id));
    $this->assertDatabaseCount('job_applications', 2);
});

test('a job seeker is redirected away from the apply form if already applied', function () {
    // Create an existing application
    job_application::create([
        'status' => 'pending',
        'aiGeneratedScore' => 0,
        'aiGeneratedFeedback' => 'AI evaluation is in progress…',
        'jobVacancyID' => $this->vacancy->id,
        'resumeID' => $this->resume->id,
        'userID' => $this->user->id,
    ]);

    // Trying to visit the apply form should redirect back
    $response = $this->actingAs($this->user)
        ->get(route('job-vacancy.apply', $this->vacancy->id));

    $response->assertRedirect(route('job-vacancy.show', $this->vacancy->id));
    $response->assertSessionHasErrors('job_vacancy');
});

test('a different user can apply to the same vacancy', function () {
    // First user applies
    job_application::create([
        'status' => 'pending',
        'aiGeneratedScore' => 0,
        'aiGeneratedFeedback' => 'AI evaluation is in progress…',
        'jobVacancyID' => $this->vacancy->id,
        'resumeID' => $this->resume->id,
        'userID' => $this->user->id,
    ]);

    // Second user
    $otherUser = User::factory()->create(['role' => 'job-seeker']);
    $otherResume = resume::create([
        'filename' => 'other_resume.pdf',
        'fileUri' => 'resume/other_resume.pdf',
        'userID' => $otherUser->id,
        'contactDetails' => ['name' => 'Other User', 'email' => 'other@example.com'],
        'summary' => 'Another test resume',
        'skills' => ['Python'],
        'experience' => [],
        'education' => [],
    ]);

    $response = $this->actingAs($otherUser)
        ->post(route('job-vacancy.processApplications', $this->vacancy->id), [
            'resume_option' => 'existing_' . $otherResume->id,
        ]);

    $response->assertRedirect(route('job-applications.index', $this->vacancy->id));
    $response->assertSessionHas('success');

    // Both applications should exist
    $this->assertDatabaseCount('job_applications', 2);
});

test('a user can apply to a different vacancy after applying to one', function () {
    // Apply to the first vacancy
    job_application::create([
        'status' => 'pending',
        'aiGeneratedScore' => 0,
        'aiGeneratedFeedback' => 'AI evaluation is in progress…',
        'jobVacancyID' => $this->vacancy->id,
        'resumeID' => $this->resume->id,
        'userID' => $this->user->id,
    ]);

    // Create a second vacancy
    $secondVacancy = job_vacancy::create([
        'title' => 'React Developer',
        'description' => 'We need a React developer',
        'location' => 'Onsite',
        'salary' => '6000',
        'type' => 'Full-Time',
        'companyID' => $this->company->id,
        'categoryID' => $this->category->id,
    ]);

    // Apply to the second vacancy — should succeed
    $response = $this->actingAs($this->user)
        ->post(route('job-vacancy.processApplications', $secondVacancy->id), [
            'resume_option' => 'existing_' . $this->resume->id,
        ]);

    $response->assertRedirect(route('job-applications.index', $secondVacancy->id));
    $response->assertSessionHas('success');

    $this->assertDatabaseCount('job_applications', 2);
});
