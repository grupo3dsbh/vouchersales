<?php
session_start();
require_once '../functions.php';

// Verifica permissão
if (!canEdit()) {
    die('Acesso negado!');
}

$db = Database::getConnection();

// Salva configuração
if (isset($_POST['save_settings'])) {
    $storage_method = $_POST['storage_method'] ?? 'file';

    $sql = "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);

    $sql = "INSERT INTO system_settings (setting_key, setting_value)
            VALUES ('receipt_storage_method', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    $stmt = $db->prepare($sql);
    $stmt->execute([$storage_method]);

    $success_msg = "Configuração salva com sucesso!";
}

// Busca configuração atual
$sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'receipt_storage_method'";
$result = $db->query($sql);
$current_method = $result ? $result->fetchColumn() : 'file';

if (!$current_method) {
    $current_method = 'file';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Configurações de Comprovantes</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body style="background: #f8f9fa; padding: 20px;">
    <div class="container">
        <h2><i class="fas fa-cog"></i> Configurações de Comprovantes</h2>

        <?php if (isset($success_msg)): ?>
            <div class="alert alert-success"><?= $success_msg ?></div>
        <?php endif; ?>

        <div class="card mt-4">
            <div class="card-body">
                <h5>Método de Armazenamento</h5>
                <p class="text-muted">Define como os comprovantes serão armazenados no sistema</p>

                <form method="POST">
                    <div class="form-group">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="storage_method" id="method_file" value="file" <?= $current_method === 'file' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="method_file">
                                <strong><i class="fas fa-folder"></i> Arquivo no Servidor</strong>
                                <br><small class="text-muted">Salva arquivos na pasta /receipts/ (recomendado para arquivos grandes)</small>
                            </label>
                        </div>

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="radio" name="storage_method" id="method_base64" value="base64" <?= $current_method === 'base64' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="method_base64">
                                <strong><i class="fas fa-database"></i> Base64 no Banco de Dados</strong>
                                <br><small class="text-muted">Salva como texto no banco (melhor para backups, mas ocupa mais espaço)</small>
                            </label>
                        </div>
                    </div>

                    <button type="submit" name="save_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar Configuração
                    </button>
                    <a href="../index.php?godmode=on" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </form>
            </div>
        </div>

        <div class="alert alert-info mt-4">
            <h6><i class="fas fa-info-circle"></i> Como funciona:</h6>
            <ul>
                <li><strong>Arquivo:</strong> Comprovantes salvos em /receipts/YYYY-MM/promotor_mes.ext</li>
                <li><strong>Base64:</strong> Comprovantes convertidos em texto e salvos no banco</li>
            </ul>
            <p class="mb-0"><strong>Nota:</strong> Esta configuração se aplica a todos os novos uploads. Comprovantes já enviados mantêm seu método original.</p>
        </div>
    </div>
</body>
</html>
