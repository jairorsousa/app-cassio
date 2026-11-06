<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/** Leitura de contatos por etiqueta no Chatwoot (somente leitura). */
class ChatwootContacts
{
    public string $error = '';

    private function request()
    {
        return Http::withHeaders(['api_access_token' => (string) config('services.chatwoot.api_access_token'), 'Content-Type' => 'application/json'])->timeout(20);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.chatwoot.base_url'), '/').'/api/v1/accounts/'.config('services.chatwoot.account_id').'/'.ltrim($path, '/');
    }

    /** Todos os contatos de UMA etiqueta (15 por página). null = falha. */
    public function forLabel(string $label): ?array
    {
        $this->error = '';
        $out = [];
        $page = 1;
        try {
            do {
                $response = $this->request()->post($this->url('contacts/filter').'?page='.$page, [
                    'payload' => [['attribute_key' => 'labels', 'filter_operator' => 'equal_to', 'values' => [$label], 'query_operator' => null]],
                ]);
                if (! $response->successful()) {
                    $this->error = 'Falha ao carregar contatos da etiqueta '.$label.'.';

                    return null;
                }
                $pageContacts = $response->json('payload') ?? [];
                foreach ($pageContacts as $c) {
                    $out[] = ['id' => $c['id'] ?? null, 'name' => $c['name'] ?? null, 'phone_number' => $c['phone_number'] ?? null];
                }
                $page++;
            } while (count($pageContacts) >= 15 && $page <= 200);
        } catch (\Exception $e) {
            $this->error = 'Erro ao conectar com Chatwoot.';

            return null;
        }

        return $out;
    }
}
