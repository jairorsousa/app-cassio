<?php

namespace Tests;

/** Decide se o banco configurado é seguro para os testes (só SQLite em memória, ambiente testing, sem config em cache). */
final class TestDatabaseGuard
{
    public static function problem(string $default, array $connection, string $environment, bool $configCached): ?string
    {
        if ($configCached) {
            return 'a configuração está em cache (bootstrap/cache/config.php) e apontaria para o banco de produção.';
        }
        if ($environment !== 'testing') {
            return "APP_ENV é '{$environment}', esperado 'testing'.";
        }
        if (($connection['driver'] ?? '') !== 'sqlite' || ($connection['database'] ?? '') !== ':memory:') {
            return "conexão '{$default}' (".($connection['driver'] ?? '?').') não é SQLite em memória.';
        }

        return null;
    }
}
