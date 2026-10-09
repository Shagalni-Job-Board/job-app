<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a unique constraint to prevent duplicate job applications.
     *
     * Because the job_applications table uses SoftDeletes, a plain
     * UNIQUE(userID, jobVacancyID) would block a user from re-applying
     * after a soft-deleted application is restored or removed.
     *
     * We use a partial unique index (WHERE deleted_at IS NULL) where it is
     * supported. MySQL/MariaDB use a generated column that is NULL for a
     * soft-deleted row; unique indexes permit multiple NULL values.
     */
    public function up(): void
    {
        if (!Schema::hasTable('job_applications')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'])) {
            DB::statement('
                CREATE UNIQUE INDEX IF NOT EXISTS job_applications_user_vacancy_unique
                ON job_applications (userID, jobVacancyID)
                WHERE deleted_at IS NULL
            ');
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('
                ALTER TABLE job_applications
                ADD COLUMN activeApplicationUserID CHAR(36)
                GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN userID ELSE NULL END) STORED,
                ADD UNIQUE INDEX job_applications_user_vacancy_unique (jobVacancyID, activeApplicationUserID)
            ');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('job_applications')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'])) {
            DB::statement('DROP INDEX IF EXISTS job_applications_user_vacancy_unique');
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE job_applications DROP INDEX job_applications_user_vacancy_unique, DROP COLUMN activeApplicationUserID');
        }
    }
};
