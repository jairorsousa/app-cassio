<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        // A raiz redireciona para o login (visitante) ou para o Dashboard (autenticado).
        $response->assertRedirect(route('login', absolute: false));
    }
}
