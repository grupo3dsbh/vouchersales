<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Verifica se usuário está autenticado e é admin ou superadmin
if (!isset($_SESSION['godmode_user_role']) || !in_array($_SESSION['godmode_user_role'], ['admin', 'superadmin'])) {
    die('<h1>Acesso Negado</h1><p>Apenas administradores podem configurar debug.</p><p><a href="../index.php?admin=1&godmode=on">← Voltar</a></p>');
}

$db = Database::getConnection();
$success_msg = '';
$error_msg = '';

// Cria tabela se não existir
try {
    $sql = "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
} catch (Exception $e) {
    // Tabela já existe
}

// Salva configuração
if (isset($_POST['save_debug'])) {
    $debug_enabled = isset($_POST['debug_enabled']) ? '1' : '0';
    $debug_console = isset($_POST['debug_console']) ? '1' : '0';
    $debug_error_log = isset($_POST['debug_error_log']) ? '1' : '0';

    try {
        $sql = "INSERT INTO system_settings (setting_key, setting_value)
                VALUES ('debug_enabled', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$debug_enabled]);

        $sql = "INSERT INTO system_settings (setting_key, setting_value)
                VALUES ('debug_console', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$debug_console]);

        $sql = "INSERT INTO system_settings (setting_key, setting_value)
                VALUES ('debug_error_log', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$debug_error_log]);

        $success_msg = "✅ Configurações de debug salvas com sucesso!";
    } catch (Exception $e) {
        $error_msg = "Erro ao salvar: " . $e->getMessage();
    }
}

// Busca configurações atuais
function getDebugSetting($key, $default = '0') {
    global $db;
    try {
        $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (Exception $e) {
        return $default;
    }
}

$debug_enabled = getDebugSetting('debug_enabled', '0');
$debug_console = getDebugSetting('debug_console', '1');
$debug_error_log = getDebugSetting('debug_error_log', '1');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações de Debug</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .settings-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .debug-switch {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        .debug-switch:hover {
            background: #e9ecef;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #28a745;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 14px;
        }
        .status-on {
            background: #d4edda;
            color: #155724;
        }
        .status-off {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="settings-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h1><i class="fas fa-bug"></i> Configurações de Debug</h1>
                <a href="../index.php?admin=1&godmode=on" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if ($success_msg): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success_msg ?>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= $error_msg ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <h5><i class="fas fa-info-circle"></i> Sobre o Debug</h5>
                <ul>
                    <li><strong>Debug Geral:</strong> Ativa/desativa todo o sistema de debug</li>
                    <li><strong>Console do Navegador:</strong> Mostra logs no console (F12)</li>
                    <li><strong>Logs do Servidor:</strong> Grava logs em /var/log/apache2/error.log</li>
                </ul>
                <p class="mb-0"><strong>⚠️ Atenção:</strong> Desative o debug em produção para melhor performance!</p>
            </div>

            <form method="POST">
                <div class="settings-card" style="background: #f8f9fa;">
                    <h4 style="margin-bottom: 20px;">
                        <i class="fas fa-toggle-on"></i> Status Atual
                        <?php if ($debug_enabled == '1'): ?>
                            <span class="status-badge status-on">🟢 DEBUG ATIVADO</span>
                        <?php else: ?>
                            <span class="status-badge status-off">🔴 DEBUG DESATIVADO</span>
                        <?php endif; ?>
                    </h4>

                    <div class="debug-switch">
                        <div>
                            <strong><i class="fas fa-power-off"></i> Debug Geral</strong>
                            <br>
                            <small class="text-muted">Ativa/desativa todo o sistema de debug</small>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="debug_enabled" <?= $debug_enabled == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="debug-switch">
                        <div>
                            <strong><i class="fas fa-terminal"></i> Debug no Console</strong>
                            <br>
                            <small class="text-muted">Mostra console.log() no navegador (F12)</small>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="debug_console" <?= $debug_console == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="debug-switch">
                        <div>
                            <strong><i class="fas fa-file-alt"></i> Debug em error.log</strong>
                            <br>
                            <small class="text-muted">Grava error_log() no servidor</small>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="debug_error_log" <?= $debug_error_log == '1' ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" name="save_debug" class="btn btn-success btn-lg">
                        <i class="fas fa-save"></i> Salvar Configurações
                    </button>
                </div>
            </form>
        </div>

        <div class="settings-card">
            <h4><i class="fas fa-tools"></i> Ferramentas de Diagnóstico</h4>
            <div class="row mt-3">
                <div class="col-md-4">
                    <a href="test_query.php" class="btn btn-primary btn-block">
                        <i class="fas fa-vial"></i> Testar Queries
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="diagnostico_comissoes.php" class="btn btn-info btn-block">
                        <i class="fas fa-stethoscope"></i> Diagnóstico Completo
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="fix_commission_null.php" class="btn btn-warning btn-block">
                        <i class="fas fa-wrench"></i> Migração NULL → __ALL__
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
