<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Trava de segurança: os testes só rodam no SQLite em memória.
     * Se a configuração apontar para outro banco (ex.: config em cache de produção), aborta antes de qualquer migração.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $problem = TestDatabaseGuard::problem(
            (string) $app['config']->get('database.default'),
            (array) $app['config']->get('database.connections.'.$app['config']->get('database.default'), []),
            (string) $app->environment(),
            $app->configurationIsCached(),
        );
        if ($problem !== null) {
            fwrite(STDERR, "\n[ABORTADO] Testes bloqueados: {$problem}\n");
            exit(97);
        }

        return $app;
    }
}
