<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_swagger_ui_and_openapi_document_are_served_by_the_api(): void
    {
        $this->get('/api/docs')
            ->assertOk()
            ->assertSee('/api/docs/openapi.yaml')
            ->assertSee('/api/docs/assets/swagger-ui.css');

        $this->get('/api/docs/assets/swagger-ui.css')
            ->assertOk()
            ->assertHeader('content-type', 'text/css; charset=UTF-8');

        $this->get('/api/docs/openapi.yaml')
            ->assertOk()
            ->assertHeader('content-type', 'application/yaml; charset=UTF-8');
    }
}
