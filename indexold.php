<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DATA_DIR', __DIR__ . '/data');
define('ADMIN_PASSWORD', 'admin@2025');

if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

$is_admin_mode = isset($_GET['admin']) && $_GET['admin'] === '1';
$show_admin_button = isset($_GET['godmode']) && $_GET['godmode'] === 'on';

// Lógica para selecionar mês/ano de referência
$selected_month = $_POST['reference_month'] ?? $_SESSION['reference_month'] ?? date('Y-m');
$_SESSION['reference_month'] = $selected_month;

// Gera o nome do arquivo baseado no mês/ano selecionado
function getDataFile($month_year) {
    list($year, $month) = explode('-', $month_year);
    $months_pt = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $month_name = $months_pt[(int)$month - 1];
    return DATA_DIR . "/ingressos_{$month_name}_{$year}.csv";
}

define('DATA_FILE', getDataFile($selected_month));

// Função para listar todos os meses disponíveis
function getAvailableMonths() {
    $files = glob(DATA_DIR . '/ingressos_*.csv');
    $months = [];
    
    foreach ($files as $file) {
        $basename = basename($file, '.csv');
        // Formato: ingressos_out_2025
        if (preg_match('/ingressos_([a-z]{3})_(\d{4})/', $basename, $matches)) {
            $month_name = $matches[1];
            $year = $matches[2];
            
            $months_pt = ['jan' => '01', 'fev' => '02', 'mar' => '03', 'abr' => '04', 
                          'mai' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08',
                          'set' => '09', 'out' => '10', 'nov' => '11', 'dez' => '12'];
            
            if (isset($months_pt[$month_name])) {
                $month_num = $months_pt[$month_name];
                $months[] = [
                    'value' => "{$year}-{$month_num}",
                    'label' => ucfirst($month_name) . "/{$year}",
                    'file' => $file
                ];
            }
        }
    }
    
    // Ordena por data (mais recente primeiro)
    usort($months, function($a, $b) {
        return strcmp($b['value'], $a['value']);
    });
    
    return $months;
}

if ($is_admin_mode && isset($_POST['admin_login'])) {
    if ($_POST['admin_password'] === ADMIN_PASSWORD) {
        $_SESSION['admin_authenticated'] = true;
    } else {
        $error_msg = 'Senha incorreta!';
    }
}

if (isset($_POST['admin_logout'])) {
    unset($_SESSION['admin_authenticated']);
    header('Location: ?admin=1&godmode=on');
    exit;
}

// Deletar arquivo
if ($is_admin_mode && isset($_SESSION['admin_authenticated']) && isset($_POST['delete_file'])) {
    $month_to_delete = $_POST['delete_month'];
    $file_to_delete = getDataFile($month_to_delete);
    
    if (file_exists($file_to_delete)) {
        if (unlink($file_to_delete)) {
            $success_msg = 'Arquivo excluído com sucesso!';
            // Limpa cache
            if (isset($_SESSION['csv_data'][$month_to_delete])) {
                unset($_SESSION['csv_data'][$month_to_delete]);
            }
        } else {
            $error_msg = 'Erro ao excluir arquivo!';
        }
    }
}

if ($is_admin_mode && isset($_SESSION['admin_authenticated']) && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
    // Valida se mês/ano foi informado
    if (empty($_POST['upload_month'])) {
        $error_msg = 'Por favor, informe o mês e ano de referência!';
    } else {
        $upload_month = $_POST['upload_month'];
        $target_file = getDataFile($upload_month);
        
        if (move_uploaded_file($_FILES['csv_file']['tmp_name'], $target_file)) {
            $success_msg = 'Arquivo enviado com sucesso para ' . basename($target_file) . '!';
            // Limpa cache apenas do mês específico
            if (isset($_SESSION['csv_data'][$upload_month])) {
                unset($_SESSION['csv_data'][$upload_month]);
            }
            // Atualiza o mês selecionado para o que foi feito upload
            $_SESSION['reference_month'] = $upload_month;
            $selected_month = $upload_month;
        } else {
            $error_msg = 'Erro ao salvar arquivo!';
        }
    }
}

