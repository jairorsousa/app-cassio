<?php

namespace App\Http\Controllers;

use App\Domains\Assets\Models\Property;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PropertyAttachmentController extends Controller
{
    public function __invoke(Property $property): StreamedResponse
    {
        abort_unless($property->hasAttachment(), 404);

        return Storage::disk('local')->download($property->attachment_path, $property->attachment_original_name ?: 'anexo');
    }
}
