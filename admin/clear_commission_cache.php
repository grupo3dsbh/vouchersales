<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Verifica se usuário está autenticado e é admin ou superadmin
if (!isset($_SESSION['godmode_user_role']) || !in_array($_SESSION['godmode_user_role'], ['admin', 'superadmin'])) {
    die('<h1>Acesso Negado</h1><p>Apenas administradores podem limpar cache.</p><p><a href="../index.php?admin=1&godmode=on">← Voltar</a></p>');
}

$db = Database::getConnection();
$executed = false;
$results = [];

// Executar limpeza
if (isset($_POST['clear_cache'])) {
    $executed = true;

    // 1. Busca todos os payments
    $sql = "SELECT promoter, month FROM payments ORDER BY month DESC, promoter";
    $payments = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $results['total_payments'] = count($payments);
    $results['recalculated'] = 0;
    $results['errors'] = 0;

    foreach ($payments as $payment) {
        $promoter = $payment['promoter'];
        $month = $payment['month'];

        try {
            // Busca comissão correta
            $commission_config = getPromoterCommissionForMonth($promoter, $month);

            // Busca quantidade de vouchers do mês
            $sql = "SELECT COUNT(*) as qty, SUM(product_value) as total
                    FROM sales
                    WHERE promoter = ? AND month_reference = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$promoter, $month]);
            $sales_data = $stmt->fetch(PDO::FETCH_ASSOC);

            $vouchers = $sales_data['qty'] ?? 0;
            $total_value = $sales_data['total'] ?? 0;

            // Calcula comissão correta
            if ($commission_config['type'] === 'fixed') {
                $new_commission = $vouchers * $commission_config['value'];
            } else {
                $new_commission = $total_value * ($commission_config['value'] / 100);
            }

            // Atualiza tabela payments
            $sql = "UPDATE payments
                    SET amount = ?, vouchers = ?
                    WHERE promoter = ? AND month = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$new_commission, $vouchers, $promoter, $month]);

            $results['recalculated']++;

            // Guarda exemplo para exibir
            if ($results['recalculated'] <= 5) {
                $results['examples'][] = [
                    'promoter' => $promoter,
                    'month' => $month,
                    'vouchers' => $vouchers,
                    'total' => $total_value,
                    'type' => $commission_config['type'],
                    'rate' => $commission_config['value'],
                    'commission' => $new_commission
                ];
            }

        } catch (Exception $e) {
            $results['errors']++;
            error_log("Erro ao recalcular $promoter/$month: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Limpar Cache de Comissões</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .btn-danger {
            background: #dc3545;
            border: none;
            padding: 15px 30px;
            font-size: 18px;
            font-weight: bold;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .result-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        .stat {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
            padding: 10px;
            background: white;
            border-radius: 5px;
        }
        .stat i {
            font-size: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h1><i class="fas fa-broom"></i> Limpar Cache de Comissões</h1>
                <a href="../index.php?admin=1&godmode=on" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>

            <?php if (!$executed): ?>
            <div class="alert alert-warning">
                <h4><i class="fas fa-exclamation-triangle"></i> O que este script faz?</h4>
                <p>Este script <strong>recalcula TODAS as comissões</strong> na tabela <code>payments</code> usando as configurações atuais de <code>manage_commissions.php</code>.</p>

                <h5>Problema que resolve:</h5>
                <ul>
                    <li>Quando você muda uma comissão de 25% para 10%, o sistema salva o valor ANTIGO na tabela <code>payments</code></li>
                    <li>Esse cache impede que a nova comissão seja exibida corretamente</li>
                    <li>Este script recalcula TUDO com os valores corretos</li>
                </ul>

                <h5>⚠️ Avisos:</h5>
                <ul>
                    <li><strong>Isso não apaga pagamentos!</strong> Apenas recalcula os valores das comissões</li>
                    <li>O status "pago/pendente" é mantido</li>
                    <li>Comprovantes de pagamento são mantidos</li>
                    <li>Execução pode levar alguns segundos</li>
                </ul>
            </div>

            <form method="POST" onsubmit="return confirm('Tem certeza que deseja recalcular TODAS as comissões?\n\nIsso pode levar alguns segundos.');">
                <div style="text-align: center;">
                    <button type="submit" name="clear_cache" class="btn btn-danger btn-lg">
                        <i class="fas fa-sync-alt"></i> RECALCULAR TODAS AS COMISSÕES
                    </button>
                </div>
            </form>

            <?php else: ?>
            <div class="alert alert-success">
                <h4><i class="fas fa-check-circle"></i> Cache Recalculado com Sucesso!</h4>
            </div>

            <div class="result-box">
                <h4>📊 Resultados:</h4>

                <div class="stat">
                    <i class="fas fa-database" style="color: #007bff;"></i>
                    <div>
                        <strong>Total de registros:</strong> <?= $results['total_payments'] ?>
                    </div>
                </div>

                <div class="stat">
                    <i class="fas fa-check" style="color: #28a745;"></i>
                    <div>
                        <strong>Recalculados:</strong> <?= $results['recalculated'] ?>
                    </div>
                </div>

                <?php if ($results['errors'] > 0): ?>
                <div class="stat">
                    <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
                    <div>
                        <strong>Erros:</strong> <?= $results['errors'] ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($results['examples'])): ?>
                <hr>
                <h5>📋 Exemplos de recálculos:</h5>
                <table class="table table-sm table-bordered">
                    <thead class="thead-dark">
                        <tr>
                            <th>Promotor</th>
                            <th>Mês</th>
                            <th>Vouchers</th>
                            <th>Tipo</th>
                            <th>Taxa</th>
                            <th>Comissão</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['examples'] as $ex): ?>
                        <tr>
                            <td><?= htmlspecialchars($ex['promoter']) ?></td>
                            <td><?= $ex['month'] ?></td>
                            <td><?= $ex['vouchers'] ?></td>
                            <td><?= $ex['type'] === 'percentage' ? 'Percentual' : 'Fixo' ?></td>
                            <td>
                                <?= $ex['type'] === 'percentage'
                                    ? number_format($ex['rate'], 1) . '%'
                                    : 'R$ ' . number_format($ex['rate'], 2, ',', '.')
                                ?>
                            </td>
                            <td style="font-weight: bold; color: #28a745;">
                                R$ <?= number_format($ex['commission'], 2, ',', '.') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div style="text-align: center; margin-top: 20px;">
                <a href="../index.php?admin=1&godmode=on" class="btn btn-primary btn-lg">
                    <i class="fas fa-home"></i> Voltar ao Sistema
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
