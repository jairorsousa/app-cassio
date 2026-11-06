<?php

namespace App\Console\Commands;

use App\Domains\Investments\Models\InvestmentImportBatch;
use App\Domains\Investments\Services\B3MidasImportService;
use App\Models\User;
use Illuminate\Console\Command;

/** Importação do export B3/Midas por linha de comando. Padrão = só prévia (não grava). Gravar exige --confirm e --user. */
class ImportB3MidasCommand extends Command
{
    protected $signature = 'investments:b3-import {dir : pasta com posicao.csv, proventos.csv e meta.json} {--base-date= : data-base da posição (AAAA-MM-DD); padrão meta.json} {--confirm : grava (somente após o Cassio aprovar a prévia)} {--user= : e-mail de quem confirmou}';

    protected $description = 'Prévia (padrão) ou importação idempotente da posição B3 e proventos do Midas, sem criar lançamentos no Financeiro';

    public function handle(B3MidasImportService $service): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/');
        // Arquivo ausente ou ilegível (ex.: permissão) não pode virar prévia "ok" vazia.
        if (! is_dir($dir) || ! is_readable($dir)) {
            $this->error("Pasta inexistente ou sem permissão de leitura: $dir");

            return self::FAILURE;
        }
        $read = function (string $name) use ($dir) {
            $path = "$dir/$name";
            if (! file_exists($path)) {
                return null;
            }
            $content = is_readable($path) ? file_get_contents($path) : false;
            if ($content === false) {
                throw new \RuntimeException("Sem permissão de leitura: $path");
            }

            return $content;
        };
        try {
            $pos = $read('posicao.csv');
            $inc = $read('proventos.csv');
            $metaRaw = $read('meta.json');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($pos === null && $inc === null) {
            $this->error("Nenhum posicao.csv ou proventos.csv encontrado em $dir");

            return self::FAILURE;
        }
        $meta = $metaRaw !== null ? json_decode($metaRaw, true) : null;
        if ($metaRaw !== null && ! is_array($meta)) {
            $this->error('meta.json inválido.');

            return self::FAILURE;
        }
        $base = $this->option('base-date') ?: ($meta['posicao_data_base'] ?? null);
        $preview = $service->preview($pos, $inc, $base, $meta);

        $this->line(json_encode(['ok' => $preview['ok'], 'errors' => $preview['errors'], 'warnings' => $preview['warnings'], 'summary' => $preview['summary']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (! $this->option('confirm')) {
            $this->info('Somente prévia: nada foi gravado.');

            return $preview['ok'] ? self::SUCCESS : self::FAILURE;
        }
        $user = User::where('email', (string) $this->option('user'))->first();
        if (! $user) {
            $this->error('Informe --user com o e-mail de quem aprovou a prévia.');

            return self::FAILURE;
        }
        $batch = InvestmentImportBatch::create([
            'source' => 'b3_midas', 'base_date' => $base, 'status' => 'preview', 'created_by' => $user->id,
            'position_file_name' => $pos !== null ? 'posicao.csv' : null, 'income_file_name' => $inc !== null ? 'proventos.csv' : null,
            'position_file_hash' => $pos !== null ? hash('sha256', $pos) : null, 'income_file_hash' => $inc !== null ? hash('sha256', $inc) : null,
            'summary' => $preview['summary'],
        ]);
        $created = $service->confirm($preview, $batch, $user->id);
        $this->info("Importação #{$batch->id} confirmada: {$created['positions']} posições e {$created['income']} proventos novos.");

        return self::SUCCESS;
    }
}
