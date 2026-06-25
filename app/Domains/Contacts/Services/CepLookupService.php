<?php

namespace App\Domains\Contacts\Services;

use Illuminate\Support\Facades\Http;

class CepLookupService
{
    /**
     * @return array{zip_code: string, street: string, complement: string, neighborhood: string, city: string, state: string}|null
     */
    public function lookup(string $zipCode): ?array
    {
        $digits = preg_replace('/\D+/', '', $zipCode) ?? '';

        if (strlen($digits) !== 8) {
            return null;
        }

        $response = Http::timeout(5)->get("https://viacep.com.br/ws/{$digits}/json/");

        if (! $response->successful() || $response->json('erro')) {
            return null;
        }

        return [
            'zip_code' => $this->formatZipCode($digits),
            'street' => (string) $response->json('logradouro', ''),
            'complement' => (string) $response->json('complemento', ''),
            'neighborhood' => (string) $response->json('bairro', ''),
            'city' => (string) $response->json('localidade', ''),
            'state' => (string) $response->json('uf', ''),
        ];
    }

    private function formatZipCode(string $digits): string
    {
        return substr($digits, 0, 5).'-'.substr($digits, 5);
    }
}
