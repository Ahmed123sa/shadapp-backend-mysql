<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every test gets a throwaway "public" disk. Without this, any test
        // that approves/sends a contract runs SendContractEmailNotification
        // (queue is sync), which writes contracts/contract-{id}-*.pdf into the
        // real storage/app/public — overwriting the dev database's real PDFs
        // with factory data (ids restart at 1 in the in-memory test DB), and
        // ensureFreshPdf() then serves that stale file.
        Storage::fake('public');
    }
}
