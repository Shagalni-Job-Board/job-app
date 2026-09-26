<div align="center">

# 💼 Shagalni — Job Seeker Application

**The job-seeker–facing app of the Shagalni job board platform**, built with **Laravel 12**.
Job seekers browse open vacancies, apply with a résumé, and receive an **AI-generated compatibility score and feedback** — powered by Google Gemini — without ever waiting on the API call, thanks to a fully asynchronous processing pipeline.

[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-%5E8.2-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind-3-38B2AC?logo=tailwindcss&logoColor=white)](https://tailwindcss.com)
[![Pest](https://img.shields.io/badge/Tested%20with-Pest-EF3B4E)](https://pestphp.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](#license)

</div>

---

## About Shagalni

**Shagalni** is a Laravel-based job board platform split into three repositories that share one database and one domain layer:

| Repository | Role |
|---|---|
| **job-app** *(this repo)* | Public-facing application for **job seekers** — search, apply, track applications |
| [job-backoffice](https://github.com/YoussefSayed-cs/job-backoffice) | Admin & company-owner dashboard — manage vacancies, review applicants |
| [job-shared](https://github.com/YoussefSayed-cs/job-shared) | Shared Eloquent models & notifications used by both apps |

This README covers **job-app** specifically.

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [How the AI Scoring Pipeline Works](#how-the-ai-scoring-pipeline-works)
- [Data Model](#data-model)
- [Route Map](#route-map)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Environment Variables](#environment-variables)
- [Testing](#testing)
- [Related Repositories](#related-repositories)
- [License](#license)

---

## Features

- **Job search & discovery** — searchable, filterable vacancy listing (keyword search across title / location / company name, plus filtering by job type) with pagination on the seeker dashboard.
- **Vacancy details page** — full description, location, and type for a single opening.
- **Apply with an existing résumé or upload a new one** — at apply time, a job seeker can reuse a previously uploaded résumé (skipping re-parsing entirely) or upload a new PDF on the spot.
- **AI-powered résumé screening, fully asynchronous:**
  - The application is saved and a response returned to the user **immediately** (`status: pending`, score `0`) — the user never waits on the AI call.
  - A queued job extracts structured résumé data (contact info, education, experience, skills) from the PDF — only for a *new* résumé; a reused résumé skips straight to scoring, saving an API call.
  - The résumé is then scored against the specific job description, producing a 0–100 compatibility score and 2–4 sentences of evidence-based feedback.
  - Automatic retries with backoff on rate limits (`429`) or upstream errors (`503`); if analysis ultimately fails, a safe fallback message is stored instead of leaving the application stuck.
- **Applicant dashboard** — job seekers track the status (pending / accepted / rejected) and AI feedback for every application they've submitted, and can download the résumé attached to each one.
- **Notifications** — the company owner (and platform admins) are notified whenever a new application comes in for one of their vacancies.
- **Role-protected routes** — every seeker-facing route is guarded by a `role:job-seeker` middleware.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP ^8.2 |
| Frontend | Blade templates, Tailwind CSS 3, Alpine.js, Vite |
| Database | MariaDB / MySQL |
| Auth | Laravel Breeze (session-based) |
| AI | Google Gemini (`gemini-2.5-flash`) via `google-gemini-php/laravel` + direct HTTP calls |
| PDF parsing | `smalot/pdfparser` |
| File storage | S3-compatible cloud storage (`league/flysystem-aws-s3-v3`, `aws/aws-sdk-php`) |
| Queues | Database-backed queue for background résumé analysis |
| Testing | Pest 4 |
| Shared domain layer | [`job/shared`](https://github.com/YoussefSayed-cs/job-shared) private Composer package |

## How the AI Scoring Pipeline Works

```text
User applies with a résumé (PDF)
        │
        ▼
JobVacancyController::processApplications()
   ├─ saves the application immediately  (status: pending, score: 0)
   └─ dispatches ProcessResumeAnalysis   (queued job)
                    │
                    ▼
        ProcessResumeAnalysis  (tries: 3 · timeout: 300s · backoff: 60s → 120s → 180s)
                    │
                    ▼
        ResumesAnalysisServices
   ├─ new résumé?  → Gemini call #1: extract structured data from the PDF text
   │                 (skipped for a reused résumé — data already on file)
   └─               → Gemini call #2: score the résumé against the job description
                    │
                    ▼
        Application updated with aiGeneratedScore (0–100) + aiGeneratedFeedback
```

The user gets an instant response ("Your application has been submitted — AI evaluation is in progress") while the scoring happens in the background.

**Two layers of resilience:**

| Layer | Retries | Trigger |
|---|---|---|
| Inside `ResumesAnalysisServices` (per Gemini HTTP call) | up to 3 attempts, 10s apart | HTTP `429` (rate limited) or `503` (upstream unavailable) |
| The queued job itself (`ProcessResumeAnalysis`) | up to 3 attempts, 60s → 120s → 180s apart | Any uncaught exception (e.g. inner retries exhausted, unreadable PDF, network failure) |

If every attempt at the job level is exhausted, `failed()` stores a safe fallback message (`"AI evaluation is temporarily unavailable..."`) instead of leaving the application stuck at "in progress" forever.

Both Gemini prompts are constrained to return **raw JSON only** (no markdown fences, no invented data — the model is explicitly instructed not to fabricate skills or experience not present in the résumé), which the service decodes directly into the fields stored on the `resumes` and `job_applications` tables.

## Data Model

This app **does not own its domain schema** — `User`, `company`, `job_vacancy`, `job_application`, and `resume` are Eloquent models that live in the shared [`job/shared`](https://github.com/YoussefSayed-cs/job-shared) package and point at tables created by **job-backoffice**'s migrations. The only migration in this repo is for the internal `jobs` (queue) table.

| Table | What this app writes to it |
|---|---|
| `resumes` | New résumé record on upload (`filename`, `fileUri`, `contactDetails`), later filled in by the queue job (`summary`, `skills`, `experience`, `education`) |
| `job_applications` | Created on apply (`status: pending`, `aiGeneratedScore: 0`), updated by the queue job with the final score and feedback |
| `notifications` | A notification is created for the company owner (and admins) on every new application |

## Route Map

| Method | URI | Middleware | Action |
|---|---|---|---|
| GET | `/` | — | Public landing page — latest companies |
| GET | `/dashboard` | `auth`, `role:job-seeker` | Vacancy search & listing |
| GET | `/job-vacancies/{id}` | `auth`, `role:job-seeker` | Vacancy details |
| GET | `/job-vacancies/{id}/apply` | `auth`, `role:job-seeker` | Apply form (choose/upload résumé) |
| POST | `/job-vacancies/{id}/apply` | `auth`, `role:job-seeker` | Submit application, dispatch AI analysis |
| GET | `/job-applications` | `auth`, `role:job-seeker` | My applications — status & AI feedback |
| GET | `/job-applications/{id}/resume` | `auth`, `role:job-seeker` | Download a submitted résumé |
| GET/PATCH/DELETE | `/profile` | `auth`, `role:job-seeker` | Manage own profile |
| GET | `/up` | — | Laravel health-check endpoint |

Plus the standard Breeze auth routes (`/login`, `/register`, `/forgot-password`, email verification, etc.) from `routes/auth.php`.

## Project Structure

```text
app/
├── Events/
│   └── JobApplicationSubmitted.php
├── Http/
│   ├── Controllers/          # DashboardController, JobVacancyController,
│   │                         # JobApplicationsController, ProfileController
│   ├── Middleware/           # RoleMiddleware
│   └── Requests/             # AbblyJobRequest (apply-form validation), ProfileUpdateRequest
├── Jobs/
│   └── ProcessResumeAnalysis.php   # background AI scoring
├── Listeners/
│   └── NotifyCompanyOwner.php
├── Providers/
│   └── AppServiceProvider.php
├── Services/
│   └── ResumesAnalysisServices.php # Gemini integration (extraction + scoring)
└── View/Components/                # AppLayout, GuestLayout, MainLayout

resources/views/
├── dashboard.blade.php
├── job-vacancies/            # show, apply
├── job-applications/         # index
├── welcome.blade.php
├── layouts/ & components/
└── auth/ & profile/

database/migrations/          # internal `jobs` (queue) table only — domain tables come from job-backoffice
routes/                       # web.php, auth.php, api.php, console.php
```

## Getting Started

### Prerequisites

- PHP ≥ 8.2
- Composer
- Node.js & npm
- MariaDB / MySQL
- A Google Gemini API key
- An S3-compatible bucket (for résumé storage)

### Installation

```bash
git clone https://github.com/Shagalni-Job-Board/job-app.git
cd job-app

composer install
cp .env.example .env
php artisan key:generate

# configure your database, AWS, and GEMINI_API_KEY in .env, then:
php artisan migrate

npm install
npm run build
```

> **⚠️ Important — shared database.** This app ships **only** the internal `jobs` queue-table migration; the core domain tables (users, companies, vacancies, applications, résumés) are created by **job-backoffice**'s migrations. Point this app's `.env` at the **same database** (same `DB_HOST` / `DB_DATABASE` / credentials) and run job-backoffice's migrations first.

### Running locally

```bash
composer dev
```

This single command runs four processes concurrently:

| Process | What it does |
|---|---|
| `php artisan serve` | The Laravel dev server |
| `php artisan queue:listen --tries=1` | **Required** — processes `ProcessResumeAnalysis`; without it, applications stay at "pending" forever |
| `php artisan pail --timeout=0` | Live-tails the application log in your terminal |
| `npm run dev` | Vite dev server with hot module reload |

## Environment Variables

| Variable | Purpose |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_URL`, `APP_DEBUG` | Standard Laravel app configuration |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection — **must match job-backoffice's `.env`**, see note above |
| `GEMINI_API_KEY` | Google Gemini API key, used for résumé parsing & scoring |
| `QUEUE_CONNECTION` | Queue driver (`database` by default) — **must be running** for AI scoring |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | Session storage (database-backed by default) |
| `CACHE_STORE` | Cache driver (`database` by default) |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Optional Redis configuration |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS` | Outgoing mail (logged locally by default) |
| `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_ENDPOINT`, `AWS_URL`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | S3-compatible bucket for résumé storage, shared with job-backoffice |
| `VITE_APP_NAME` | App name exposed to the frontend build |

## Testing

```bash
composer test
```

Runs the Pest test suite (`tests/Feature`, `tests/Unit`) after clearing cached config.

## Related Repositories

- [job-backoffice](https://github.com/YoussefSayed-cs/job-backoffice) — admin & company-owner management console
- [job-shared](https://github.com/YoussefSayed-cs/job-shared) — shared Eloquent models & notifications package

## License

Released under the [MIT License](LICENSE) (as declared in `composer.json`).
