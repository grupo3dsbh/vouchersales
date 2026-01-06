<?php
/**
 * Gerenciamento de Comissões Mensais
 *
 * Permite configurar comissão individual por promotor/mês
 * Suporta: percentual (%) ou valor fixo (R$)
 *
 * ACESSE: /admin/manage_commissions.php
 */

session_start();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../functions.php';

// Verifica se usuário está autenticado e é admin ou superadmin
if (!isset($_SESSION['godmode_user_role']) || !in_array($_SESSION['godmode_user_role'], ['admin', 'superadmin'])) {
    die('<h1>Acesso Negado</h1><p>Apenas administradores podem gerenciar comissões.</p><p><a href="../index.php?admin=1&godmode=on">← Voltar</a></p>');
}

$db = Database::getConnection();

// Cria/atualiza tabela com suporte para valor fixo
$db->exec("CREATE TABLE IF NOT EXISTS `promoter_commission_history` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `promoter_name` VARCHAR(255) NOT NULL,
    `month_reference` VARCHAR(7) NOT NULL,
    `commission_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
    `commission_value` DECIMAL(10, 2) NOT NULL COMMENT 'Percentual (ex: 25.00) ou valor fixo (ex: 50.00)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_promoter_month` (`promoter_name`, `month_reference`),
    INDEX `idx_promoter` (`promoter_name`),
    INDEX `idx_month` (`month_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Adiciona coluna commission_type se não existir
try {
    $db->exec("ALTER TABLE promoter_commission_history
               ADD COLUMN commission_type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage'
               AFTER month_reference");
} catch (Exception $e) {
    // Coluna já existe
}

// Renomeia coluna antiga se existir
try {
    $db->exec("ALTER TABLE promoter_commission_history
               CHANGE commission_percentage commission_value DECIMAL(10, 2) NOT NULL");
} catch (Exception $e) {
    // Já foi renomeada
}

// Salvar/Atualizar comissão
if (isset($_POST['save_commission'])) {
    $promoter = $_POST['promoter'] ?? '';
    $month = $_POST['month'] ?? '';
    $type = $_POST['commission_type'] ?? 'percentage';
    $value = floatval($_POST['commission_value'] ?? 0);
    $edit_id = intval($_POST['edit_id'] ?? 0);

    // Permite "TODOS" (usa '__ALL__' ao invés de NULL)
    $promoterName = ($promoter === 'TODOS' || empty($promoter)) ? '__ALL__' : $promoter;

    if (!empty($month) && $value > 0) {
        try {
            if ($edit_id > 0) {
                // ATUALIZAR comissão existente
                $sql = "UPDATE promoter_commission_history
                        SET promoter_name = ?, month_reference = ?, commission_type = ?, commission_value = ?
                        WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$promoterName, $month, $type, $value, $edit_id]);

                $success_msg = "✅ Comissão atualizada com sucesso!";
            } else {
                // INSERIR nova comissão
                $sql = "INSERT INTO promoter_commission_history (promoter_name, month_reference, commission_type, commission_value)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            commission_type = VALUES(commission_type),
                            commission_value = VALUES(commission_value)";
                $stmt = $db->prepare($sql);
                $stmt->execute([$promoterName, $month, $type, $value]);

                if ($promoterName === '__ALL__') {
                    $success_msg = "✅ Comissão GERAL salva com sucesso! Todos os consultores usarão esta comissão para $month (exceto se tiverem comissão específica).";
                } else {
                    $success_msg = "✅ Comissão específica salva para $promoterName no mês $month!";
                }
            }
        } catch (Exception $e) {
            $error_msg = "Erro ao salvar comissão: " . $e->getMessage();
        }
    } else {
        $error_msg = "Preencha o mês e o valor da comissão!";
    }
}

// Deletar comissão
if (isset($_POST['delete_commission'])) {
    $id = intval($_POST['commission_id'] ?? 0);
    if ($id > 0) {
        try {
            $db->prepare("DELETE FROM promoter_commission_history WHERE id = ?")->execute([$id]);
            $success_msg = "Comissão removida!";
        } catch (Exception $e) {
            $error_msg = "Erro ao remover: " . $e->getMessage();
        }
    }
}

// Busca promotores
$promoters = $db->query("SELECT DISTINCT promoter FROM sales ORDER BY promoter")->fetchAll(PDO::FETCH_COLUMN);

// Busca meses disponíveis
$months = $db->query("SELECT DISTINCT month_reference FROM sales ORDER BY month_reference DESC")->fetchAll(PDO::FETCH_COLUMN);

// Busca comissões configuradas
$commissions = $db->query("SELECT pch.*, p.commission_percentage as default_percentage
                           FROM promoter_commission_history pch
                           LEFT JOIN promoters p ON pch.promoter_name = p.name
                           ORDER BY pch.month_reference DESC, pch.promoter_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Comissões</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #667eea; border-bottom: 3px solid #667eea; padding-bottom: 15px; margin-bottom: 25px; }
        .form-section { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        .commission-table { margin-top: 20px; }
        .badge-percentage { background: #17a2b8; }
        .badge-fixed { background: #28a745; }
    </style>
</head>
<body>
<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <h1><i class="fas fa-percent"></i> Gerenciar Comissões Mensais</h1>
        <div>
            <a href="diagnostico_comissoes.php" class="btn btn-info" title="Ver diagnóstico detalhado do banco">
                <i class="fas fa-stethoscope"></i> Diagnóstico
            </a>
            <a href="../index.php?admin=1&godmode=on" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
    </div>

    <?php if (isset($success_msg)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success_msg ?></div>
    <?php endif; ?>

    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $error_msg ?></div>
    <?php endif; ?>

    <!-- Formulário -->
    <div class="form-section">
        <h4 style="margin-bottom: 20px;"><i class="fas fa-plus-circle"></i> Definir Comissão</h4>

        <div class="alert alert-info" style="margin-bottom: 20px;">
            <h5 style="margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Como Funciona:</h5>
            <ul style="margin: 0; padding-left: 20px;">
                <li><strong>Percentual (%):</strong> Comissão calculada sobre o VALOR TOTAL de vendas do mês</li>
                <li><strong>Valor Fixo (R$):</strong> Comissão calculada POR VENDA (Ex: R$10 por venda x 5 vendas = R$50)</li>
            </ul>
            <hr style="margin: 10px 0;">
            <p style="margin: 0;"><strong>📌 Prioridade:</strong> Comissão específica → Comissão geral do mês → Cadastro do promotor → Padrão (25%)</p>
        </div>

        <form method="POST" id="commission_form">
            <input type="hidden" name="edit_id" id="edit_id" value="">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Promotor:</label>
                        <select name="promoter" id="promoter" class="form-control" required>
                            <option value="">Selecione...</option>
                            <option value="TODOS" style="background: #fff3cd; font-weight: bold;">🌟 -- Todos os Consultores --</option>
                            <option disabled>────────────────</option>
                            <?php foreach ($promoters as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            <strong>Todos:</strong> Define comissão padrão do mês<br>
                            <strong>Individual:</strong> Sobrescreve o padrão para o promotor
                        </small>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Mês:</label>
                        <select name="month" id="month" class="form-control" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($months as $m): ?>
                                <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Tipo:</label>
                        <select name="commission_type" class="form-control" id="commission_type" required onchange="updateLabel()">
                            <option value="percentage">Percentual (%)</option>
                            <option value="fixed">Valor Fixo (R$)</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="form-group">
                        <label id="value_label"><i class="fas fa-percent"></i> Valor (%):</label>
                        <input type="number" name="commission_value" id="commission_value" class="form-control" step="0.01" min="0" required placeholder="Ex: 25.00">
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" name="save_commission" class="btn btn-primary btn-block" id="save_btn">
                            <i class="fas fa-save"></i> <span id="btn_text">Salvar</span>
                        </button>
                        <button type="button" class="btn btn-secondary btn-block" id="cancel_btn" style="display:none;" onclick="cancelEdit()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Tabela de Comissões -->
    <div class="commission-table">
        <h4><i class="fas fa-list"></i> Comissões Configuradas (<?= count($commissions) ?>)</h4>

        <?php if (empty($commissions)): ?>
            <div class="alert alert-info mt-3">
                <i class="fas fa-info-circle"></i> Nenhuma comissão personalizada configurada.
                Os promotores usarão o percentual padrão (25% ou o definido no cadastro).
            </div>
        <?php else: ?>
            <table class="table table-striped mt-3">
                <thead>
                    <tr>
                        <th>Mês</th>
                        <th>Promotor</th>
                        <th>Tipo</th>
                        <th>Comissão</th>
                        <th>Padrão do Promotor</th>
                        <th>Configurado em</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commissions as $comm): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($comm['month_reference']) ?></strong></td>
                            <td>
                                <?php if ($comm['promoter_name'] === '__ALL__'): ?>
                                    <span style="background: #fff3cd; padding: 3px 8px; border-radius: 5px; font-weight: bold;">
                                        🌟 TODOS OS CONSULTORES
                                    </span>
                                <?php else: ?>
                                    <?= htmlspecialchars($comm['promoter_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($comm['commission_type'] == 'percentage'): ?>
                                    <span class="badge badge-percentage"><i class="fas fa-percent"></i> Percentual</span>
                                <?php else: ?>
                                    <span class="badge badge-fixed"><i class="fas fa-dollar-sign"></i> Fixo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong>
                                    <?php if ($comm['commission_type'] == 'percentage'): ?>
                                        <?= number_format($comm['commission_value'], 2, ',', '.') ?>%
                                    <?php else: ?>
                                        R$ <?= number_format($comm['commission_value'], 2, ',', '.') ?>
                                    <?php endif; ?>
                                </strong>
                            </td>
                            <td>
                                <?php if ($comm['default_percentage']): ?>
                                    <span class="text-muted"><?= number_format($comm['default_percentage'], 2, ',', '.') ?>%</span>
                                <?php else: ?>
                                    <span class="text-muted">25.00%</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?= date('d/m/Y H:i', strtotime($comm['created_at'])) ?></small></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning" onclick="editCommission(<?= $comm['id'] ?>, '<?= $comm['promoter_name'] === '__ALL__' ? 'TODOS' : htmlspecialchars($comm['promoter_name'], ENT_QUOTES) ?>', '<?= $comm['month_reference'] ?>', '<?= $comm['commission_type'] ?>', <?= $comm['commission_value'] ?>)" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Remover esta configuração?');">
                                    <input type="hidden" name="commission_id" value="<?= $comm['id'] ?>">
                                    <button type="submit" name="delete_commission" class="btn btn-sm btn-danger" title="Remover">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Informações -->
    <div class="alert alert-info mt-4">
        <h5><i class="fas fa-info-circle"></i> Como funciona:</h5>
        <ul>
            <li><strong>Percentual (%):</strong> A comissão será calculada como percentual do total de vendas do promotor no mês</li>
            <li><strong>Valor Fixo (R$):</strong> O promotor receberá esse valor fixo independente das vendas</li>
            <li><strong>Prioridade:</strong> Se houver comissão configurada para o mês, ela sobrescreve o padrão do promotor</li>
            <li><strong>Padrão:</strong> Se não houver configuração específica, usa o percentual do cadastro do promotor (ou 25% se não definido)</li>
        </ul>
    </div>
</div>

<script>
function updateLabel() {
    const type = document.getElementById('commission_type').value;
    const label = document.getElementById('value_label');

    if (type === 'percentage') {
        label.innerHTML = '<i class="fas fa-percent"></i> Valor (%):';
    } else {
        label.innerHTML = '<i class="fas fa-dollar-sign"></i> Valor (R$):';
    }
}

function editCommission(id, promoter, month, type, value) {
    // Preenche o formulário com os dados existentes
    document.getElementById('edit_id').value = id;
    document.getElementById('promoter').value = promoter;
    document.getElementById('month').value = month;
    document.getElementById('commission_type').value = type;
    document.getElementById('commission_value').value = value;

    // Atualiza o label
    updateLabel();

    // Muda o botão para "Atualizar"
    document.getElementById('btn_text').textContent = 'Atualizar';
    document.getElementById('save_btn').className = 'btn btn-success btn-block';
    document.getElementById('cancel_btn').style.display = 'block';

    // Scroll para o formulário
    document.getElementById('commission_form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function cancelEdit() {
    // Limpa o formulário
    document.getElementById('commission_form').reset();
    document.getElementById('edit_id').value = '';

    // Restaura o botão para "Salvar"
    document.getElementById('btn_text').textContent = 'Salvar';
    document.getElementById('save_btn').className = 'btn btn-primary btn-block';
    document.getElementById('cancel_btn').style.display = 'none';
}
</script>
</body>
</html>
