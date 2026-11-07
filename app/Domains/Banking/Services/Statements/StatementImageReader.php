<?php

namespace App\Domains\Banking\Services\Statements;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Foto de extrato/fatura lida por IA (endpoint compatível com chat/completions com imagem). Só lê. */
class StatementImageReader
{
    public function enabled(): bool
    {
        return filled(config('services.statement_ai.key')) && filled(config('services.statement_ai.url')) && filled(config('services.statement_ai.model'));
    }

    /** @return list<array{date: string, description: string, amount: float, balance: ?float}> */
    public function read(string $path, string $ext): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('A leitura de foto precisa da IA configurada (STATEMENT_AI_URL, STATEMENT_AI_KEY e STATEMENT_AI_MODEL). Enquanto isso, envie CSV, XLSX ou PDF.');
        }
        $mime = match ($ext) { 'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg' };
        $prompt = 'Extraia os lançamentos deste extrato bancário ou fatura de cartão brasileira. Responda só JSON {"rows":[{"date":"AAAA-MM-DD","description":"...","amount":-123.45}]}, com amount negativo para saídas/compras e positivo para entradas. Não invente linhas.';
        $res = Http::timeout(90)->withToken((string) config('services.statement_ai.key'))->post((string) config('services.statement_ai.url'), [
            'model' => config('services.statement_ai.model'),
            'temperature' => 0,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path))]],
            ]]],
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('A IA não conseguiu ler a foto agora (HTTP '.$res->status().'). Tente de novo ou envie CSV/XLSX/PDF.');
        }
        $content = (string) $res->json('choices.0.message.content');
        $json = preg_match('/\{.*\}/s', $content, $m) ? json_decode($m[0], true) : null;
        $rows = [];
        foreach ((array) ($json['rows'] ?? []) as $r) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['date'] ?? '')) || ! is_numeric($r['amount'] ?? null)) {
                continue;
            }
            $rows[] = ['date' => $r['date'], 'description' => mb_substr(trim((string) ($r['description'] ?? '')), 0, 255) ?: 'Sem descrição', 'amount' => round((float) $r['amount'], 2), 'balance' => null];
        }
        if ($rows === []) {
            throw new RuntimeException('Não encontrei lançamentos na foto.');
        }

        return array_slice($rows, 0, GenericStatementReader::MAX_ROWS);
    }
}
