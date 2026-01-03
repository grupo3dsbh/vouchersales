<?php
/**
 * Script de correção da UNIQUE KEY da tabela sales
 *
 * PROBLEMA: A UNIQUE KEY está configurada como (sale_item_id, voucher_code)
 * mas deveria ser apenas (sale_item_id), pois sale_item_id é o identificador
 * único real de cada ingresso.
 *
 * Isso permitiu que múltiplas importações criassem duplicatas.
 *
 * ACESSE: fix_unique_key.php
 */

require_once 'Database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Correção de UNIQUE KEY</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #dc3545; border-bottom: 3px solid #dc3545; padding-bottom: 10px; }
        h2 { color: #667eea; margin-top: 30px; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 8px; }
        .alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .alert-warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; }
        .code { background: #2d2d2d; color: #d4d4d4; padding: 15px; border-radius: 5px; font-family: monospace; overflow-x: auto; }
        .btn { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .btn-danger { background: #dc3545; }
        .step { background: #f8f9fa; padding: 15px; margin: 10px 0; border-left: 4px solid #667eea; }
        .number { display: inline-block; width: 30px; height: 30px; background: #667eea; color: white; border-radius: 50%; text-align: center; line-height: 30px; margin-right: 10px; font-weight: bold; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>🔧 Correção de UNIQUE KEY da Tabela Sales</h1>";

try {
    $db = Database::getConnection();

    // 1. ANÁLISE ATUAL
    echo "<h2>📊 1. Análise Atual do Banco</h2>";

    // Verifica UNIQUE KEY atual
    $sql = "SHOW INDEX FROM sales WHERE Key_name = 'uk_sale_voucher'";
    $indexes = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($indexes)) {
        echo "<div class='alert alert-danger'>";
        echo "<strong>❌ PROBLEMA CONFIRMADO!</strong><br>";
        echo "UNIQUE KEY atual: <code>uk_sale_voucher</code> composta por:<br>";
        echo "<ul>";
        foreach ($indexes as $idx) {
            echo "<li><code>" . $idx['Column_name'] . "</code></li>";
        }
        echo "</ul>";
        echo "Isso permite que o mesmo <code>sale_item_id</code> seja inserido múltiplas vezes!";
        echo "</div>";
    }

    // Conta duplicatas por mês
    $sql = "SELECT
                month_reference,
                COUNT(*) as total_records,
                COUNT(DISTINCT sale_item_id) as unique_sale_items,
                COUNT(*) - COUNT(DISTINCT sale_item_id) as duplicates,
                COUNT(DISTINCT voucher_code) as unique_vouchers
            FROM sales
            WHERE campaign_name NOT LIKE '%SITE%'
            GROUP BY month_reference
            ORDER BY month_reference DESC";

    $months = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo "<table>";
    echo "<tr>
            <th>Mês</th>
            <th>Total Registros</th>
            <th>Sale Items Únicos</th>
            <th>Duplicatas</th>
            <th>Vouchers Únicos</th>
            <th>Status</th>
          </tr>";

    $total_duplicates = 0;
    foreach ($months as $month) {
        $duplicates = (int)$month['duplicates'];
        $total_duplicates += $duplicates;

        $status = $duplicates > 0
            ? "<span style='color: #dc3545; font-weight: bold;'>❌ TEM DUPLICATAS</span>"
            : "<span style='color: #28a745; font-weight: bold;'>✅ LIMPO</span>";

        echo "<tr>";
        echo "<td>" . $month['month_reference'] . "</td>";
        echo "<td>" . number_format($month['total_records']) . "</td>";
        echo "<td>" . number_format($month['unique_sale_items']) . "</td>";
        echo "<td style='font-weight: bold; color: " . ($duplicates > 0 ? "#dc3545" : "#28a745") . ";'>" . number_format($duplicates) . "</td>";
        echo "<td>" . number_format($month['unique_vouchers']) . "</td>";
        echo "<td>$status</td>";
        echo "</tr>";
    }

    echo "</table>";

    echo "<div class='alert alert-warning'>";
    echo "<strong>📌 TOTAL DE DUPLICATAS NO BANCO:</strong> " . number_format($total_duplicates) . " registros";
    echo "</div>";

    // 2. PLANO DE CORREÇÃO
    echo "<h2>🔧 2. Plano de Correção</h2>";

    echo "<div class='step'>";
    echo "<span class='number'>1</span>";
    echo "<strong>Remover duplicatas do banco</strong><br>";
    echo "Manter apenas o registro mais recente (maior id) de cada sale_item_id";
    echo "</div>";

    echo "<div class='step'>";
    echo "<span class='number'>2</span>";
    echo "<strong>Remover UNIQUE KEY antiga</strong><br>";
    echo "<div class='code'>ALTER TABLE sales DROP INDEX uk_sale_voucher;</div>";
    echo "</div>";

    echo "<div class='step'>";
    echo "<span class='number'>3</span>";
    echo "<strong>Criar UNIQUE KEY correta</strong><br>";
    echo "<div class='code'>ALTER TABLE sales ADD UNIQUE KEY uk_sale_item (sale_item_id);</div>";
    echo "</div>";

    echo "<div class='step'>";
    echo "<span class='number'>4</span>";
    echo "<strong>Verificar integridade</strong><br>";
    echo "Confirmar que não há mais duplicatas e que a nova UNIQUE KEY está funcionando";
    echo "</div>";

    // 3. EXECUTAR CORREÇÃO
    if (isset($_POST['execute_fix'])) {
        echo "<h2>⚡ 3. Executando Correção...</h2>";

        $db->beginTransaction();

        try {
            // Passo 1: Remover duplicatas
            echo "<p><strong>Passo 1:</strong> Removendo duplicatas...</p>";

            $sql = "SELECT sale_item_id, COUNT(*) as count
                    FROM sales
                    GROUP BY sale_item_id
                    HAVING count > 1";

            $duplicates = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $removed = 0;
            foreach ($duplicates as $dup) {
                $sql = "DELETE FROM sales
                        WHERE sale_item_id = :sale_item_id
                        AND id NOT IN (
                            SELECT * FROM (
                                SELECT MAX(id)
                                FROM sales
                                WHERE sale_item_id = :sale_item_id2
                            ) AS temp
                        )";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':sale_item_id' => $dup['sale_item_id'],
                    ':sale_item_id2' => $dup['sale_item_id']
                ]);

                $removed += $stmt->rowCount();
            }

            echo "<div class='alert alert-success'>✅ Removidas <strong>$removed</strong> duplicatas!</div>";

            // Passo 2: Remover UNIQUE KEY antiga
            echo "<p><strong>Passo 2:</strong> Removendo UNIQUE KEY antiga...</p>";

            $db->exec("ALTER TABLE sales DROP INDEX uk_sale_voucher");

            echo "<div class='alert alert-success'>✅ UNIQUE KEY antiga removida!</div>";

            // Passo 3: Criar UNIQUE KEY correta
            echo "<p><strong>Passo 3:</strong> Criando UNIQUE KEY correta...</p>";

            $db->exec("ALTER TABLE sales ADD UNIQUE KEY uk_sale_item (sale_item_id)");

            echo "<div class='alert alert-success'>✅ Nova UNIQUE KEY criada: <code>uk_sale_item (sale_item_id)</code></div>";

            $db->commit();

            echo "<div class='alert alert-success'>";
            echo "<h3>🎉 CORREÇÃO CONCLUÍDA COM SUCESSO!</h3>";
            echo "<p>✅ $removed duplicatas removidas<br>";
            echo "✅ UNIQUE KEY corrigida<br>";
            echo "✅ Banco de dados está agora protegido contra duplicatas!</p>";
            echo "<p><a href='index.php?admin=1&godmode=on' class='btn'>→ Voltar ao Sistema</a></p>";
            echo "</div>";

        } catch (Exception $e) {
            $db->rollBack();
            echo "<div class='alert alert-danger'>";
            echo "<strong>❌ ERRO durante a correção:</strong><br>";
            echo htmlspecialchars($e->getMessage());
            echo "</div>";
        }

    } else {
        // Mostra botão de confirmação
        echo "<h2>⚠️ 3. Confirmação Necessária</h2>";

        echo "<div class='alert alert-warning'>";
        echo "<strong>ATENÇÃO:</strong> Esta operação irá:<br>";
        echo "<ul>";
        echo "<li>Remover <strong>" . number_format($total_duplicates) . " registros duplicados</strong></li>";
        echo "<li>Alterar a estrutura da tabela <code>sales</code></li>";
        echo "<li>Esta operação é <strong>IRREVERSÍVEL</strong></li>";
        echo "</ul>";
        echo "<p>Certifique-se de ter um backup do banco de dados antes de prosseguir!</p>";
        echo "</div>";

        echo "<form method='POST'>";
        echo "<button type='submit' name='execute_fix' class='btn btn-danger' onclick='return confirm(\"Tem certeza? Esta operação é IRREVERSÍVEL!\");'>";
        echo "🔧 EXECUTAR CORREÇÃO AGORA";
        echo "</button>";
        echo "<a href='index.php?admin=1&godmode=on' class='btn'>← Cancelar</a>";
        echo "</form>";
    }

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<strong>❌ Erro:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

echo "</div></body></html>";
