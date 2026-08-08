<?php

namespace Tests;

use App\Models\Site;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Site::registry() memoizes into a static property, which outlives a test
     * the way RefreshDatabase's rollback does not: a site created or edited in
     * one test would still be resolving hosts in the next, long after its row
     * was rolled back.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Site::flushRegistry();
    }
}
