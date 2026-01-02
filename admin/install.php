<?php
/**
 * Instalador Automático do Sistema
 *
 * Este script cria o banco de dados e todas as tabelas necessárias.
 * Acesse: /admin/install.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('INSTALL_MODE', true);
require_once __DIR__ . '/../Database.php';

// Carrega configurações
$configFile = __DIR__ . '/../db_config.php';
if (!file_exists($configFile)) {
    die('<h1>Erro!</h1><p>Arquivo db_config.php não encontrado. Por favor, copie db_config.example.php para db_config.php e configure.</p>');
}

$dbConfig = require $configFile;

// Log de instalação
$log = [];
$errors = [];

/**
 * Adiciona mensagem ao log
 */
function addLog($message, $type = 'info') {
    global $log;
    $log[] = ['message' => $message, 'type' => $type];
}

/**
 * Adiciona erro
 */
function addError($message) {
    global $errors;
    $errors[] = $message;
    addLog($message, 'error');
}

/**
 * Cria o banco de dados se não existir
 */
function createDatabase($config) {
    try {
        // Conecta sem especificar o banco
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $config['host'],
            $config['port'],
            $config['charset']
        );

        $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);

        // Verifica se o banco existe
        $stmt = $pdo->query("SHOW DATABASES LIKE '{$config['database']}'");
        $exists = $stmt->rowCount() > 0;

        if (!$exists) {
            $pdo->exec("CREATE DATABASE `{$config['database']}` CHARACTER SET {$config['charset']} COLLATE utf8mb4_unicode_ci");
            addLog("✓ Banco de dados '{$config['database']}' criado com sucesso!", 'success');
        } else {
            addLog("✓ Banco de dados '{$config['database']}' já existe.", 'info');
        }

        return true;
    } catch (PDOException $e) {
        addError("✗ Erro ao criar banco de dados: " . $e->getMessage());
        return false;
    }
}

/**
 * Cria as tabelas do sistema
 */
