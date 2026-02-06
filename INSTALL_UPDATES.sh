#!/bin/bash
# Script para instalar atualizações manualmente no servidor
# Execute: bash INSTALL_UPDATES.sh

echo "🔧 Instalando atualizações de comissões..."

# Navega para o diretório do vouchersales
cd /home/mcaquabeat.hotlead.es/public_html/vouchersales

echo "📦 Criando arquivo: admin/clear_commission_cache.php"
cat > admin/clear_commission_cache.php << 'ENDOFFILE'
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
            $sql = "SELECT COUNT(*) as qty, SUM(value) as total
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
    <title>Limpar Cache de Comissões</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .card { background: white; border-radius: 15px; padding: 30px; margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .btn-danger { background: #dc3545; border: none; padding: 15px 30px; font-size: 18px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1><i class="fas fa-broom"></i> Limpar Cache de Comissões</h1>

            <?php if (!$executed): ?>
            <div class="alert alert-warning">
                <h4>O que este script faz?</h4>
                <p>Recalcula TODAS as comissões usando as configurações atuais.</p>
            </div>

            <form method="POST" onsubmit="return confirm('Recalcular TODAS as comissões?');">
                <div style="text-align: center;">
                    <button type="submit" name="clear_cache" class="btn btn-danger btn-lg">
                        <i class="fas fa-sync-alt"></i> RECALCULAR TODAS AS COMISSÕES
                    </button>
                </div>
            </form>

            <?php else: ?>
            <div class="alert alert-success">
                <h4>Cache Recalculado!</h4>
                <p>Total: <?= $results['total_payments'] ?> | Recalculados: <?= $results['recalculated'] ?></p>
            </div>
            <a href="../index.php?admin=1&godmode=on" class="btn btn-primary">Voltar</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
ENDOFFILE

echo "✅ Arquivo criado com sucesso!"
echo ""
echo "🎯 PRÓXIMO PASSO:"
echo "Acesse: http://mcaquabeat.hotlead.es/vouchersales/admin/clear_commission_cache.php"
echo "E clique em 'RECALCULAR TODAS AS COMISSÕES'"
ENDOFFILE
