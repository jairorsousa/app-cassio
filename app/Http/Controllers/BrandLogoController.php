<?php

namespace App\Http\Controllers;

use App\Support\BrandLogo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandLogoController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        abort_unless(BrandLogo::exists($user), 404);

        return response(Storage::disk('local')->get(BrandLogo::path($user)), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=31536000',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request)
    {
        $request->validateWithBag('logo', [
            'logo' => ['required', 'file', 'max:2048', 'mimetypes:image/png', 'dimensions:width='.BrandLogo::SIZE.',height='.BrandLogo::SIZE],
        ], [
            'logo.required' => 'Escolha uma imagem e ajuste antes de salvar.',
            'logo.max' => 'Imagem final grande demais (máx. 2 MB).',
            'logo.mimetypes' => 'Formato inválido. Use PNG, JPG ou WebP.',
            'logo.dimensions' => 'Imagem final inválida. Ajuste a imagem novamente e salve.',
        ]);

        // Reencoda no servidor (descarta metadados e qualquer conteúdo que não seja pixel).
        $img = @imagecreatefrompng($request->file('logo')->getRealPath());
        if (! $img) {
            return back()->withErrors(['logo' => 'Não foi possível ler a imagem.'], 'logo');
        }
        imagesavealpha($img, true);
        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);

        Storage::disk('local')->put(BrandLogo::path($request->user()), $png);

        return redirect()->route('settings', ['logo' => 'salvo']);
    }

    public function destroy(Request $request)
    {
        Storage::disk('local')->delete(BrandLogo::path($request->user()));

        return redirect()->route('settings', ['logo' => 'removido']);
    }
}
