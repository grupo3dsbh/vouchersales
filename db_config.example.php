<?php
/**
 * Configuração do Banco de Dados
 *
 * INSTRUÇÕES:
 * 1. Renomeie este arquivo para db_config.php
 * 2. Preencha os dados do seu banco de dados abaixo
 * 3. Acesse /admin/install.php para criar as tabelas automaticamente
 */

return [
    'host' => 'localhost',
    'database' => 'vouchersales',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'port' => 3306,

    // Configurações opcionais
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];
