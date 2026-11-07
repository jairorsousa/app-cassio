<?php

namespace Tests\Feature;

use App\Support\BrazilianDocuments;
use Tests\TestCase;

class PtBrMaskTest extends TestCase
{
    public function test_translations_and_pagination_in_portuguese(): void
    {
        app()->setLocale('pt_BR');
        $this->assertSame('Anterior', __('Previous'));
        $this->assertSame('Mostrando', __('Showing'));
        $this->assertSame('O campo nome é obrigatório.', __('validation.required', ['attribute' => 'nome']));
        $p = new \Illuminate\Pagination\LengthAwarePaginator(range(1, 10), 50, 10, 2, ['path' => '/x']);
        $html = (string) $p->links();
        $this->assertStringContainsString('Mostrando', $html);
        $this->assertStringNotContainsString('Showing', $html);
    }

    public function test_cpf_masked_for_lists(): void
    {
        $this->assertSame('123.xxx.xxx-09', BrazilianDocuments::maskForList('123.456.789-09'));
        $this->assertSame('12.345.678/0001-95', BrazilianDocuments::maskForList('12345678000195'));
        $this->assertSame('', BrazilianDocuments::maskForList(null));
    }
}
