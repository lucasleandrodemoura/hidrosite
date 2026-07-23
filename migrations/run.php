<?php
declare(strict_types=1);

/**
 * Executa as migrações do banco de dados.
 * Uso: php migrations/run.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Bootstrap.php';

use ValeTaquari\Bootstrap;
use ValeTaquari\Database;

$cfg = Bootstrap::init();
$pdo = Database::get($cfg['db']);

echo "=== Vale Taquari Tempo – Migração ===\n";
echo "Driver: {$cfg['db']['driver']}\n\n";

// Seleciona o schema correto conforme o driver
$schemaFile = $cfg['db']['driver'] === 'pgsql'
    ? __DIR__ . '/001_schema_pgsql.sql'
    : __DIR__ . '/001_schema.sql';

$sql = file_get_contents($schemaFile);
if ($sql === false) {
    echo "ERRO: não foi possível ler {$schemaFile}\n";
    exit(1);
}

// Executa cada statement separadamente (compatível com ambos drivers)
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => $s !== ''
);

foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
    } catch (\PDOException $e) {
        // Ignora erros de "já existe" (IF NOT EXISTS pode não funcionar em todos os contextos)
        if (!str_contains($e->getMessage(), 'already exists')) {
            echo "AVISO: " . $e->getMessage() . "\n";
        }
    }
}

echo "Tabelas criadas/verificadas.\n\n";

// Popula estações a partir da configuração
$insertSql = $cfg['db']['driver'] === 'pgsql'
    ? 'INSERT INTO estacoes (id, nome, tipo) VALUES (:id, :nome, :tipo) ON CONFLICT (id) DO NOTHING'
    : 'INSERT OR IGNORE INTO estacoes (id, nome, tipo) VALUES (:id, :nome, :tipo)';

$stmt = $pdo->prepare($insertSql);

foreach ($cfg['estacoes'] as $tipo => $lista) {
    foreach ($lista as $id => $nome) {
        $stmt->execute([':id' => $id, ':nome' => $nome, ':tipo' => $tipo]);
        echo "  [{$tipo}] {$nome} ({$id})\n";
    }
}

echo "\nMigração concluída com sucesso.\n";
