<?php
/**
 * Script para comparar dados dos arquivos JSON vs dados do MySQL
 * Acesse: http://seu-site.com/vouchersales/compare_sources.php
 */

require_once 'Database.php';
require_once 'functions.php';

$months_to_check = ['2025-10', '2025-11'];

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Comparação: Arquivos JSON vs MySQL</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #667eea; padding-bottom: 10px; }
        .month-comparison { margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 5px solid #667eea; }
        .month-title { font-size: 24px; font-weight: bold; margin-bottom: 15px; color: #667eea; }
        .comparison-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin: 15px 0; }
        .source-box { padding: 15px; border-radius: 8px; }
        .json-source { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
        .mysql-source { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; }
        .diff-box { background: #ffc107; color: #333; font-weight: bold; }
        .metric { margin: 10px 0; }
        .metric-label { font-size: 12px; opacity: 0.9; }
        .metric-value { font-size: 28px; font-weight: bold; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 8px; }
        .alert-danger { background: #dc3545; color: white; }
        .alert-success { background: #28a745; color: white; }
        .alert-warning { background: #ffc107; color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; }
        .positive { color: #28a745; font-weight: bold; }
        .negative { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1><i>📊</i> Comparação: Arquivos JSON vs Banco MySQL</h1>";

foreach ($months_to_check as $month) {
    echo "<div class='month-comparison'>";
    echo "<div class='month-title'>📅 " . date('F/Y', strtotime($month . '-01')) . " ($month)</div>";

    // 1. Dados dos arquivos JSON
    $json_dir = __DIR__ . '/uploads/';
    $json_file = $json_dir . $month . '.json';

    $json_stats = [
        'exists' => false,
        'records' => 0,
        'vouchers' => 0,
        'promoters' => 0,
        'total_value' => 0
    ];

    if (file_exists($json_file)) {
        $json_stats['exists'] = true;
        $json_data = json_decode(file_get_contents($json_file), true);

        $temp_vouchers = [];
        $temp_promoters = [];

        foreach ($json_data as $row) {
            $json_stats['records']++;

            if (!empty($row[9])) { // voucher_code
                $temp_vouchers[$row[9]] = true;
            }

            if (!empty($row[4]) && $row[4] !== 'NULL') { // promoter
                $temp_promoters[$row[4]] = true;
            }

            if (!empty($row[7])) { // value
                $value_clean = str_replace(['R$', ' ', '.'], '', $row[7]);
                $value_clean = str_replace(',', '.', $value_clean);
                $json_stats['total_value'] += floatval($value_clean);
            }
        }

        $json_stats['vouchers'] = count($temp_vouchers);
        $json_stats['promoters'] = count($temp_promoters);
    }

    // 2. Dados do MySQL
    $mysql_stats = [
        'exists' => false,
        'records' => 0,
        'vouchers' => 0,
        'promoters' => 0,
        'total_value' => 0,
        'unique_sale_items' => 0
    ];

    try {
        $sql = "SELECT
                    COUNT(*) as total_records,
                    COUNT(DISTINCT voucher_code) as unique_vouchers,
                    COUNT(DISTINCT promoter) as unique_promoters,
                    COUNT(DISTINCT sale_item_id) as unique_sale_items,
                    SUM(product_value) as total_value
                FROM sales
                WHERE month_reference = ?";

        $db_data = Database::fetchOne($sql, [$month]);

        if ($db_data && $db_data['total_records'] > 0) {
            $mysql_stats['exists'] = true;
            $mysql_stats['records'] = (int)$db_data['total_records'];
            $mysql_stats['vouchers'] = (int)$db_data['unique_vouchers'];
            $mysql_stats['promoters'] = (int)$db_data['unique_promoters'];
            $mysql_stats['unique_sale_items'] = (int)$db_data['unique_sale_items'];
            $mysql_stats['total_value'] = (float)$db_data['total_value'];
        }
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>❌ Erro ao buscar dados do MySQL: " . htmlspecialchars($e->getMessage()) . "</div>";
    }

    // 3. Comparação
    echo "<div class='comparison-grid'>";

    // Arquivo JSON
    echo "<div class='source-box json-source'>";
    echo "<div style='font-size: 18px; margin-bottom: 10px;'>📁 Arquivo JSON</div>";
    if ($json_stats['exists']) {
        echo "<div class='metric'><div class='metric-label'>Registros</div><div class='metric-value'>" . number_format($json_stats['records']) . "</div></div>";
        echo "<div class='metric'><div class='metric-label'>Vouchers Únicos</div><div class='metric-value'>" . number_format($json_stats['vouchers']) . "</div></div>";
        echo "<div class='metric'><div class='metric-label'>Consultores</div><div class='metric-value'>" . number_format($json_stats['promoters']) . "</div></div>";
        echo "<div class='metric'><div class='metric-label'>Valor Total</div><div class='metric-value'>R$ " . number_format($json_stats['total_value'], 2, ',', '.') . "</div></div>";
    } else {
        echo "<div style='padding: 20px; text-align: center;'>❌ Arquivo não encontrado</div>";
    }
    echo "</div>";

    // MySQL
    echo "<div class='source-box mysql-source'>";
    echo "<div style='font-size: 18px; margin-bottom: 10px;'>🗄️ Banco MySQL</div>";
    if ($mysql_stats['exists']) {
        echo "<div class='metric'><div class='metric-label'>Registros</div><div class='metric-value'>" . number_format($mysql_stats['records']) . "</div></div>";
        echo "<div class='metric'><div class='metric-label'>Vouchers Únicos</div><div class='metric-value'>" . number_format($mysql_stats['vouchers']) . "</div></div>";
        echo "<div class='metric'><div class='metric-label'>Consultores</div><div class='metric-value'>" . number_format($mysql_stats['promoters']) . "</div></div>";
        echo "<div class='metric'><div class='metric-label'>Valor Total</div><div class='metric-value'>R$ " . number_format($mysql_stats['total_value'], 2, ',', '.') . "</div></div>";
        echo "<div class='metric'><div class='metric-label'>Sale Items Únicos</div><div class='metric-value'>" . number_format($mysql_stats['unique_sale_items']) . "</div></div>";
    } else {
        echo "<div style='padding: 20px; text-align: center;'>❌ Sem dados no banco</div>";
    }
    echo "</div>";

    // Diferenças
    echo "<div class='source-box diff-box'>";
    echo "<div style='font-size: 18px; margin-bottom: 10px;'>⚖️ Diferenças</div>";
    if ($json_stats['exists'] && $mysql_stats['exists']) {
        $diff_records = $mysql_stats['records'] - $json_stats['records'];
        $diff_vouchers = $mysql_stats['vouchers'] - $json_stats['vouchers'];
        $diff_value = $mysql_stats['total_value'] - $json_stats['total_value'];

        echo "<div class='metric'><div class='metric-label'>Registros</div><div class='metric-value " . ($diff_records > 0 ? 'positive' : ($diff_records < 0 ? 'negative' : '')) . "'>" . ($diff_records >= 0 ? '+' : '') . number_format($diff_records) . "</div></div>";
        echo "<div class='metric'><div class='metric-label'>Vouchers</div><div class='metric-value " . ($diff_vouchers > 0 ? 'positive' : ($diff_vouchers < 0 ? 'negative' : '')) . "'>" . ($diff_vouchers >= 0 ? '+' : '') . number_format($diff_vouchers) . "</div></div>";
        echo "<div class='metric'><div class='metric-label'>Valor</div><div class='metric-value " . ($diff_value > 0 ? 'positive' : ($diff_value < 0 ? 'negative' : '')) . "'>" . ($diff_value >= 0 ? '+' : '') . "R$ " . number_format($diff_value, 2, ',', '.') . "</div></div>";

        // Análise de duplicatas
        $mysql_duplicates = $mysql_stats['records'] - $mysql_stats['unique_sale_items'];
        if ($mysql_duplicates > 0) {
            echo "<div style='margin-top: 15px; padding: 10px; background: rgba(220,53,69,0.2); border-radius: 5px; font-size: 12px;'>";
            echo "⚠️ <strong>" . number_format($mysql_duplicates) . " duplicatas</strong> no MySQL<br>";
            echo "(" . number_format($mysql_stats['records']) . " registros - " . number_format($mysql_stats['unique_sale_items']) . " sale items únicos)";
            echo "</div>";
        }
    } else {
        echo "<div style='padding: 20px; text-align: center;'>-</div>";
    }
    echo "</div>";

    echo "</div>"; // fim comparison-grid

    // Diagnóstico
    if ($json_stats['exists'] && $mysql_stats['exists']) {
        $diff_records = $mysql_stats['records'] - $json_stats['records'];
        $mysql_duplicates = $mysql_stats['records'] - $mysql_stats['unique_sale_items'];

        if ($mysql_duplicates > 0) {
            echo "<div class='alert alert-danger'>";
            echo "<strong>🔴 PROBLEMA IDENTIFICADO:</strong><br>";
            echo "O banco MySQL tem <strong>" . number_format($mysql_duplicates) . " registros duplicados</strong>.<br>";
            echo "Após limpar as duplicatas, o banco terá " . number_format($mysql_stats['unique_sale_items']) . " registros.";

            if ($mysql_stats['unique_sale_items'] < $json_stats['records']) {
                echo "<br><br><strong>⚠️ ATENÇÃO:</strong> Mesmo após limpar, MySQL terá MENOS registros que o JSON (" .
                     number_format($json_stats['records'] - $mysql_stats['unique_sale_items']) . " a menos).";
            } elseif ($mysql_stats['unique_sale_items'] > $json_stats['records']) {
                echo "<br><br><strong>⚠️ ATENÇÃO:</strong> Mesmo após limpar, MySQL terá MAIS registros que o JSON (" .
                     number_format($mysql_stats['unique_sale_items'] - $json_stats['records']) . " a mais).";
            } else {
                echo "<br><br><strong>✅ BOM:</strong> Após limpar as duplicatas, MySQL e JSON terão a mesma quantidade!";
            }
            echo "</div>";
        } elseif ($diff_records != 0) {
            echo "<div class='alert alert-warning'>";
            echo "<strong>⚠️ DIVERGÊNCIA:</strong><br>";
            echo "MySQL tem " . abs($diff_records) . " registros " . ($diff_records > 0 ? "a MAIS" : "a MENOS") . " que o arquivo JSON.";
            echo "</div>";
        } else {
            echo "<div class='alert alert-success'>";
            echo "<strong>✅ PERFEITO:</strong> MySQL e JSON estão sincronizados!";
            echo "</div>";
        }
    }

    echo "</div>"; // fim month-comparison
}

echo "
<div style='margin-top: 30px; padding: 20px; background: #e3f2fd; border-radius: 8px;'>
    <h3>💡 O que fazer?</h3>
    <ol>
        <li><strong>Se há duplicatas no MySQL:</strong> Use o botão \"Limpar Duplicatas\" no modo admin</li>
        <li><strong>Se MySQL tem menos que JSON:</strong> Pode haver dados que não foram importados. Considere re-importar o CSV.</li>
        <li><strong>Se MySQL tem mais que JSON:</strong> Pode haver importações adicionais. Verifique o histórico de importações.</li>
    </ol>
    <p><a href='?admin=1&godmode=on' style='display: inline-block; background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 10px;'>→ Ir para Modo Admin</a></p>
</div>
</div>
</body>
</html>";
