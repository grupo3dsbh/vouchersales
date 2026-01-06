<?php
require_once '../config.php';
require_once '../functions.php';

echo "<h1>🧪 Teste de Query de Comissões</h1>";
echo "<pre style='background: #f5f5f5; padding: 20px; border-radius: 10px;'>";

$db = Database::getConnection();

echo "=" . str_repeat("=", 70) . "\n";
echo "TESTE 1: Buscar comissão específica para GLECIA CESARIA DOS SANTOS em 2025-12\n";
echo "=" . str_repeat("=", 70) . "\n\n";

$sql1 = "SELECT commission_type, commission_value, commission_percentage
        FROM promoter_commission_history
        WHERE promoter_name = 'GLECIA CESARIA DOS SANTOS' AND month_reference = '2025-12'";

echo "SQL:\n$sql1\n\n";
$result1 = $db->query($sql1)->fetch(PDO::FETCH_ASSOC);
echo "RESULTADO: " . ($result1 ? json_encode($result1, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : "NADA") . "\n\n";

echo "=" . str_repeat("=", 70) . "\n";
echo "TESTE 2: Buscar comissão GERAL (__ALL__) para 2025-12\n";
echo "=" . str_repeat("=", 70) . "\n\n";

$sql2 = "SELECT commission_type, commission_value
        FROM promoter_commission_history
        WHERE promoter_name = '__ALL__' AND month_reference = '2025-12'";

echo "SQL:\n$sql2\n\n";
$result2 = $db->query($sql2)->fetch(PDO::FETCH_ASSOC);
echo "RESULTADO: " . ($result2 ? json_encode($result2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : "NADA") . "\n\n";

echo "=" . str_repeat("=", 70) . "\n";
echo "TESTE 3: Chamar função getPromoterCommissionForMonth()\n";
echo "=" . str_repeat("=", 70) . "\n\n";

echo "Chamando: getPromoterCommissionForMonth('GLECIA CESARIA DOS SANTOS', '2025-12')\n\n";
$result3 = getPromoterCommissionForMonth('GLECIA CESARIA DOS SANTOS', '2025-12');
echo "RESULTADO: " . json_encode($result3, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=" . str_repeat("=", 70) . "\n";
echo "TESTE 4: Listar TODAS as comissões no banco\n";
echo "=" . str_repeat("=", 70) . "\n\n";

$sql4 = "SELECT * FROM promoter_commission_history ORDER BY month_reference DESC, promoter_name";
echo "SQL:\n$sql4\n\n";

$result4 = $db->query($sql4)->fetchAll(PDO::FETCH_ASSOC);

if (empty($result4)) {
    echo "⚠️ NENHUMA comissão encontrada no banco!\n";
} else {
    echo "📊 Encontradas " . count($result4) . " comissões:\n\n";

    foreach ($result4 as $row) {
        $promoter_display = ($row['promoter_name'] === '__ALL__') ? '🌟 __ALL__ (TODOS)' : $row['promoter_name'];
        echo sprintf(
            "  • %s | %s | %s = %s\n",
            $row['month_reference'],
            str_pad($promoter_display, 30),
            $row['commission_type'],
            $row['commission_value']
        );
    }
}

echo "\n";
echo "=" . str_repeat("=", 70) . "\n";
echo "TESTE 5: Buscar GLECIA na tabela promoters\n";
echo "=" . str_repeat("=", 70) . "\n\n";

$result5 = getPromoterByName('GLECIA CESARIA DOS SANTOS');
echo "RESULTADO: " . json_encode($result5, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "</pre>";

echo "<hr>";
echo "<h2>💡 Interpretação dos Resultados</h2>";
echo "<ul>";
echo "<li><strong>TESTE 1:</strong> Se retornar algo, é comissão ESPECÍFICA para GLECIA em dezembro</li>";
echo "<li><strong>TESTE 2:</strong> Se retornar 10%, é a comissão GERAL configurada para todos em dezembro</li>";
echo "<li><strong>TESTE 3:</strong> Mostra o que a função está retornando (deve ser 10% do TESTE 2)</li>";
echo "<li><strong>TESTE 4:</strong> Lista todas as comissões cadastradas</li>";
echo "<li><strong>TESTE 5:</strong> Verifica se GLECIA tem comissão no cadastro (PRIORIDADE 3)</li>";
echo "</ul>";

echo "<p><strong>✅ Resultado esperado:</strong> TESTE 3 deve retornar <code>percentage = 10.0</code> com source <code>PRIORIDADE 2</code></p>";

echo "<p><a href='../index.php?admin=1&godmode=on' class='btn btn-primary'>← Voltar ao Sistema</a></p>";
?>
