<?php
/**
 * Script de teste de importação
 * Testa a importação de um CSV sem modificar o banco
 */

require_once 'Database.php';

$csvFile = __DIR__ . '/data/ingressos_nov_2025.csv';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Teste de Importação</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #d4d4d4; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .warning { color: #ce9178; }
        .info { color: #9cdcfe; }
        pre { background: #2d2d2d; padding: 15px; border-radius: 5px; overflow-x: auto; }
        h2 { color: #4fc1ff; border-bottom: 2px solid #4fc1ff; padding-bottom: 5px; }
        table { border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 8px 12px; border: 1px solid #555; text-align: left; }
        th { background: #2d2d2d; color: #4fc1ff; }
    </style>
</head>
<body>";

echo "<h1>🔍 Teste de Importação - Novembro/2025</h1>";

if (!file_exists($csvFile)) {
    echo "<p class='error'>❌ Arquivo não encontrado: $csvFile</p>";
    exit;
}

echo "<p class='success'>✅ Arquivo encontrado: $csvFile</p>";
echo "<p class='info'>Tamanho: " . number_format(filesize($csvFile)) . " bytes</p>";

// Abre arquivo
$handle = fopen($csvFile, 'r');
if ($handle === FALSE) {
    echo "<p class='error'>❌ Erro ao abrir arquivo!</p>";
    exit;
}

// Remove BOM
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

// Detecta delimitador
$first_line = fgets($handle);
rewind($handle);
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

$tab_count = substr_count($first_line, "\t");
$comma_count = substr_count($first_line, ',');
$semicolon_count = substr_count($first_line, ';');

if ($tab_count > $comma_count && $tab_count > $semicolon_count) {
    $delimiter = "\t";
    $delimiter_name = "TAB";
} elseif ($semicolon_count > $comma_count) {
    $delimiter = ';';
    $delimiter_name = "PONTO-E-VÍRGULA (;)";
} else {
    $delimiter = ',';
    $delimiter_name = "VÍRGULA (,)";
}

echo "<h2>📋 Análise do Arquivo</h2>";
echo "<p class='info'><strong>Delimitador detectado:</strong> $delimiter_name</p>";
echo "<p class='info'><strong>Primeira linha:</strong></p>";
echo "<pre>" . htmlspecialchars(substr($first_line, 0, 200)) . "...</pre>";

// Lê headers
$headers = fgetcsv($handle, 10000, $delimiter);

if (empty($headers) || !is_array($headers)) {
    echo "<p class='error'>❌ Não foi possível ler os headers!</p>";
    fclose($handle);
    exit;
}

// Remove espaços e BOM dos headers
$headers = array_map(function($header) {
    return trim(str_replace("\xEF\xBB\xBF", '', $header));
}, $headers);

echo "<h2>📊 Headers do CSV</h2>";
echo "<p class='success'>✅ " . count($headers) . " colunas encontradas</p>";
echo "<table>";
echo "<tr><th>#</th><th>Nome da Coluna</th></tr>";
foreach ($headers as $index => $header) {
    echo "<tr><td>" . ($index + 1) . "</td><td>" . htmlspecialchars($header) . "</td></tr>";
}
echo "</table>";

// Verifica headers esperados
$expectedHeaders = ['SaleItemsId', 'VoucherCode', 'VoucherStatus', 'OriginPlace', 'CampaignName',
                    'PackageName', 'ProductName', 'ProductValue', 'Promoter'];

$missingHeaders = [];
foreach ($expectedHeaders as $expected) {
    if (!in_array($expected, $headers)) {
        $missingHeaders[] = $expected;
    }
}

if (!empty($missingHeaders)) {
    echo "<p class='error'>❌ Headers faltando: " . implode(', ', $missingHeaders) . "</p>";
} else {
    echo "<p class='success'>✅ Todos os headers essenciais foram encontrados!</p>";
}

// Lê primeiras 10 linhas
echo "<h2>📄 Primeiras 5 Linhas de Dados</h2>";
$lineCount = 0;
$withSite = 0;
$withoutSite = 0;
$emptyPromoter = 0;
$validRecords = 0;

echo "<table>";
echo "<tr><th>Linha</th><th>SaleItemsId</th><th>VoucherCode</th><th>Promoter</th><th>CampaignName</th><th>ProductValue</th></tr>";

while (($row = fgetcsv($handle, 10000, $delimiter)) !== FALSE && $lineCount < 5) {
    $lineCount++;

    if (count($headers) !== count($row)) {
        echo "<tr><td>$lineCount</td><td colspan='5' class='error'>❌ Número de colunas não confere! Esperado: " . count($headers) . ", Encontrado: " . count($row) . "</td></tr>";
        continue;
    }

    $data = array_combine($headers, $row);

    $saleItemId = $data['SaleItemsId'] ?? '';
    $voucherCode = $data['VoucherCode'] ?? '';
    $promoter = trim($data['Promoter'] ?? '', " \"\n\r\t");
    $campaign = $data['CampaignName'] ?? '';
    $productValue = $data['ProductValue'] ?? '0';

    echo "<tr>";
    echo "<td>$lineCount</td>";
    echo "<td>" . htmlspecialchars(substr($saleItemId, 0, 15)) . "</td>";
    echo "<td>" . htmlspecialchars(substr($voucherCode, 0, 15)) . "</td>";
    echo "<td>" . htmlspecialchars(substr($promoter, 0, 20)) . "</td>";
    echo "<td>" . htmlspecialchars(substr($campaign, 0, 30)) . "</td>";
    echo "<td>" . htmlspecialchars($productValue) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Reseta e conta tudo
rewind($handle);
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}
fgetcsv($handle, 10000, $delimiter); // pula headers

$lineCount = 0;
$withSite = 0;
$withoutSite = 0;
$emptyPromoter = 0;
$validRecords = 0;
$promoters = [];
$campaigns = [];

while (($row = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
    $lineCount++;

    if (count($headers) !== count($row)) {
        continue;
    }

    $data = array_combine($headers, $row);
    $promoter = trim($data['Promoter'] ?? '', " \"\n\r\t");
    $campaign = $data['CampaignName'] ?? '';
    $voucherCode = $data['VoucherCode'] ?? '';

    if (!empty($campaign)) {
        $campaigns[$campaign] = ($campaigns[$campaign] ?? 0) + 1;
    }

    if (stripos($campaign, 'SITE') !== false) {
        $withSite++;
    } else {
        $withoutSite++;
        if (!empty($promoter) && $promoter !== 'NULL' && !empty($voucherCode)) {
            $validRecords++;
            $promoters[$promoter] = ($promoters[$promoter] ?? 0) + 1;
        } elseif (empty($promoter) || $promoter === 'NULL') {
            $emptyPromoter++;
        }
    }
}

fclose($handle);

echo "<h2>📊 Estatísticas Gerais</h2>";
echo "<table>";
echo "<tr><th>Métrica</th><th>Valor</th></tr>";
echo "<tr><td>Total de linhas no CSV</td><td class='success'>" . number_format($lineCount) . "</td></tr>";
echo "<tr><td>Com 'SITE' na campanha (seriam IGNORADOS)</td><td class='warning'>" . number_format($withSite) . "</td></tr>";
echo "<tr><td>SEM 'SITE' na campanha (seriam IMPORTADOS)</td><td class='success'>" . number_format($withoutSite) . "</td></tr>";
echo "<tr><td>Sem promoter ou voucher vazio</td><td class='warning'>" . number_format($emptyPromoter) . "</td></tr>";
echo "<tr><td><strong>Registros VÁLIDOS para importação</strong></td><td class='success'><strong>" . number_format($validRecords) . "</strong></td></tr>";
echo "</table>";

echo "<h2>👥 Promotores Encontrados (" . count($promoters) . ")</h2>";
arsort($promoters);
echo "<table>";
echo "<tr><th>Promoter</th><th>Registros</th></tr>";
$count = 0;
foreach ($promoters as $promoter => $qty) {
    if ($count++ >= 15) break;
    echo "<tr><td>" . htmlspecialchars($promoter) . "</td><td>" . number_format($qty) . "</td></tr>";
}
echo "</table>";

echo "<h2>📢 Campanhas Encontradas (" . count($campaigns) . ")</h2>";
arsort($campaigns);
echo "<table>";
echo "<tr><th>Campanha</th><th>Registros</th><th>Status</th></tr>";
foreach ($campaigns as $campaign => $qty) {
    $isSite = stripos($campaign, 'SITE') !== false;
    $status = $isSite ? "<span class='error'>SERIA IGNORADO</span>" : "<span class='success'>SERIA IMPORTADO</span>";
    echo "<tr><td>" . htmlspecialchars($campaign) . "</td><td>" . number_format($qty) . "</td><td>$status</td></tr>";
}
echo "</table>";

echo "<h2>💡 Conclusão</h2>";
if ($validRecords > 0) {
    echo "<p class='success'>✅ O arquivo parece estar OK! $validRecords registros válidos seriam importados.</p>";
} else {
    echo "<p class='error'>❌ PROBLEMA: Nenhum registro válido encontrado para importação!</p>";
    echo "<p class='warning'>Isso explica por que o banco ficou zerado após a importação.</p>";
}

if ($withSite > 0) {
    echo "<p class='warning'>⚠️ ATENÇÃO: $withSite registros têm 'SITE' na campanha e seriam ignorados pelo filtro.</p>";
    echo "<p class='info'>Atualmente a importação NÃO filtra esses registros, mas o display sim!</p>";
}

echo "</body></html>";
