# Job App

Job-seeker facing application for the job portal platform, built with **Laravel 12**. Job seekers browse open vacancies, apply with a résumé, and get an **AI-generated compatibility score and feedback** — powered by Google Gemini — without ever waiting on the API call, thanks to a fully asynchronous processing pipeline.

This repository is one of three that make up the platform:

| Repo | Role |
|---|---|
| **job-app** *(this repo)* | Public-facing app for job seekers |<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=0:0F172A,100:2563EB&height=220&section=header&text=Youssef%20Sayed&fontSize=55&fontColor=FFFFFF&fontAlignY=38&desc=PHP%20%7C%20Laravel%20%7C%20Backend%20Developer&descAlignY=58&descSize=20" width="100%"/>

</div>

# Youssef Sayed

### Computer Science Student | PHP & Laravel Backend Developer

I build practical backend applications using PHP and Laravel, with a focus on clean code, REST APIs, databases, and real-world application design.

---

## About Me

- Computer Science student
- Focused on PHP and Laravel backend development
- Interested in software architecture and backend engineering
- Building practical projects to improve my development skills
- Currently improving my knowledge of databases, testing, caching, Docker, and deployment

---

## Tech Stack

### Backend

<img src="https://skillicons.dev/icons?i=php,laravel" />

### Database

<img src="https://skillicons.dev/icons?i=mysql,postgresql" />

### Tools

<img src="https://skillicons.dev/icons?i=git,github,docker,linux,postman,vscode" />

---

## Backend Concepts

`OOP` · `MVC` · `REST APIs` · `Authentication` · `Authorization`

`Eloquent ORM` · `Service Layer` · `Repository Pattern`

`Queues & Jobs` · `Events & Listeners` · `Notifications`

`Database Design` · `API Design` · `Clean Code`

---

# Featured Project

## Shagalni — Job Board Platform

A Laravel 12 job board platform built around real-world recruitment workflows.

### Main Features

- Authentication and authorization
- Role-based access control
- Job vacancy management
- Search, filtering, and pagination
- Resume management
- Job applications
- Application tracking
- Database notifications
- Queued jobs
- Events and listeners
- AI-powered CV compatibility analysis
- Admin and company dashboards
- REST APIs
- Soft deletes

### Architecture

```text
Laravel Application
│
├── MVC
├── Service Layer
├── Repository Pattern
├── Events & Listeners
├── Queues & Jobs
├── Notifications
├── REST APIs
└── Role-Based Authorization
| [job-backoffice](https://github.com/YoussefSayed-cs/job-backoffice) | Admin & company-owner dashboard |
| [job-shared](https://github.com/YoussefSayed-cs/job-shared) | Shared Eloquent models & notifications used by both apps |

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [How the AI Scoring Pipeline Works](#how-the-ai-scoring-pipeline-works)
- [Database Schema](#database-schema)
- [Route Map](#route-map)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Testing](#testing)
- [Related Repositories](#related-repositories)

---

## Features

- **Job search & discovery** — searchable, filterable vacancy listing (by keyword across title/location/company name, and by job type) with pagination, on the seeker dashboard.
- **Vacancy details page** — full description, location, salary, and type for a single opening.
- **Apply with an existing résumé or upload a new one** — at apply time, a job seeker can reuse a résumé they've already uploaded (skipping re-parsing entirely) or upload a new PDF on the spot.
- **AI-powered résumé screening**, fully asynchronous:
  - The application is saved and a response returned to the user **immediately** (`status: pending`, score `0`, no blocking on the AI call).
  - A queued job extracts structured résumé data (contact info, education, experience, skills) from the PDF — only when it's a *new* résumé; a reused résumé skips straight to scoring, saving an API call.
  - The résumé is then scored against the specific job description, producing a 0–100 compatibility score and 2–4 sentences of specific, evidence-based feedback.
  - Automatic retries with backoff on rate limits (`429`) or upstream errors (`503`); if analysis ultimately fails after all retries, a safe fallback message is stored instead of leaving the application stuck.
- **Applicant dashboard** — job seekers track the status (pending / accepted / rejected) and AI feedback for every application they've submitted, and can view the résumé attached to each one.
- **Notifications** — company owners and admins are notified when a new application comes in for their vacancy.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP ^8.2 |
| Frontend | Blade templates, Tailwind CSS 3, Alpine.js, Vite |
| Database | MariaDB / MySQL — UUID primary keys throughout |
| Auth | Laravel Breeze (session-based) |
| AI | Google Gemini (`gemini-2.5-flash`) via `google-gemini-php/laravel` + raw HTTP calls |
| PDF parsing | `smalot/pdfparser` |
| File storage | S3-compatible cloud storage (`league/flysystem-aws-s3-v3`, `aws/aws-sdk-php`) |
| Queues | Database-backed queue for background résumé analysis |
| Testing | Pest 4 |
| Shared domain layer | [`job/shared`](https://github.com/YoussefSayed-cs/job-shared) Composer package |

## How the AI Scoring Pipeline Works

```
User applies (PDF résumé)
        │
        ▼