$csv_data = null;
if (file_exists(DATA_FILE)) {
    if (!isset($_SESSION['csv_data'][$selected_month])) {
        $data = [];
        if (($handle = fopen(DATA_FILE, 'r')) !== FALSE) {
            // Remove BOM se existir
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            
            // Detecta o delimitador automaticamente
            $first_line = fgets($handle);
            rewind($handle);
            
            // Remove BOM novamente após rewind
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            
            // Conta quantos delimitadores existem na primeira linha
            $semicolon_count = substr_count($first_line, ';');
            $comma_count = substr_count($first_line, ',');
            
            // Escolhe o delimitador com maior ocorrência
            $delimiter = ($semicolon_count > $comma_count) ? ';' : ',';
            
            $headers = fgetcsv($handle, 10000, $delimiter);
            while (($row = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                if (count($headers) === count($row)) {
                    $data[] = array_combine($headers, $row);
                }
            }
            fclose($handle);
            $_SESSION['csv_data'][$selected_month] = $data;
        }
    }
    $csv_data = $_SESSION['csv_data'][$selected_month] ?? null;
}

$selected_promoter = $_POST['promoter'] ?? $_SESSION['selected_promoter'] ?? null;
if ($selected_promoter) {
    $_SESSION['selected_promoter'] = $selected_promoter;
}

$promoters = [];
$promoter_stats = [];
$voucher_packages = [];

if ($csv_data) {
    foreach ($csv_data as $row) {
        $voucher = $row['VoucherCode'] ?? '';
        $promoter = $row['Promoter'] ?? '';
        
        if (!empty($voucher) && !empty($promoter)) {
            $value_raw = $row['ProductValue'] ?? '0';
            $value_clean = str_replace(['R$', ' '], '', $value_raw);
            // Se tem vírgula, substitui vírgula por ponto (formato BR: 1.400,00)
            if (strpos($value_clean, ',') !== false) {
                $value_clean = str_replace(['.', ','], ['', '.'], $value_clean);
            }
            // Senão já está no formato correto (formato US: 140.00)
            $value = floatval($value_clean);
            
            if (!isset($voucher_packages[$voucher])) {
                $voucher_packages[$voucher] = [
                    'promoter' => $promoter,
                    'value' => 0, // Volta a somar
                    'sale_id' => $row['SaleItemsId'] ?? '',
                    'status' => $row['VoucherStatus'] ?? '',
                    'campaign' => $row['CampaignName'] ?? '',
                    'package' => $row['PackageName'] ?? '',
                    'date' => $row['SaleDateTime'] ?? '',
                    'customers' => [],
                    'items' => []
                ];
            }
            $voucher_packages[$voucher]['value'] += $value; // Soma valor de cada pessoa
            $voucher_packages[$voucher]['customers'][] = $row['VisitorName'] ?? '';
            $voucher_packages[$voucher]['items'][] = $row;
        }
    }
    
    foreach ($voucher_packages as $voucher => $package) {
        $promoter = $package['promoter'];
        
        if (!in_array($promoter, $promoters)) {
            $promoters[] = $promoter;
        }
        
        if (!isset($promoter_stats[$promoter])) {
            $promoter_stats[$promoter] = [
                'quantity' => 0,
                'total' => 0,
                'commission' => 0,
                'sales' => []
            ];
        }
        
        $promoter_stats[$promoter]['quantity']++;
        $promoter_stats[$promoter]['total'] += $package['value'];
        $promoter_stats[$promoter]['commission'] += ($package['value'] * 0.25);
        
        $promoter_stats[$promoter]['sales'][] = [
            'voucher' => $voucher,
            'sale_id' => $package['sale_id'],
            'status' => $package['status'],
            'campaign' => $package['campaign'],
            'package' => $package['package'],
            'value' => $package['value'],
            'commission' => $package['value'] * 0.25,
            'date' => $package['date'],
            'customers' => $package['customers'],
            'items' => $package['items']
        ];
    }
    
    sort($promoters);
}

$is_admin_authenticated = $is_admin_mode && isset($_SESSION['admin_authenticated']);
$available_months = getAvailableMonths();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_admin_mode ? 'Administração' : 'Minhas Vendas' ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, <?= $is_admin_mode ? '#dc3545 0%, #c82333 100%' : '#667eea 0%, #764ba2 100%' ?>);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; background: white; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); padding: 30px; }
        h1 { color: #333; margin-bottom: 30px; text-align: center; font-size: 32px; }
        .admin-badge { background: #dc3545; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px; margin-left: 10px; }
        .upload-section { background: #f8f9fa; padding: 25px; border-radius: 10px; margin-bottom: 30px; border: 2px solid <?= $is_admin_mode ? '#dc3545' : '#ddd' ?>; }
        .admin-section { background: #fff3cd; border: 2px solid #ffc107; }
        .upload-form { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        input[type="file"], input[type="password"], input[type="month"] { flex: 1; padding: 10px; border: 2px solid #ddd; border-radius: 5px; background: white; }
        .btn { padding: 12px 25px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; transform: translateY(-2px); color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-secondary { background: #6c757d; color: white; }
        .filter-section { margin-bottom: 30px; }
        .month-selector { background: #e7f3ff; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px solid #667eea; }
        .month-selector label { font-weight: 600; color: #333; margin-bottom: 10px; display: block; }
        select { width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 5px; font-size: 16px; background: white; }
        .stats-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 15px; }
        .stat-item { background: rgba(255,255,255,0.2); padding: 20px; border-radius: 8px; text-align: center; }
        .stat-label { font-size: 14px; opacity: 0.9; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; }
        .stat-value { font-size: 32px; font-weight: bold; }
        .table-container { overflow-x: auto; margin-top: 20px; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th { background: #667eea; color: white; padding: 15px; text-align: left; font-weight: 600; position: sticky; top: 0; z-index: 10; }
        td { padding: 12px 15px; border-bottom: 1px solid #eee; }
        tr:hover { background: #f8f9fa; }
        .nested-row { background: #f8f9fa !important; }
        .nested-row td { padding: 8px 15px 8px 40px; font-size: 0.9em; color: #666; border-bottom: 1px solid #e0e0e0; }
        .main-row { font-weight: 500; background: white; }
        .toggle-details { cursor: pointer; color: #667eea; font-size: 1.2em; }
        .toggle-details:hover { color: #5568d3; }
        .alert { padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .file-info { color: #666; margin-top: 10px; font-size: 14px; }
        .badge { padding: 5px 10px; border-radius: 3px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #28a745; color: white; }
        .badge-warning { background: #ffc107; color: #333; }
        .badge-secondary { background: #6c757d; color: white; }
        .badge-info { background: #17a2b8; color: white; }
        .export-buttons { display: flex; gap: 10px; margin-bottom: 20px; align-items: center; }
        .form-control { padding: 10px 15px; border: 2px solid #ddd; border-radius: 5px; font-size: 14px; width: 100%; }
        .form-control:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .no-results { padding: 20px; text-align: center; color: #666; background: #f8f9fa; border-radius: 5px; margin: 10px; }
        .login-card { max-width: 500px; margin: 50px auto; padding: 30px; background: white; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .mode-switch { text-align: center; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; }
        .month-badge { background: #667eea; color: white; padding: 8px 15px; border-radius: 20px; font-size: 14px; margin-left: 10px; display: inline-block; }
        @media print {
            body { background: white; padding: 0; }
            .upload-section, .filter-section, .export-buttons, .btn, .mode-switch, .month-selector { display: none !important; }
            .container { box-shadow: none; padding: 20px; }
            th { background: #333 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$is_admin_mode): ?>
            <?php if ($show_admin_button): ?>
                <div class="mode-switch">
                    <a href="?admin=1&godmode=on" class="btn btn-secondary btn-sm">
                        <i class="fas fa-user-shield"></i> Acesso Administrador
                    </a>
                </div>
            <?php endif; ?>
            
            <h1>
                <i class="fas fa-chart-line"></i> Minhas Vendas
                <?php if (!empty($available_months)): ?>
                    <span class="month-badge">
                        <i class="fas fa-calendar-alt"></i> 
                        <?php
                            list($year, $month) = explode('-', $selected_month);
                            $months_pt = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr', 
                                          '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
                                          '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];
                            echo $months_pt[$month] . '/' . $year;
                        ?>
                    </span>
                <?php endif; ?>
            </h1>
            
            <?php if (!empty($available_months)): ?>
                <div class="month-selector">
                    <form method="POST">
                        <label for="reference_month">
                            <i class="fas fa-calendar"></i> Selecione o Período:
                        </label>
                        <select name="reference_month" id="reference_month" onchange="this.form.submit()">
                            <?php foreach ($available_months as $month): ?>
                                <option value="<?= htmlspecialchars($month['value']) ?>" <?= $selected_month === $month['value'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($month['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php endif; ?>
            
            <?php if ($csv_data && count($promoters) > 0): ?>
                <div class="filter-section">
                    <form method="POST">
                        <input type="hidden" name="reference_month" value="<?= htmlspecialchars($selected_month) ?>">
                        <label for="promoter" style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                            <i class="fas fa-user"></i> Selecione seu nome:
                        </label>
                        <select name="promoter" id="promoter" onchange="this.form.submit()">
                            <option value="">-- Selecione seu nome --</option>
                            <?php foreach ($promoters as $promoter): ?>
                                <option value="<?= htmlspecialchars($promoter) ?>" <?= $selected_promoter === $promoter ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($promoter) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                
                <?php if ($selected_promoter && isset($promoter_stats[$selected_promoter])): ?>
                    <?php $stats = $promoter_stats[$selected_promoter]; ?>
                    
                    <div class="stats-card">
                        <h2 style="margin-bottom: 20px; text-align: center;">
                            <i class="fas fa-user-circle"></i> <?= htmlspecialchars($selected_promoter) ?>
                        </h2>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-label"><i class="fas fa-shopping-cart"></i> Vendas</div>
                                <div class="stat-value"><?= $stats['quantity'] ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-label"><i class="fas fa-dollar-sign"></i> Valor Total</div>
                                <div class="stat-value">R$ <?= number_format($stats['total'], 2, ',', '.') ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-label"><i class="fas fa-hand-holding-usd"></i> Comissão (25%)</div>
                                <div class="stat-value">R$ <?= number_format($stats['commission'], 2, ',', '.') ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="export-buttons">
                        <button onclick="window.print()" class="btn btn-success">
                            <i class="fas fa-print"></i> Imprimir
                        </button>
                        <button onclick="exportToExcel()" class="btn btn-primary">
                            <i class="fas fa-file-excel"></i> Exportar Excel
                        </button>
                        <div style="flex: 1; max-width: 400px; margin-left: auto;">
                            <input type="text" id="searchVoucher" class="form-control" placeholder="🔍 Buscar por voucher..." onkeyup="filterByVoucher()">
                        </div>
                    </div>
                    
                    <div class="table-container">
                        <h3 style="padding: 20px; margin: 0; color: #333;">
                            <i class="fas fa-list"></i> Detalhamento das Vendas
                        </h3>
                        <table id="salesTable">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"></th>
                                    <th>ID</th>
                                    <th>Voucher</th>
                                    <th>Status</th>
                                    <th>Campanha</th>
                                    <th>Pacote</th>
                                    <th>Pessoas</th>
                                    <th>Valor</th>
                                    <th>Comissão</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['sales'] as $index => $sale): ?>
                                    <tr class="main-row sale-row" data-voucher="<?= strtolower(htmlspecialchars($sale['voucher'])) ?>">
                                        <td>
                                            <span class="toggle-details" onclick="toggleDetails(<?= $index ?>)">
                                                <i class="fas fa-plus-circle" id="icon-<?= $index ?>"></i>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($sale['sale_id']) ?></td>
                                        <td><strong><?= htmlspecialchars($sale['voucher']) ?></strong></td>
                                        <td>
                                            <span class="badge badge-<?= $sale['status'] == 'Pronto para uso' ? 'success' : ($sale['status'] == 'Aguardando pagamento' ? 'warning' : 'secondary') ?>">
                                                <?= htmlspecialchars($sale['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($sale['campaign']) ?></td>
                                        <td><?= htmlspecialchars($sale['package']) ?></td>
                                        <td style="text-align: center;">
                                            <span class="badge badge-secondary"><?= count($sale['customers']) ?></span>
                                        </td>
                                        <td style="font-weight: bold;">R$ <?= number_format($sale['value'], 2, ',', '.') ?></td>
                                        <td style="color: #28a745; font-weight: bold;">R$ <?= number_format($sale['commission'], 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($sale['date']) ?></td>
                                    </tr>
                                    <?php foreach ($sale['items'] as $item): ?>
                                        <tr class="nested-row sale-row" id="details-<?= $index ?>" style="display: none;" data-voucher="<?= strtolower(htmlspecialchars($sale['voucher'])) ?>">
                                            <td></td>
                                            <td colspan="2">
                                                <i class="fas fa-user"></i> <?= htmlspecialchars($item['VisitorName'] ?? '') ?>
                                            </td>
                                            <td colspan="7">
                                                <?php if (!empty($item['VisitDate'])): ?>
                                                    Visita: <?= htmlspecialchars($item['VisitDate']) ?>
                                                <?php endif; ?>
                                                <?php if (!empty($item['SaleWeekday'])): ?>
                                                    | <?= htmlspecialchars($item['SaleWeekday']) ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($selected_promoter): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Nenhuma venda encontrada para este período.
                    </div>
                <?php endif; ?>
            <?php elseif ($csv_data): ?>
                <div class="alert alert-info">
                    <i class="fas fa-exclamation-triangle"></i> Nenhum consultor encontrado.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i>
                    <h4>Nenhum arquivo carregado</h4>
                    <p>Aguarde o administrador carregar os dados ou selecione outro período.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="mode-switch">
                <a href="<?= $_SERVER['PHP_SELF'] ?>?godmode=on" class="btn btn-primary btn-sm">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
            
            <h1>
                <i class="fas fa-user-shield"></i> Administração
                <span class="admin-badge">ADMIN</span>
            </h1>
            
            <?php if (isset($success_msg)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success_msg ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_msg)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?= $error_msg ?>
                </div>
            <?php endif; ?>
            
            <?php if (!$is_admin_authenticated): ?>
                <div class="login-card">
                    <h3 style="text-align: center; margin-bottom: 20px;">
                        <i class="fas fa-lock"></i> Login de Administrador
                    </h3>
                    <form method="POST">
                        <div class="form-group">
                            <label>Senha:</label>
                            <input type="password" name="admin_password" class="form-control" required autofocus>
                        </div>
                        <button type="submit" name="admin_login" class="btn btn-danger btn-block">
                            <i class="fas fa-sign-in-alt"></i> Entrar
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="upload-section admin-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5><i class="fas fa-upload"></i> Upload CSV com Mês/Ano de Referência</h5>
                        <form method="POST" style="margin: 0;">
                            <button type="submit" name="admin_logout" class="btn btn-secondary btn-sm">
                                <i class="fas fa-sign-out-alt"></i> Sair
                            </button>
                        </form>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="upload-form">
                        <div style="flex: 1; min-width: 200px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">
                                <i class="fas fa-calendar"></i> Mês/Ano:
                            </label>
                            <input type="month" name="upload_month" value="<?= date('Y-m') ?>" required>
                        </div>
                        <div style="flex: 2; min-width: 300px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">
                                <i class="fas fa-file-csv"></i> Arquivo CSV:
                            </label>
                            <input type="file" name="csv_file" accept=".csv" required>
                        </div>
                        <button type="submit" class="btn btn-danger" style="align-self: flex-end;">
                            <i class="fas fa-upload"></i> Enviar
                        </button>
                    </form>
                    
                    <?php if (!empty($available_months)): ?>
                        <div class="file-info" style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #ddd;">
                            <strong><i class="fas fa-history"></i> Arquivos Disponíveis:</strong>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; margin-top: 15px;">
                                <?php foreach ($available_months as $month): 
                                    // Carrega dados do arquivo para estatísticas
                                    $month_file = $month['file'];
                                    $month_stats = [
                                        'promoters' => 0,
                                        'vouchers' => 0,
                                        'tickets' => 0,
                                        'total_value' => 0
                                    ];
                                    
                                    if (file_exists($month_file)) {
                                        $temp_data = [];
                                        if (($handle = fopen($month_file, 'r')) !== FALSE) {
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
                                            $delimiter = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';
                                            
                                            $headers = fgetcsv($handle, 10000, $delimiter);
                                            while (($row = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                                                if (count($headers) === count($row)) {
                                                    $temp_data[] = array_combine($headers, $row);
                                                }
                                            }
                                            fclose($handle);
                                        }
                                        
                                        // Calcula estatísticas
                                        $temp_promoters = [];
                                        $temp_vouchers = [];
                                        
                                        foreach ($temp_data as $row) {
                                            $voucher = $row['VoucherCode'] ?? '';
                                            $promoter = $row['Promoter'] ?? '';
                                            
                                            if (!empty($promoter) && $promoter !== 'NULL') {
                                                $temp_promoters[$promoter] = true;
                                            }
                                            
                                            if (!empty($voucher)) {
                                                $month_stats['tickets']++;
                                                
                                                // Soma o valor de cada ingresso individual
                                                $value_raw = $row['ProductValue'] ?? '0';
                                                $value_clean = str_replace(['R$', ' '], '', $value_raw);
                                                if (strpos($value_clean, ',') !== false) {
                                                    $value_clean = str_replace(['.', ','], ['', '.'], $value_clean);
                                                }
                                                $value = floatval($value_clean);
                                                $month_stats['total_value'] += $value;
                                                
                                                if (!isset($temp_vouchers[$voucher])) {
                                                    $temp_vouchers[$voucher] = true;
                                                }
                                            }
                                        }
                                        
                                        $month_stats['promoters'] = count($temp_promoters);
                                        $month_stats['vouchers'] = count($temp_vouchers);
                                        $month_stats['promoters_list'] = array_keys($temp_promoters);
                                        sort($month_stats['promoters_list']);
                                    }
                                    
                                    $is_expanded = isset($_POST['view_month']) && $_POST['view_month'] === $month['value'];
                                ?>
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid <?= $month['value'] === $selected_month ? '#667eea' : '#ddd' ?>; position: relative;">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                            <div style="font-weight: bold; color: #333; font-size: 16px;">
                                                <i class="fas fa-file-csv"></i> <?= htmlspecialchars($month['label']) ?>
                                            </div>
                                            <div style="display: flex; gap: 5px;">
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="view_month" value="<?= $is_expanded ? '' : htmlspecialchars($month['value']) ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm" style="padding: 5px 10px; font-size: 12px;" title="<?= $is_expanded ? 'Ocultar' : 'Ver' ?> consultores">
                                                        <i class="fas fa-<?= $is_expanded ? 'eye-slash' : 'eye' ?>"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('Deseja realmente excluir este arquivo?');" style="margin: 0;">
                                                    <input type="hidden" name="delete_month" value="<?= htmlspecialchars($month['value']) ?>">
                                                    <button type="submit" name="delete_file" class="btn btn-danger btn-sm" style="padding: 5px 10px; font-size: 12px;" title="Excluir arquivo">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                        
                                        <div style="font-size: 11px; color: #666; margin-bottom: 10px;">
                                            <i class="fas fa-clock"></i> <?= date('d/m/Y H:i', filemtime($month['file'])) ?>
                                        </div>
                                        
                                        <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 13px;">
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                                <div>
                                                    <i class="fas fa-users" style="color: #667eea;"></i>
                                                    <strong><?= $month_stats['promoters'] ?></strong> consultores
                                                </div>
                                                <div>
                                                    <i class="fas fa-ticket-alt" style="color: #28a745;"></i>
                                                    <strong><?= $month_stats['vouchers'] ?></strong> vouchers
                                                </div>
                                                <div>
                                                    <i class="fas fa-shopping-cart" style="color: #17a2b8;"></i>
                                                    <strong><?= $month_stats['tickets'] ?></strong> ingressos
                                                </div>
                                                <div>
                                                    <i class="fas fa-dollar-sign" style="color: #ffc107;"></i>
                                                    <strong>R$ <?= number_format($month_stats['total_value'], 0, ',', '.') ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($is_expanded && !empty($month_stats['promoters_list'])): ?>
                                            <div style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #ddd;">
                                                <strong style="display: block; margin-bottom: 10px; color: #333;">
                                                    <i class="fas fa-users"></i> Consultores (<?= count($month_stats['promoters_list']) ?>):
                                                </strong>
                                                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                                    <?php foreach ($month_stats['promoters_list'] as $promo): ?>
                                                        <span class="badge badge-secondary" style="font-size: 11px; padding: 6px 10px;">
                                                            <?= htmlspecialchars($promo) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="file-info">
                            <i class="fas fa-info-circle" style="color: #ffc107;"></i> Nenhum arquivo enviado ainda.
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($csv_data && count($promoters) > 0): ?>
                    <div class="alert alert-info">
                        <h5><i class="fas fa-users"></i> Consultores: <?= count($promoters) ?></h5>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                            <?php foreach ($promoters as $promoter): ?>
                                <span class="badge badge-secondary" style="font-size: 14px; padding: 8px 12px;">
                                    <?= htmlspecialchars($promoter) ?> (<?= $promoter_stats[$promoter]['quantity'] ?>)
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        function filterByVoucher() {
            const filter = document.getElementById('searchVoucher').value.toLowerCase().trim();
            const rows = document.querySelectorAll('.sale-row');
            let count = 0;
            
            rows.forEach(row => {
                const voucher = row.getAttribute('data-voucher');
                if (voucher && voucher.includes(filter)) {
                    row.style.display = '';
                    count++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            let msg = document.getElementById('noResults');
            if (count === 0 && filter !== '') {
                if (!msg) {
                    msg = document.createElement('div');
                    msg.id = 'noResults';
                    msg.className = 'no-results';
                    msg.innerHTML = '<i class="fas fa-search"></i> Nenhum voucher encontrado';
                    document.querySelector('.table-container').appendChild(msg);
                }
            } else if (msg) {
                msg.remove();
            }
        }
        
        function toggleDetails(index) {
            const details = document.querySelectorAll(`#details-${index}`);
            const icon = document.getElementById(`icon-${index}`);
            
            details.forEach(detail => {
                if (detail.style.display === 'none') {
                    detail.style.display = 'table-row';
                    icon.className = 'fas fa-minus-circle';
                } else {
                    detail.style.display = 'none';
                    icon.className = 'fas fa-plus-circle';
                }
            });
        }
        
        function exportToExcel() {
            const table = document.getElementById('salesTable');
            const wb = XLSX.utils.table_to_book(table, {sheet: "Vendas"});
            const name = "<?= htmlspecialchars($selected_promoter ?? 'Vendas') ?>";
            const period = "<?= str_replace('-', '_', $selected_month) ?>";
            XLSX.writeFile(wb, `Vendas_${name}_${period}.xlsx`);
        }

        document.getElementById('promoter')?.addEventListener('change', function() {
            if (this.value) {
                setTimeout(() => {
                    window.scrollTo({
                        top: document.querySelector('.stats-card')?.offsetTop - 20 || 0,
                        behavior: 'smooth'
                    });
                }, 100);
            }
        });
    </script>
</body>
</html>