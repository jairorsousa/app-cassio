<?php

namespace Tests\Feature\Livewire;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FileUploadSignatureTest extends TestCase
{
    public function test_livewire_upload_accepts_signed_url_generated_from_app_url(): void
    {
        config(['app.url' => 'https://app.cassiomota.com']);

        URL::forceRootUrl('https://app.cassiomota.com');
        URL::forceScheme('https');

        Storage::fake('tmp-for-tests');

        $uploadUrl = URL::temporarySignedRoute(
            'livewire.upload-file',
            now()->addMinutes(5)
        );

        $response = $this
            ->withServerVariables([
                'HTTPS' => 'on',
                'HTTP_HOST' => 'app.cassiomota.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'app.cassiomota.com',
            ])
            ->post(parse_url($uploadUrl, PHP_URL_PATH) . '?' . parse_url($uploadUrl, PHP_URL_QUERY), [
                'files' => [UploadedFile::fake()->image('broadcast.jpg')],
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['paths']);
    }

    public function test_livewire_upload_rejects_unsigned_requests(): void
    {
        $response = $this->post('/livewire/upload-file', [
            'files' => [UploadedFile::fake()->image('broadcast.jpg')],
        ]);

        $response->assertUnauthorized();
    }
}