JobVacancyController::processApplications()
   ├─ saves the Application immediately  (status: pending, score: 0)
   └─ dispatches ProcessResumeAnalysis   (queued job)
                    │
                    ▼
        ProcessResumeAnalysis  (tries: 3 · timeout: 300s · backoff: 60s → 120s → 180s)
                    │
                    ▼
        ResumesAnalysisServices
   ├─ new résumé?  → Gemini call #1: extract structured data from the PDF text
   │                 (skipped for a reused résumé — already have the data)
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
| The queued job itself (`ProcessResumeAnalysis`) | up to 3 attempts, 60s → 120s → 180s apart | Any uncaught exception (e.g. both inner retries exhausted, PDF unreadable, network failure) |

If every attempt at the job level is exhausted, `failed()` writes a safe fallback (`aiGeneratedFeedback: "AI evaluation is temporarily unavailable..."`) instead of leaving the application stuck at "in progress" forever.

Both Gemini prompts are constrained to return **raw JSON only** (no markdown fences, no invented data — the model is explicitly instructed not to fabricate skills or experience not present in the résumé), which the service then decodes directly into the fields stored on the `resumes` and `job_applications` tables.

## Database Schema

This app shares its schema with job-backoffice via the `job/shared` package — see that repo's README for the full table breakdown. The tables this app touches directly:

| Table | What this app writes to it |
|---|---|
| `resumes` | New résumé record on upload (`filename`, `fileUri`, `contactDetails`), later filled in by the queue job (`summary`, `skills`, `experience`, `education`) |
| `job_applications` | Created on apply (`status: pending`, `aiGeneratedScore: 0`), updated by the queue job with the final score and feedback, or by job-backoffice when a company owner accepts/rejects |
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
| GET | `/job-applications/{id}/resume` | `auth`, `role:job-seeker` | View a submitted résumé |
| GET/PATCH/DELETE | `/profile` | `auth`, `role:job-seeker` | Manage own profile |

Plus the standard Breeze auth routes (`/login`, `/register`, `/forgot-password`, email verification, etc.) from `routes/auth.php`.

## Project Structure

```
app/
├── Events/
│   └── JobApplicationSubmitted.php
├── Http/
│   ├── Controllers/          # DashboardController, JobVacancyController,
│   │                          # JobApplicationsController, ProfileController
│   ├── Middleware/            # RoleMiddleware
│   └── Requests/              # AbblyJobRequest (apply form validation), ProfileUpdateRequest
├── Jobs/
│   └── ProcessResumeAnalysis.php     # background AI scoring
├── Listeners/
│   └── NotifyCompanyOwner.php
├── Providers/
│   └── AppServiceProvider.php
└── Services/
    └── ResumesAnalysisServices.php   # Gemini integration (extraction + scoring)

resources/views/
├── dashboard.blade.php
├── job-vacancies/            # show, apply
├── welcome.blade.php
├── layouts/ & components/
└── auth/ & profile/

database/migrations/           # internal `jobs` (queue) table only — domain tables come from job-backoffice
routes/web.php
```

(Models — `User`, `company`, `job_vacancy`, `job_category`, `job_application`, `resume` — and the `newJobApply` notification live in the separate [`job/shared`](https://github.com/YoussefSayed-cs/job-shared) package.)

---

## Getting Started

### Prerequisites

- PHP >= 8.2
- Composer
- Node.js & npm
- MariaDB/MySQL
- A Google Gemini API key

### Installation

```bash
git clone https://github.com/YoussefSayed-cs/job-app.git
cd job-app

composer install
cp .env.example .env
php artisan key:generate

# configure your database and GEMINI_API_KEY in .env, then:
php artisan migrate

npm install
npm run build
```

### Running locally

```bash
composer dev
```
This single command runs four processes concurrently:

| Process | What it does |
|---|---|
| `php artisan serve` | The Laravel dev server |
| `php artisan queue:listen --tries=1` | **Required** — processes `ProcessResumeAnalysis`, without it applications stay at "pending" forever |
| `php artisan pail --timeout=0` | Live-tails the application log in your terminal |
| `npm run dev` | Vite dev server with hot module reload |

### ⚠️ Important — shared database

This app **does not ship migrations for the core domain tables** (users, companies, job vacancies, applications, résumés) — only the internal `jobs` queue table. It relies entirely on the shared models from `job/shared`, so its `.env` must point at the **same database** that [job-backoffice](https://github.com/YoussefSayed-cs/job-backoffice)'s migrations created (same `DB_HOST` / `DB_DATABASE` / credentials). Run job-backoffice's migrations first.

### Environment variables

| Variable | Purpose |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_URL`, `APP_DEBUG` | Standard Laravel app config |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection — **must match job-backoffice's `.env`**, see note above |
| `GEMINI_API_KEY` | Google Gemini API key, used for résumé parsing & scoring |
| `QUEUE_CONNECTION` | Queue driver (`database` by default) — **must be running** for AI scoring |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | Session storage (database-backed by default) |
| `CACHE_STORE` | Cache driver (`database` by default) |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Optional Redis config |
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
- [job-shared](https://github.com/YoussefSayed-cs/job-shared) — shared models and notifications package

## License

MIT