function createTables() {
    try {
        $db = Database::getConnection();

        // Tabela de usuários
        $sql = "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(100) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `email` VARCHAR(255) NULL,
            `master_code` VARCHAR(255) NULL COMMENT 'Código mestre para acesso a qualquer promotor',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            `last_login` DATETIME NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_username` (`username`),
            INDEX `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
        addLog("✓ Tabela 'users' criada/verificada.", 'success');

        // Tabela de pagamentos
        $sql = "CREATE TABLE IF NOT EXISTS `payments` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `promoter` VARCHAR(255) NOT NULL,
            `month` VARCHAR(7) NOT NULL COMMENT 'Formato: YYYY-MM',
            `paid` TINYINT(1) NOT NULL DEFAULT 0,
            `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `vouchers` INT(11) NOT NULL DEFAULT 0,
            `paid_by` INT(11) UNSIGNED NULL,
            `paid_at` DATETIME NULL,
            `unmarked_by` INT(11) UNSIGNED NULL,
            `unmarked_at` DATETIME NULL,
            `notes` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_promoter_month` (`promoter`, `month`),
            INDEX `idx_promoter` (`promoter`),
            INDEX `idx_month` (`month`),
            INDEX `idx_paid` (`paid`),
            FOREIGN KEY (`paid_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`unmarked_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
        addLog("✓ Tabela 'payments' criada/verificada.", 'success');

        // Tabela de configurações
        $sql = "CREATE TABLE IF NOT EXISTS `config` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `key` VARCHAR(100) NOT NULL UNIQUE,
            `value` TEXT NOT NULL,
            `description` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_key` (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
        addLog("✓ Tabela 'config' criada/verificada.", 'success');

        // Tabela de sessões (para controle de timeout)
        $sql = "CREATE TABLE IF NOT EXISTS `sessions` (
            `id` VARCHAR(128) NOT NULL,
            `user_id` INT(11) UNSIGNED NULL,
            `data` TEXT NOT NULL,
            `last_activity` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_last_activity` (`last_activity`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
        addLog("✓ Tabela 'sessions' criada/verificada.", 'success');

        // Tabela de logs de auditoria
        $sql = "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT(11) UNSIGNED NULL,
            `action` VARCHAR(100) NOT NULL,
            `entity_type` VARCHAR(50) NULL,
            `entity_id` INT(11) NULL,
            `old_data` TEXT NULL,
            `new_data` TEXT NULL,
            `ip_address` VARCHAR(45) NULL,
            `user_agent` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_action` (`action`),
            INDEX `idx_created_at` (`created_at`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
        addLog("✓ Tabela 'audit_logs' criada/verificada.", 'success');

        // Tabela de vendas (importadas dos CSVs)
        $sql = "CREATE TABLE IF NOT EXISTS `sales` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `sale_item_id` VARCHAR(50) NOT NULL,
            `voucher_code` VARCHAR(50) NOT NULL,
            `voucher_status` VARCHAR(100) NULL,
            `origin_place` VARCHAR(100) NULL,
            `campaign_name` VARCHAR(255) NULL,
            `package_name` VARCHAR(255) NULL,
            `product_name` VARCHAR(255) NULL,
            `product_value` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            `sale_weekday` VARCHAR(20) NULL,
            `sale_datetime` DATETIME NULL,
            `visit_weekday` VARCHAR(20) NULL,
            `visit_date` DATE NULL,
            `manager` VARCHAR(255) NULL,
            `promoter` VARCHAR(255) NOT NULL,
            `visitor_name` VARCHAR(255) NULL,
            `visitor_document` VARCHAR(50) NULL,
            `visitor_email` VARCHAR(255) NULL,
            `visitor_birthdate` DATE NULL,
            `visitor_sex` VARCHAR(20) NULL,
            `visitor_address_street` VARCHAR(255) NULL,
            `visitor_address_number` VARCHAR(20) NULL,
            `visitor_address_burgh` VARCHAR(100) NULL,
            `visitor_mobile_phone` VARCHAR(50) NULL,
            `visitor_address_city` VARCHAR(100) NULL,
            `visitor_address_state` VARCHAR(50) NULL,
            `visitor_address_postal_code` VARCHAR(20) NULL,
            `visitor_address_country` VARCHAR(100) NULL,
            `dependencies_last_update_date` DATETIME NULL,
            `last_update_date` DATETIME NULL,
            `month_reference` VARCHAR(7) NOT NULL COMMENT 'Formato: YYYY-MM',
            `imported_by` INT(11) UNSIGNED NULL,
            `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_voucher_code` (`voucher_code`),
            INDEX `idx_promoter` (`promoter`),
            INDEX `idx_month_reference` (`month_reference`),
            INDEX `idx_sale_datetime` (`sale_datetime`),
            INDEX `idx_campaign_name` (`campaign_name`),
            INDEX `idx_sale_item_id` (`sale_item_id`),
            FOREIGN KEY (`imported_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
        addLog("✓ Tabela 'sales' criada/verificada.", 'success');

        // Tabela de promotores/consultores
        $sql = "CREATE TABLE IF NOT EXISTS `promoters` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            `title` VARCHAR(50) NULL,
            `commission_percentage` DECIMAL(5, 2) NULL DEFAULT 25.00,
            `status` ENUM('Ativo', 'Desativado') NOT NULL DEFAULT 'Ativo',
            `document` VARCHAR(20) NULL COMMENT 'CPF',
            `rg` VARCHAR(50) NULL,
            `street` VARCHAR(255) NULL,
            `number` VARCHAR(20) NULL,
            `complement` VARCHAR(100) NULL,
            `neighborhood` VARCHAR(100) NULL,
            `city` VARCHAR(100) NULL,
            `state` VARCHAR(2) NULL,
            `postal_code` VARCHAR(20) NULL,
            `mobile_phone` VARCHAR(20) NULL,
            `pin` VARCHAR(255) NULL COMMENT 'PIN criptografado para login rápido',
            `pin_attempts` INT(11) NOT NULL DEFAULT 0 COMMENT 'Tentativas de PIN falhadas',
            `pin_last_reset` DATETIME NULL COMMENT 'Última vez que o PIN foi resetado',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_name` (`name`),
            INDEX `idx_document` (`document`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
        addLog("✓ Tabela 'promoters' criada/verificada.", 'success');

        return true;
    } catch (PDOException $e) {
        addError("✗ Erro ao criar tabelas: " . $e->getMessage());
        return false;
    }
}

/**
 * Insere configurações padrão
 */
function insertDefaultConfig() {
    try {
        $db = Database::getConnection();

        $configs = [
            ['key' => 'admin_password', 'value' => password_hash('admin@2025', PASSWORD_BCRYPT), 'description' => 'Senha do modo admin (hash)'],
            ['key' => 'commission_percentage', 'value' => '0.25', 'description' => 'Percentual de comissão'],
            ['key' => 'app_name', 'value' => 'Sistema de Vendas', 'description' => 'Nome da aplicação'],
            ['key' => 'timezone', 'value' => 'America/Sao_Paulo', 'description' => 'Timezone do sistema'],
            ['key' => 'session_timeout', 'value' => '3600', 'description' => 'Timeout de sessão em segundos (1 hora)'],
            ['key' => 'installed', 'value' => '1', 'description' => 'Sistema instalado'],
            ['key' => 'install_date', 'value' => date('Y-m-d H:i:s'), 'description' => 'Data da instalação'],
        ];

        $stmt = $db->prepare("INSERT INTO config (`key`, `value`, `description`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");

        foreach ($configs as $config) {
            $stmt->execute([$config['key'], $config['value'], $config['description']]);
        }

        addLog("✓ Configurações padrão inseridas.", 'success');
        return true;
    } catch (PDOException $e) {
        addError("✗ Erro ao inserir configurações: " . $e->getMessage());
        return false;
    }
}

/**
 * Cria usuário admin padrão
 */
function createAdminUser() {
    try {
        $db = Database::getConnection();

        // Verifica se já existe admin
        $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE id = 1");
        $result = $stmt->fetch();

        if ($result['count'] == 0) {
            // Senha padrão: godmode@2025 (usuário deve alterar depois)
            $hashedPassword = password_hash('godmode@2025', PASSWORD_BCRYPT);

            $stmt = $db->prepare("INSERT INTO users (id, username, password, name, email, active) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([1, 'admin', $hashedPassword, 'Administrador', null, 1]);

            addLog("✓ Usuário admin criado (username: admin, senha: godmode@2025)", 'success');
            addLog("⚠ IMPORTANTE: Altere a senha padrão após o primeiro login!", 'warning');
        } else {
            addLog("✓ Usuário admin já existe.", 'info');
        }

        return true;
    } catch (PDOException $e) {
        addError("✗ Erro ao criar usuário admin: " . $e->getMessage());
        return false;
    }
}

/**
 * Migra dados dos arquivos JSON se existirem
 */
function migrateJsonData() {
    $migrated = false;

    // Migrar usuários
    $usersFile = __DIR__ . '/../data/users.json';
    if (file_exists($usersFile)) {
        try {
            $data = json_decode(file_get_contents($usersFile), true);
            $db = Database::getConnection();

            $stmt = $db->prepare("INSERT INTO users (id, username, password, name, active, created_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE username=VALUES(username), name=VALUES(name), active=VALUES(active)");

            foreach ($data['users'] as $user) {
                // Hash da senha se ainda não estiver
                $password = (strlen($user['password']) < 60)
                    ? password_hash($user['password'], PASSWORD_BCRYPT)
                    : $user['password'];

                $stmt->execute([
                    $user['id'],
                    $user['username'],
                    $password,
                    $user['name'],
                    $user['active'] ? 1 : 0,
                    $user['created_at']
                ]);
            }

            addLog("✓ " . count($data['users']) . " usuário(s) migrado(s) do JSON.", 'success');
            $migrated = true;
        } catch (Exception $e) {
            addError("✗ Erro ao migrar usuários: " . $e->getMessage());
        }
    }

    // Migrar comissões
    $commissionsFile = __DIR__ . '/../data/comissoes.json';
    if (file_exists($commissionsFile)) {
        try {
            $data = json_decode(file_get_contents($commissionsFile), true);
            $db = Database::getConnection();

            $stmt = $db->prepare("INSERT INTO payments (promoter, month, paid, paid_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE paid=VALUES(paid), paid_at=VALUES(paid_at)");

            $count = 0;
            foreach ($data['payments'] as $key => $payment) {
                list($promoter, $month) = explode('|', $key);

                $stmt->execute([
                    $promoter,
                    $month,
                    $payment['paid'] ? 1 : 0,
                    $payment['date'] ?? null
                ]);
                $count++;
            }

            if ($count > 0) {
                addLog("✓ {$count} pagamento(s) migrado(s) do JSON.", 'success');
                $migrated = true;
            }
        } catch (Exception $e) {
            addError("✗ Erro ao migrar comissões: " . $e->getMessage());
        }
    }

    if (!$migrated) {
        addLog("ℹ Nenhum arquivo JSON encontrado para migração.", 'info');
    }

    return true;
}

// ============================
// PROCESSAMENTO DA INSTALAÇÃO
// ============================

$success = true;

// 1. Criar banco de dados
if (!createDatabase($dbConfig)) {
    $success = false;
}

// 2. Criar tabelas
if ($success && !createTables()) {
    $success = false;
}

// 3. Inserir configurações
if ($success && !insertDefaultConfig()) {
    $success = false;
}

// 4. Criar usuário admin
if ($success && !createAdminUser()) {
    $success = false;
}

// 5. Migrar dados JSON
if ($success) {
    migrateJsonData();
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Sistema de Vendas</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            max-width: 900px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 40px;
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }
        .alert {
            border-radius: 10px;
            border: none;
            padding: 20px;
            margin-bottom: 20px;
        }
        .log-entry {
            padding: 12px 20px;
            margin-bottom: 10px;
            border-radius: 8px;
            border-left: 4px solid;
            background: #f8f9fa;
        }
        .log-success {
            border-color: #28a745;
            background: #d4edda;
            color: #155724;
        }
        .log-error {
            border-color: #dc3545;
            background: #f8d7da;
            color: #721c24;
        }
        .log-warning {
            border-color: #ffc107;
            background: #fff3cd;
            color: #856404;
        }
        .log-info {
            border-color: #17a2b8;
            background: #d1ecf1;
            color: #0c5460;
        }
        .btn {
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        .credentials {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        .credentials h4 {
            color: #856404;
            margin-bottom: 15px;
        }
        .credentials code {
            background: #fff;
            padding: 5px 10px;
            border-radius: 4px;
            color: #dc3545;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <i class="fas fa-database"></i> Instalação do Sistema
        </h1>

        <?php if ($success && empty($errors)): ?>
            <div class="alert alert-success">
                <h4><i class="fas fa-check-circle"></i> Instalação Concluída com Sucesso!</h4>
                <p>O sistema foi instalado e configurado corretamente.</p>
            </div>

            <div class="credentials">
                <h4><i class="fas fa-key"></i> Credenciais de Acesso</h4>
                <p><strong>Usuário:</strong> <code>admin</code></p>
                <p><strong>Senha:</strong> <code>godmode@2025</code></p>
                <p class="mb-0"><small><i class="fas fa-exclamation-triangle"></i> <strong>Importante:</strong> Altere a senha após o primeiro login!</small></p>
            </div>

            <div class="text-center mt-4">
                <a href="../index.php?godmode=on" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Acessar Sistema
                </a>
                <a href="install.php" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Reinstalar
                </a>
            </div>
        <?php elseif (!empty($errors)): ?>
            <div class="alert alert-danger">
                <h4><i class="fas fa-times-circle"></i> Instalação Falhou!</h4>
                <p>Ocorreram erros durante a instalação. Verifique os logs abaixo.</p>
            </div>
        <?php endif; ?>

        <h3 class="mt-4 mb-3"><i class="fas fa-list"></i> Log de Instalação</h3>
        <div class="log-container">
            <?php foreach ($log as $entry): ?>
                <div class="log-entry log-<?= htmlspecialchars($entry['type']) ?>">
                    <?= htmlspecialchars($entry['message']) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="text-center mt-4">
                <a href="install.php" class="btn btn-warning">
                    <i class="fas fa-redo"></i> Tentar Novamente
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
