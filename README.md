# Shagalni — Job App

This repository contains the job-seeker-facing application of the Shagalni platform. It is the public Laravel app where candidates browse vacancies, view details, submit applications, upload or reuse resumes, and track the status of each application.

## Purpose

The main purpose of this project is to help job seekers discover openings and apply to them from a simple web interface. The app also connects to the shared domain models from job-shared and uses a queued AI workflow to evaluate a submitted resume against a job vacancy.

## Main technologies

- PHP 8.2
- Laravel 12
- Laravel Breeze
- Blade + Tailwind CSS + Vite
- MariaDB / MySQL
- Google Gemini API
- AWS S3-compatible storage
- Pest for tests
- Composer package: job/shared

## Main features

- Public landing page showing recent companies
- Job seeker dashboard
- Vacancy details and application flow
- Resume upload or reuse
- Background AI analysis for resume-to-job matching
- Application status tracking
- Resume download from submitted applications
- Database notifications for company owners and admins

## Project structure

- app/Http/Controllers: request handling for the seeker flow
- app/Jobs: queued jobs such as resume analysis
- app/Services: AI and resume-processing logic
- app/Listeners: notification handling
- app/Providers: Laravel service registration
- resources/views: UI templates for the public and seeker pages
- routes/web.php: public and protected seeker routes
- database/migrations: local queue-related migration(s)

## Installation

1. Clone the repository:

   ```bash
   git clone https://github.com/YoussefSayed-cs/job-app.git
   cd job-app
   ```

2. Install PHP dependencies:

   ```bash
   composer install
   ```

3. Install frontend dependencies:

   ```bash
   npm install
   ```

4. Copy the environment file:

   ```bash
   cp .env.example .env
   ```

5. Generate the application key:

   ```bash
   php artisan key:generate
   ```

## Environment configuration

Edit the .env file and configure the required settings. The project includes the following important values in .env.example:

- APP_NAME, APP_ENV, APP_URL, APP_DEBUG
- DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
- SESSION_DRIVER and CACHE_STORE
- QUEUE_CONNECTION=database
- MAIL configuration
- AWS access and bucket values
- GEMINI_API_KEY

Important: this app shares the same database as job-backoffice. The core application tables are owned by the shared domain layer and the backoffice project, so the DB credentials and database name should be aligned across the two apps.

## Database setup and migrations

This repository does not define the full domain schema by itself. The main model tables are provided by the shared package and created by the backoffice repository. This app still uses Laravel migrations for local queue support.

To prepare the database:

```bash
php artisan migrate
```

If the shared schema has not been created yet, run the migrations in job-backoffice first and point both applications to the same database.

## Running locally

Use the project script:

```bash
composer dev
```

This runs the web server, queue worker, log viewer, and Vite frontend together. The queue worker is required because the resume-analysis process is handled asynchronously.

You can also start the app manually:

```bash
php artisan serve
php artisan queue:listen --tries=1
npm run dev
```

## Requirements

- PHP ^8.2
- Composer
- Node.js and npm
- MariaDB or MySQL
- Google Gemini API key
- AWS S3-compatible bucket for resume storage
- job-shared Composer package and job-backoffice project available in the same platform setup

## Related repositories

- job-backoffice: backoffice and admin dashboard for the platform
- job-shared: shared models and notification definitions used by both apps
