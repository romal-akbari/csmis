<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\EnsureProductionWorkflow;

/**
 * Exercise retained document handlers and their historical fixtures in isolation.
 * The operational API rejects these writes; CompanyProductionWorkflowApiTest tests that boundary with middleware enabled.
 */
abstract class HistoricalApiTestCase extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureProductionWorkflow::class);
    }
}
