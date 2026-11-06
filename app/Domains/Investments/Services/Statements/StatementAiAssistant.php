<?php

namespace App\Domains\Investments\Services\Statements;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IA só SUGERE o mapeamento de colunas (nunca grava, nunca confirma). Envia apenas cabeçalhos + até 5 linhas de amostra.
 * Sem STATEMENT_AI_* configurado: não envia nada e devolve null (fluxo segue manual).
 * Endpoint compatível com chat/completions (OpenAI-like).
 */
class StatementAiAssistant
{
    public function enabled(): bool
    {
        return filled(config('services.statement_ai.key')) && filled(config('services.statement_ai.url')) && filled(config('services.statement_ai.model'));
    }

    public function provider(): ?string
    {
        return $this->enabled() ? (string) (config('services.statement_ai.provider') ?: parse_url((string) config('services.statement_ai.url'), PHP_URL_HOST)) : null;
    }

    /** @return array{mapping: array<string,string>, notes: string}|null */
    public function suggestMapping(array $headers, array $sampleRows): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $fields = implode(', ', array_keys(BrokerStatementParser::FIELDS));
        $prompt = "Extrato de corretora brasileira. Mapeie cada campo para o nome exato de uma coluna do cabeçalho, ou omita. Campos: $fields. Responda só JSON {\"mapping\":{campo:coluna},\"notes\":\"...\"}.\nCabeçalho: ".json_encode($headers, JSON_UNESCAPED_UNICODE)."\nAmostra: ".json_encode(array_slice($sampleRows, 0, 5), JSON_UNESCAPED_UNICODE);
        try {
            $res = Http::timeout(30)->withToken((string) config('services.statement_ai.key'))->post((string) config('services.statement_ai.url'), [
                'model' => config('services.statement_ai.model'),
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0,
            ]);
            if (! $res->successful()) {
                Log::warning('Statement AI request failed.', ['status' => $res->status()]);

                return null;
            }
            $content = (string) $res->json('choices.0.message.content');
            if (preg_match('/\{.*\}/s', $content, $m)) {
                $json = json_decode($m[0], true);
            }
            $mapping = array_filter((array) ($json['mapping'] ?? []), fn ($col, $field) => isset(BrokerStatementParser::FIELDS[$field]) && in_array($col, $headers, true), ARRAY_FILTER_USE_BOTH);

            return ['mapping' => $mapping, 'notes' => mb_substr((string) ($json['notes'] ?? ''), 0, 500)];
        } catch (\Throwable $e) {
            Log::warning('Statement AI request failed.', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
