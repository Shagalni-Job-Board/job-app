<?php

namespace Tests;

use App\Models\User;
use App\Models\company;
use App\Models\job_category;
use App\Models\job_vacancy;
use App\Models\resume;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected User $user;
    protected User $companyOwner;
    protected company $company;
    protected job_category $category;
    protected job_vacancy $vacancy;
    protected resume $resume;

    public function createApplication()
    {
        $app = parent::createApplication();

        $app->make('migrator')->path(base_path('../job-backoffice/database/migrations'));

        return $app;
    }
}
