<?php

namespace App\Console\Commands;

use App\Domains\Divisions\Services\DivisionService;
use Illuminate\Console\Command;

/** Backfill: participantes de Divisões sem celular recebem o do Contato do usuário ligado (não sobrescreve). */
class FillDivisionPhonesCommand extends Command
{
    protected $signature = 'divisions:fill-phones {--dry-run : Só lista, sem gravar}';

    protected $description = 'Preenche o celular dos participantes de Divisões a partir do Contato do usuário (só quem está sem número).';

    public function handle(DivisionService $service): int
    {
        if ($this->option('dry-run')) {
            $rows = \App\Domains\Divisions\Models\DivisionMember::whereNull('phone')->where('is_owner', false)->whereNotNull('user_id')->with('group')->get();
            foreach ($rows as $m) {
                $this->line("{$m->id} {$m->name} · {$m->group?->name}");
            }
            $this->info(count($rows).' candidato(s).');

            return self::SUCCESS;
        }
        $this->info($service->fillPhonesFromContacts().' participante(s) receberam celular.');

        return self::SUCCESS;
    }
}
