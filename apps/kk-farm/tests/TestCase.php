<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\Tenant::forget(); // every test has a new database session, so nothing bound earlier applies
    }
}
