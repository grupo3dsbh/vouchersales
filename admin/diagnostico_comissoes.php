<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Verifica se usuário está autenticado e é admin ou superadmin
if (!isset($_SESSION['godmode_user_role']) || !in_array($_SESSION['godmode_user_role'], ['admin', 'superadmin'])) {
    die('<h1>Acesso Negado</h1><p>Apenas administradores podem visualizar diagnóstico.</p><p><a href="../index.php?admin=1&godmode=on">← Voltar</a></p>');
}

$db = Database::getConnection();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 Diagnóstico de Comissões</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .diagnostic-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .null-record {
            background: #fff3cd !important;
            border-left: 5px solid #ffc107;
        }
        .all-record {
            background: #d1ecf1 !important;
            border-left: 5px solid #17a2b8;
        }
        table {
            font-size: 13px;
        }
        .badge-null {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
        }
        .badge-all {
            background: #28a745;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="diagnostic-card">
            <h2><i class="fas fa-stethoscope"></i> Diagnóstico de Comissões</h2>
            <p class="text-muted">Verificando registros no banco de dados</p>
        </div>

        <?php
        // 1. Busca TODOS os registros de comissões
        echo '<div class="diagnostic-card">';
        echo '<h4>📋 Todos os Registros de Comissões</h4>';

        $sql = "SELECT
                    id,
                    promoter_name,
                    month_reference,
                    commission_type,
                    commission_value,
                    commission_percentage,
                    created_at
                FROM promoter_commission_history
                ORDER BY month_reference DESC, promoter_name";

        $stmt = $db->query($sql);
        $all_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($all_records)) {
            echo '<div class="alert alert-warning">Nenhum registro encontrado!</div>';
        } else {
            echo '<table class="table table-bordered table-sm">';
            echo '<thead class="thead-dark">';
            echo '<tr>';
            echo '<th>ID</th>';
            echo '<th>Promotor</th>';
            echo '<th>Mês</th>';
            echo '<th>Tipo</th>';
            echo '<th>Valor</th>';
            echo '<th>% (antigo)</th>';
            echo '<th>Criado em</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($all_records as $rec) {
                $isNull = ($rec['promoter_name'] === null);
                $isAll = ($rec['promoter_name'] === '__ALL__');
                $rowClass = '';

                if ($isNull) {
                    $rowClass = 'null-record';
                } elseif ($isAll) {
                    $rowClass = 'all-record';
                }

                echo '<tr class="' . $rowClass . '">';
                echo '<td>' . $rec['id'] . '</td>';
                echo '<td>';

                if ($isNull) {
                    echo '<span class="badge-null">⚠️ NULL (PROBLEMA!)</span>';
                } elseif ($isAll) {
                    echo '<span class="badge-all">✅ __ALL__</span>';
                } else {
                    echo htmlspecialchars($rec['promoter_name']);
                }

                echo '</td>';
                echo '<td><strong>' . $rec['month_reference'] . '</strong></td>';
                echo '<td>' . $rec['commission_type'] . '</td>';
                echo '<td>' . number_format($rec['commission_value'], 2, ',', '.') . '</td>';
                echo '<td>' . ($rec['commission_percentage'] ?? '-') . '</td>';
                echo '<td>' . date('d/m/Y H:i', strtotime($rec['created_at'])) . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';

            // Contagem
            $nullCount = count(array_filter($all_records, function($r) { return $r['promoter_name'] === null; }));
            $allCount = count(array_filter($all_records, function($r) { return $r['promoter_name'] === '__ALL__'; }));

            echo '<div class="alert alert-info">';
            echo '<strong>Resumo:</strong><br>';
            echo '🔴 Registros com NULL: <strong>' . $nullCount . '</strong><br>';
            echo '🟢 Registros com __ALL__: <strong>' . $allCount . '</strong><br>';
            echo '📊 Total de registros: <strong>' . count($all_records) . '</strong>';
            echo '</div>';
        }

        echo '</div>';

        // 2. Teste de Query para Dezembro 2025
        echo '<div class="diagnostic-card">';
        echo '<h4>🧪 Teste: O que o sistema retorna para Dezembro 2025?</h4>';

        // Query antiga (com NULL)
        echo '<h5>Query com IS NULL:</h5>';
        $sql = "SELECT commission_type, commission_value
                FROM promoter_commission_history
                WHERE promoter_name IS NULL AND month_reference = '2025-12'";

        echo '<pre>' . $sql . '</pre>';
        $stmt = $db->query($sql);
        $resultNull = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($resultNull)) {
            echo '<div class="alert alert-secondary">Nenhum resultado com NULL</div>';
        } else {
            echo '<div class="alert alert-warning">';
            echo '<strong>Encontrados ' . count($resultNull) . ' registros:</strong><br>';
            foreach ($resultNull as $r) {
                echo '- Tipo: ' . $r['commission_type'] . ', Valor: ' . $r['commission_value'] . '<br>';
            }
            echo '</div>';
        }

        // Query nova (com __ALL__)
        echo '<h5>Query com = \'__ALL__\':</h5>';
        $sql = "SELECT commission_type, commission_value
                FROM promoter_commission_history
                WHERE promoter_name = '__ALL__' AND month_reference = '2025-12'";

        echo '<pre>' . $sql . '</pre>';
        $stmt = $db->query($sql);
        $resultAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($resultAll)) {
            echo '<div class="alert alert-secondary">Nenhum resultado com __ALL__</div>';
        } else {
            echo '<div class="alert alert-success">';
            echo '<strong>Encontrados ' . count($resultAll) . ' registros:</strong><br>';
            foreach ($resultAll as $r) {
                echo '- Tipo: ' . $r['commission_type'] . ', Valor: ' . $r['commission_value'] . '<br>';
            }
            echo '</div>';
        }

        echo '</div>';

        // 3. Teste da função
        echo '<div class="diagnostic-card">';
        echo '<h4>🔬 Teste: O que a função retorna para GLECIA em Dezembro 2025?</h4>';

        $result = getPromoterCommissionForMonth('GLECIA CESARIA DOS SANTOS', '2025-12');

        echo '<div class="alert alert-info">';
        echo '<strong>Resultado da função:</strong><br>';
        echo 'Tipo: <strong>' . $result['type'] . '</strong><br>';
        echo 'Valor: <strong>' . number_format($result['value'], 2, ',', '.') . '</strong>';

        if ($result['type'] === 'percentage') {
            echo ' <span class="badge badge-warning">' . number_format($result['value'], 1) . '%</span>';
        }
        echo '</div>';

        echo '</div>';

        // 4. Recomendações
        echo '<div class="diagnostic-card">';
        echo '<h4>💡 Recomendações</h4>';

        if ($nullCount > 0) {
            echo '<div class="alert alert-danger">';
            echo '<strong>⚠️ PROBLEMA IDENTIFICADO!</strong><br>';
            echo 'Existem <strong>' . $nullCount . '</strong> registros com promoter_name = NULL<br>';
            echo 'Isso causa conflito com UNIQUE KEY e retorna valores incorretos.<br><br>';
            echo '<strong>Solução:</strong> Execute o script de migração:<br>';
            echo '<a href="fix_commission_null.php" class="btn btn-danger"><i class="fas fa-tools"></i> Executar Migração</a>';
            echo '</div>';
        } else {
            echo '<div class="alert alert-success">';
            echo '<strong>✅ Nenhum registro com NULL encontrado!</strong><br>';
            echo 'O sistema está usando __ALL__ corretamente.';
            echo '</div>';
        }

        echo '</div>';
        ?>

        <div class="text-center">
            <a href="manage_commissions.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Voltar para Gerenciar Comissões
            </a>
        </div>
    </div>
</body>
</html>
