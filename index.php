<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DATA_DIR', __DIR__ . '/data');

// Inclui funções auxiliares
require_once 'functions.php';

// Verifica timeout de sessão
checkSessionTimeout();

// Gera token CSRF
$csrf_token = generateCSRFToken();

// Carrega configurações
$config = loadConfig();

// Verifica se sistema está instalado
try {
    Database::getConnection();
} catch (Exception $e) {
    // Redireciona para instalação se banco não estiver configurado
    if (!defined('INSTALL_MODE')) {
        header('Location: /admin/install.php');
        exit;
    }
}

if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

$is_admin_mode = isset($_GET['admin']) && $_GET['admin'] === '1';
$show_admin_button = isset($_GET['godmode']) && $_GET['godmode'] === 'on';
$godmode_enabled = isset($_GET['godmode']) && $_GET['godmode'] === 'on';
$show_total_row = isset($_GET['total']) && $_GET['total'] === 'on';

// Autenticação GODMODE
if ($godmode_enabled && isset($_POST['godmode_login'])) {
    $username = $_POST['godmode_username'] ?? '';
    $password = $_POST['godmode_password'] ?? '';
    
    $user = authenticateUser($username, $password);
    
    if ($user) {
        $_SESSION['godmode_authenticated'] = true;
        $_SESSION['godmode_user'] = $user['name'];
        $_SESSION['godmode_username'] = $user['username'];
        $_SESSION['godmode_user_id'] = $user['id'];
        $_SESSION['godmode_user_role'] = $user['role'] ?? 'admin'; // Armazena role

        // Regenera token CSRF após login
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $error_msg = 'Usuário ou senha incorretos!';
    }
}

if (isset($_POST['godmode_logout'])) {
    unset($_SESSION['godmode_authenticated']);
    unset($_SESSION['godmode_user']);
    unset($_SESSION['godmode_username']);
    unset($_SESSION['godmode_user_id']);
    unset($_SESSION['godmode_user_role']);
    header('Location: ?godmode=on');
    exit;
}

// Gestão de usuários (apenas para admin principal)
$is_main_admin = isset($_SESSION['godmode_user_id']) && $_SESSION['godmode_user_id'] == 1;

if ($is_main_admin && isset($_POST['add_user'])) {
    $result = addUser($_POST['new_username'], $_POST['new_password'], $_POST['new_name']);
    if ($result['success']) {
        $success_msg = $result['message'];
    } else {
        $error_msg = $result['message'];
    }
}

if ($is_main_admin && isset($_POST['edit_user'])) {
    $role = $_POST['edit_role'] ?? 'admin';
    $master_pin = $_POST['edit_master_pin'] ?? null;
    $result = editUser($_POST['edit_id'], $_POST['edit_username'], $_POST['edit_password'], $_POST['edit_name'], $role, null, $master_pin);
    if ($result['success']) {
        $success_msg = $result['message'];
    } else {
        $error_msg = $result['message'];
    }
}

if ($is_main_admin && isset($_POST['toggle_user'])) {
    $result = toggleUserStatus($_POST['user_id'], $_POST['active'] === '1');
    if ($result['success']) {
        $success_msg = $result['message'];
    } else {
        $error_msg = $result['message'];
    }
}

if ($is_main_admin && isset($_POST['delete_user'])) {
    $result = deleteUser($_POST['user_id']);
    if ($result['success']) {
        $success_msg = $result['message'];
    } else {
        $error_msg = $result['message'];
    }
}

// Carrega lista de meses disponíveis
$available_months = getAvailableMonths();

// Lógica para selecionar mês/ano de referência
$selected_month = $_POST['reference_month'] ?? $_SESSION['reference_month'] ?? null;
if ($selected_month) {
    $_SESSION['reference_month'] = $selected_month;
}

$data_file = getDataFile($selected_month);

if ($is_admin_mode && isset($_POST['admin_login'])) {
    $admin_username = $_POST['admin_username'] ?? '';
    $admin_password = $_POST['admin_password'] ?? '';

    // Tenta autenticar como usuário do banco de dados
    $user = authenticateUser($admin_username, $admin_password);

    if ($user) {
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['godmode_user_id'] = $user['id'];
        $_SESSION['godmode_user'] = $user['name'];
        $_SESSION['godmode_user_role'] = $user['role'] ?? 'admin'; // Armazena role
        // Recalcula is_main_admin
        $is_main_admin = true;
    } else {
        $error_msg = 'Usuário ou senha incorretos!';
    }
}

if (isset($_POST['admin_logout'])) {
    unset($_SESSION['admin_authenticated']);
    unset($_SESSION['godmode_user_id']);
    unset($_SESSION['godmode_user']);
    unset($_SESSION['godmode_user_role']);
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
            // Recarrega lista de meses
            $available_months = getAvailableMonths();
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
            $success_msg = 'Arquivo CSV salvo com sucesso!';

            // Importa automaticamente para o banco de dados
            $import_to_db = isset($_POST['import_to_db']) && $_POST['import_to_db'] === '1';
            $replace_data = isset($_POST['replace_data']) && $_POST['replace_data'] === '1';

            if ($import_to_db) {
                $userId = $_SESSION['godmode_user_id'] ?? 1;
                $importResult = importCSVToDatabase($target_file, $upload_month, $userId, $replace_data);

                if ($importResult['success']) {
                    $success_msg .= ' ' . $importResult['message'];
                } else {
                    $error_msg = $importResult['message'];
                }
            } else {
                $success_msg .= ' Use o botão "Importar para Banco de Dados" abaixo para importar os dados.';
            }

            // Limpa cache apenas do mês específico
            if (isset($_SESSION['csv_data'][$upload_month])) {
                unset($_SESSION['csv_data'][$upload_month]);
            }
            // Atualiza o mês selecionado para o que foi feito upload
            $_SESSION['reference_month'] = $upload_month;
            $selected_month = $upload_month;
            // Recarrega lista de meses
            $available_months = getAvailableMonths();
        } else {
            $error_msg = 'Erro ao salvar arquivo!';
        }
    }
}

// Importar CSV existente para banco de dados
if ($is_admin_mode && isset($_SESSION['admin_authenticated']) && isset($_POST['import_existing_csv'])) {
    $import_month = $_POST['import_month'] ?? '';
    $replace_existing = isset($_POST['replace_existing']) && $_POST['replace_existing'] === '1';

    if (empty($import_month)) {
        $error_msg = 'Por favor, selecione um mês para importar!';
    } else {
        $csv_file = getDataFile($import_month);

        if (!file_exists($csv_file)) {
            $error_msg = 'Arquivo CSV não encontrado!';
        } else {
            $userId = $_SESSION['godmode_user_id'] ?? 1;
            $importResult = importCSVToDatabase($csv_file, $import_month, $userId, $replace_existing);

            if ($importResult['success']) {
                $success_msg = $importResult['message'];
            } else {
                $error_msg = $importResult['message'];
            }
        }
    }
}

// Remover duplicatas de vendas
if ($is_admin_mode && isset($_SESSION['admin_authenticated']) && isset($_POST['remove_duplicates'])) {
    $userId = $_SESSION['godmode_user_id'] ?? 1;
    $result = removeDuplicateSales($userId);

    if ($result['success']) {
        $success_msg = $result['message'];
    } else {
        $error_msg = $result['message'];
    }
}

// Deletar todos os dados de vendas
if ($is_admin_mode && isset($_SESSION['admin_authenticated']) && isset($_POST['delete_all_sales'])) {
    if (isset($_POST['confirm_delete_all']) && $_POST['confirm_delete_all'] === 'DELETAR TUDO') {
        $userId = $_SESSION['godmode_user_id'] ?? 1;
        $result = deleteAllSalesData($userId);

        if ($result['success']) {
            $success_msg = $result['message'];
        } else {
            $error_msg = $result['message'];
        }
    } else {
        $error_msg = 'Confirmação inválida! Digite "DELETAR TUDO" para confirmar.';
    }
}

// Deletar dados de um mês específico
if ($is_admin_mode && isset($_SESSION['admin_authenticated']) && isset($_POST['delete_month_sales'])) {
    $month_to_delete = $_POST['month_to_delete'] ?? '';

    if (empty($month_to_delete)) {
        $error_msg = 'Selecione um mês para deletar!';
    } else {
        $userId = $_SESSION['godmode_user_id'] ?? 1;
        $result = deleteSalesDataByMonth($month_to_delete, $userId);

        if ($result['success']) {
            $success_msg = $result['message'];
        } else {
            $error_msg = $result['message'];
        }
    }
}

// Debug de dados de um mês específico
$debug_result = null;
if ($is_admin_mode && isset($_SESSION['admin_authenticated']) && isset($_POST['debug_month'])) {
    $month_to_debug = $_POST['month_to_debug'] ?? '';

    if (!empty($month_to_debug)) {
        $debug_result = debugMonthData($month_to_debug);
    }
}

// Upload de CSV de Promotores
if ($is_admin_mode && isset($_SESSION['admin_authenticated']) && isset($_FILES['promoters_csv_file']) && $_FILES['promoters_csv_file']['error'] == 0) {
    $upload_dir = DATA_DIR . '/';
    $file_name = 'promoters_' . date('Ymd_His') . '.csv';
    $target_file = $upload_dir . $file_name;

    // Cria diretório se não existir
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    if (move_uploaded_file($_FILES['promoters_csv_file']['tmp_name'], $target_file)) {
        $replace_promoters = isset($_POST['replace_promoters']) && $_POST['replace_promoters'] === '1';
        $userId = $_SESSION['godmode_user_id'] ?? 1;

        $importResult = importPromotersCSV($target_file, $userId, $replace_promoters);

        if ($importResult['success']) {
            $success_msg = $importResult['message'];
            // Remove arquivo temporário após importação bem-sucedida
            @unlink($target_file);
        } else {
            $error_msg = $importResult['message'];
        }
    } else {
        $error_msg = 'Erro ao fazer upload do arquivo de promotores!';
    }
}

$csv_data = null;
if ($data_file && file_exists($data_file)) {
    if (!isset($_SESSION['csv_data'][$selected_month])) {
        $data = [];
        if (($handle = fopen($data_file, 'r')) !== FALSE) {
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

// ===== AUTENTICAÇÃO DE PROMOTOR =====
$promoter_auth_error = '';
$promoter_auth_step = ''; // pode ser: 'select_name', 'verify_pin', 'verify_info', 'create_pin'

// Logout de promotor
if (isset($_POST['promoter_logout'])) {
    unset($_SESSION['selected_promoter']);
    unset($_SESSION['promoter_authenticated']);
    unset($_SESSION['promoter_id']);
}

// Verifica master code (admin pode acessar qualquer promotor)
if (isset($_POST['verify_master_code']) && !empty($_POST['master_code'])) {
    $master_code = $_POST['master_code'];
    $promoter_name = $_POST['promoter_name'] ?? '';

    // Verifica se o usuário logado tem master code
    $userId = $_SESSION['godmode_user_id'] ?? null;
    if ($userId) {
        $sql = "SELECT master_code FROM users WHERE id = ?";
        $user = Database::fetchOne($sql, [$userId]);

        if ($user && !empty($user['master_code']) && password_verify($master_code, $user['master_code'])) {
            // Master code correto - autentica diretamente
            $promoter = getPromoterByName($promoter_name);
            if ($promoter) {
                $_SESSION['selected_promoter'] = $promoter_name;
                $_SESSION['promoter_authenticated'] = true;
                $_SESSION['promoter_id'] = $promoter['id'];
                $_SESSION['auth_method'] = 'master_code';

                logAudit($userId, 'promoter_access_with_master_code', 'promoters', $promoter['id'], null, json_encode(['promoter' => $promoter_name]));
            } else {
                $promoter_auth_error = 'Promotor não encontrado no sistema!';
            }
        } else {
            $promoter_auth_error = 'Código mestre inválido!';
        }
    }
}

// Tentativa de login (seleção de nome)
if (isset($_POST['promoter_login']) && !empty($_POST['promoter_name'])) {
    $promoter_name = sanitizeInput($_POST['promoter_name']);

    // Busca promotor no banco
    $promoter = getPromoterByName($promoter_name);

    if (!$promoter) {
        $promoter_auth_error = 'Promotor não encontrado no sistema! Entre em contato com o administrador.';
    } elseif ($promoter['status'] !== 'Ativo') {
        $promoter_auth_error = 'Seu cadastro está desativado. Entre em contato com o administrador.';
    } else {
        // Promotor existe e está ativo
        $_SESSION['promoter_login_attempt'] = $promoter_name;
        $_SESSION['promoter_id_temp'] = $promoter['id'];

        // Verifica se já tem PIN cadastrado
        if (!empty($promoter['pin'])) {
            // Tem PIN - pede PIN
            $promoter_auth_step = 'verify_pin';
        } else {
            // Não tem PIN - pede verificação pessoal (primeiro login)
            $promoter_auth_step = 'verify_info';
        }
    }
}

// Verificação de PIN
if (isset($_POST['verify_pin']) && isset($_SESSION['promoter_id_temp'])) {
    $pin = $_POST['pin'] ?? '';
    $promoter_id = $_SESSION['promoter_id_temp'];

    $pin_valid = verifyPromoterPIN($promoter_id, $pin);
    $admin_access = false;
    $admin_user = null;

    // Se PIN não é do consultor, verifica se é master PIN de algum admin
    if (!$pin_valid && !empty($pin)) {
        $sql = "SELECT id, name, username FROM users WHERE master_pin = ? AND master_pin IS NOT NULL";
        $admin_user = Database::fetchOne($sql, [$pin]);

        if ($admin_user) {
            $pin_valid = true;
            $admin_access = true;
        }
    }

    if ($pin_valid) {
        // PIN correto
        $promoter_name = $_SESSION['promoter_login_attempt'];
        $_SESSION['selected_promoter'] = $promoter_name;
        $_SESSION['promoter_authenticated'] = true;
        $_SESSION['promoter_id'] = $promoter_id;
        $_SESSION['auth_method'] = $admin_access ? 'admin_master_pin' : 'pin';

        unset($_SESSION['promoter_login_attempt']);
        unset($_SESSION['promoter_id_temp']);

        if ($admin_access) {
            // Registra acesso do admin com master PIN
            logAudit($admin_user['id'], 'admin_master_pin_access', 'promoters', $promoter_id,
                     "Admin {$admin_user['name']} acessou relatório de {$promoter_name} usando master PIN");
        } else {
            logAudit($promoter_id, 'promoter_login_pin', 'promoters', $promoter_id);
        }
    } else {
        // Verifica quantas tentativas
        $sql = "SELECT pin_attempts FROM promoters WHERE id = ?";
        $p = Database::fetchOne($sql, [$promoter_id]);

        if ($p && $p['pin_attempts'] >= 3) {
            $promoter_auth_error = 'PIN bloqueado após 3 tentativas incorretas! Por favor, faça a verificação com seus dados pessoais novamente.';
            $promoter_auth_step = 'verify_info';
        } else {
            $remaining = 3 - ($p['pin_attempts'] ?? 0);
            $promoter_auth_error = "PIN incorreto! Você tem mais $remaining tentativa(s).";
            $promoter_auth_step = 'verify_pin';
        }
    }
}

// Verificação de informações pessoais (primeiro login ou após reset)
if (isset($_POST['verify_info']) && isset($_SESSION['promoter_id_temp'])) {
    $promoter_id = $_SESSION['promoter_id_temp'];
    $verification_type = $_POST['verification_type'] ?? '';
    $verification_value = $_POST['verification_value'] ?? '';

    $sql = "SELECT * FROM promoters WHERE id = ?";
    $promoter = Database::fetchOne($sql, [$promoter_id]);

    $is_valid = false;

    if ($promoter) {
        switch ($verification_type) {
            case 'cpf_last4':
                // Últimos 4 dígitos do CPF
                $cpf = preg_replace('/[^0-9]/', '', $promoter['document'] ?? '');
                $last4 = substr($cpf, -4);
                $is_valid = ($verification_value === $last4);
                break;

            case 'cpf_first4':
                // Primeiros 4 dígitos do CPF
                $cpf = preg_replace('/[^0-9]/', '', $promoter['document'] ?? '');
                $first4 = substr($cpf, 0, 4);
                $is_valid = ($verification_value === $first4);
                break;

            case 'middle_name':
                // Nome do meio
                $name_parts = explode(' ', trim($promoter['name']));
                if (count($name_parts) >= 3) {
                    // Pega o segundo nome (índice 1)
                    $middle_name = strtoupper($name_parts[1]);
                    $is_valid = (strtoupper(trim($verification_value)) === $middle_name);
                }
                break;
        }
    }

    if ($is_valid) {
        // Verificação bem-sucedida - pede para criar PIN
        $promoter_auth_step = 'create_pin';
    } else {
        $promoter_auth_error = 'Informação incorreta! Tente novamente.';
        $promoter_auth_step = 'verify_info';
    }
}

// Criação de PIN após verificação
if (isset($_POST['create_pin']) && isset($_SESSION['promoter_id_temp'])) {
    $new_pin = $_POST['new_pin'] ?? '';
    $confirm_pin = $_POST['confirm_pin'] ?? '';

    if (strlen($new_pin) < 4) {
        $promoter_auth_error = 'O PIN deve ter pelo menos 4 dígitos!';
        $promoter_auth_step = 'create_pin';
    } elseif ($new_pin !== $confirm_pin) {
        $promoter_auth_error = 'Os PINs não conferem! Digite novamente.';
        $promoter_auth_step = 'create_pin';
    } else {
        $promoter_id = $_SESSION['promoter_id_temp'];

        if (updatePromoterPIN($promoter_id, $new_pin)) {
            // PIN criado com sucesso - autentica
            $promoter_name = $_SESSION['promoter_login_attempt'];
            $_SESSION['selected_promoter'] = $promoter_name;
            $_SESSION['promoter_authenticated'] = true;
            $_SESSION['promoter_id'] = $promoter_id;
            $_SESSION['auth_method'] = 'first_login';

            unset($_SESSION['promoter_login_attempt']);
            unset($_SESSION['promoter_id_temp']);

            logAudit($promoter_id, 'promoter_pin_created', 'promoters', $promoter_id);

            $success_msg = 'PIN criado com sucesso! Você já está autenticado.';
        } else {
            $promoter_auth_error = 'Erro ao criar PIN. Tente novamente.';
            $promoter_auth_step = 'create_pin';
        }
    }
}

// Se há tentativa de login em andamento, continua no fluxo de autenticação
if (isset($_SESSION['promoter_login_attempt']) && empty($promoter_auth_step)) {
    $promoter_id = $_SESSION['promoter_id_temp'];
    $sql = "SELECT pin FROM promoters WHERE id = ?";
    $p = Database::fetchOne($sql, [$promoter_id]);

    if (!empty($p['pin'])) {
        $promoter_auth_step = 'verify_pin';
    } else {
        $promoter_auth_step = 'verify_info';
    }
}

// Define o promotor selecionado (apenas se autenticado)
$selected_promoter = null;
if (isset($_SESSION['promoter_authenticated']) && $_SESSION['promoter_authenticated'] === true) {
    $selected_promoter = $_SESSION['selected_promoter'];
} elseif (isset($_POST['promoter']) && !isset($_POST['promoter_login'])) {
    // Se tentou selecionar diretamente sem login, redireciona para login
    $promoter_name = $_POST['promoter'];
    $_POST['promoter_name'] = $promoter_name;
    $_POST['promoter_login'] = '1';
    // Reprocessa
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$promoters = [];
$promoter_stats = [];
$voucher_packages = [];
$accumulated_balance = [
    'quantity' => 0,
    'total' => 0,
    'commission' => 0
];

// ===== NOVO: Processamento para GODMODE =====
$godmode_data = [];
$godmode_authenticated = isset($_SESSION['godmode_authenticated']) && $_SESSION['godmode_authenticated'] === true;

if ($godmode_enabled && $godmode_authenticated && !empty($available_months)) {
    $godmode_data = calculateGodmodeStats($available_months);
}
// ===== FIM NOVO =====

// Verifica quais migrações já foram executadas
$migrations_done = [];
try {
    $db = Database::getConnection();

    // Verifica coluna 'role' na tabela users
    $result = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    $migrations_done['user_roles'] = !empty($result);

    // Verifica colunas de comprovante na tabela payments
    $result = $db->query("SHOW COLUMNS FROM payments LIKE 'receipt%'")->fetchAll();
    $migrations_done['payment_receipts'] = count($result) > 0;

    // Verifica se índice de comissões gerais existe
    $result = $db->query("SHOW INDEXES FROM promoter_commission_history WHERE Key_name = 'uk_promoter_month_v2'")->fetch();
    $migrations_done['commission_defaults'] = !empty($result);

    // Verifica coluna master_pin na tabela users
    $result = $db->query("SHOW COLUMNS FROM users LIKE 'master_pin'")->fetch();
    $migrations_done['master_pin'] = !empty($result);
} catch (Exception $e) {
    // Se der erro, assume que nada foi migrado
    $migrations_done = [
        'user_roles' => false,
        'payment_receipts' => false,
        'commission_defaults' => false,
        'master_pin' => false
    ];
}

// Busca saldo acumulado dos meses anteriores (do banco de dados)
$accumulated_data = [];
if ($selected_month && $selected_promoter) {
    $accumulated_data = getPromoterAccumulatedBalance($selected_promoter, $selected_month);
    $accumulated_balance = [
        'quantity' => $accumulated_data['total_quantity'],
        'total' => $accumulated_data['total_value'],
        'commission' => $accumulated_data['total_commission']
    ];
}

if ($csv_data) {
    foreach ($csv_data as $row) {
        $voucher = $row['VoucherCode'] ?? '';
        $promoter = trim($row['Promoter'] ?? '', " \"\n\r\t"); // Remove espaços, aspas e quebras de linha
        $campaign = $row['CampaignName'] ?? '';
        
        // Ignora vendas de "Dayuse SITE" - considera apenas "LINK VENDEDOR"
        if (stripos($campaign, 'SITE') !== false) {
            continue;
        }
        
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
        // Comissão será calculada depois com base na configuração

        $promoter_stats[$promoter]['sales'][] = [
            'voucher' => $voucher,
            'sale_id' => $package['sale_id'],
            'status' => $package['status'],
            'campaign' => $package['campaign'],
            'package' => $package['package'],
            'value' => $package['value'],
            'commission' => 0, // Será calculado depois
            'date' => $package['date'],
            'customers' => $package['customers'],
            'items' => $package['items']
        ];
    }

    sort($promoters);
}

// Calcula comissão correta para cada promotor do mês de referência
if ($selected_month && !empty($promoter_stats)) {
    foreach ($promoter_stats as $promoter => &$stats) {
        $commission_config = getPromoterCommissionForMonth($promoter, $selected_month);

        if ($commission_config['type'] === 'fixed') {
            // Valor fixo POR VENDA
            $stats['commission'] = $stats['quantity'] * $commission_config['value'];

            // Atualiza cada venda
            foreach ($stats['sales'] as &$sale) {
                $sale['commission'] = $commission_config['value'];
            }
        } else {
            // Percentual sobre valor total
            $stats['commission'] = $stats['total'] * ($commission_config['value'] / 100);

            // Atualiza cada venda
            foreach ($stats['sales'] as &$sale) {
                $sale['commission'] = $sale['value'] * ($commission_config['value'] / 100);
            }
        }

        $stats['commission_type'] = $commission_config['type'];
        $stats['commission_value'] = $commission_config['value'];
    }
    unset($stats); // Libera referência
}

$is_admin_authenticated = $is_admin_mode && isset($_SESSION['admin_authenticated']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
    <title><?= $is_admin_mode ? 'Administração' : 'Minhas Vendas' ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        
        /* Estilos para tabela GODMODE */
        .godmode-table { width: 100%; margin-top: 20px; }
        .godmode-table th { background: #dc3545; text-align: center; }
        .godmode-table td { text-align: center; vertical-align: middle; }
        .godmode-table .promoter-name { text-align: left; font-weight: 600; }
        .month-column { background: #f8f9fa; }
        .total-column { background: #e7f3ff; font-weight: bold; }
        .select2-container { width: 100% !important; }
        .select2-container--default .select2-selection--single { height: 45px; padding: 8px; border: 2px solid #ddd; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 28px; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 43px; }
        
        /* Modal de Detalhes */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; overflow: auto; padding: 20px; }
        .modal-overlay.active { display: flex; align-items: center; justify-content: center; }
        .modal-content { background: white; border-radius: 15px; max-width: 95%; max-height: 90vh; overflow: auto; box-shadow: 0 10px 50px rgba(0,0,0,0.3); position: relative; }
        .modal-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px 30px; border-radius: 15px 15px 0 0; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10; }
        .modal-header h3 { margin: 0; font-size: 24px; }
        .modal-close { background: rgba(255,255,255,0.2); border: none; color: white; font-size: 28px; cursor: pointer; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s; }
        .modal-close:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }
        .modal-body { padding: 30px; }
        .modal-table { width: 100%; border-collapse: collapse; }
        .modal-table th { background: #667eea; color: white; padding: 12px; text-align: center; font-weight: 600; }
        .modal-table td { padding: 10px 12px; border-bottom: 1px solid #eee; text-align: center; }
        .modal-table tr:hover { background: #f8f9fa; }
        .btn-details { background: #17a2b8; color: white; padding: 6px 12px; border: none; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600; transition: all 0.3s; }
        .btn-details:hover { background: #138496; transform: translateY(-2px); }
        .last-month-column { background: #fff3cd; }
        
        @media print {
            body { background: white; padding: 0; }
            .upload-section, .filter-section, .export-buttons, .btn, .mode-switch, .month-selector, .modal-overlay { display: none !important; }
            .alert[style*="ffc107"] { display: none !important; } /* Oculta o aviso fiscal amarelo */
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
            
            <?php if ($godmode_enabled && !empty($available_months)): ?>
                <!-- MODO GODMODE: Visão Geral de Todos os Consultores -->
                
                <?php if (!$godmode_authenticated): ?>
                    <!-- Login GODMODE -->
                    <div class="login-card">
                        <h3 style="text-align: center; margin-bottom: 20px; color: #dc3545;">
                            <i class="fas fa-crown"></i> Acesso GODMODE
                        </h3>
                        <form method="POST">
                            <div class="form-group">
                                <label>Usuário:</label>
                                <input type="text" name="godmode_username" class="form-control" placeholder="Nome de usuário" required autofocus>
                            </div>
                            <div class="form-group">
                                <label>Senha:</label>
                                <input type="password" name="godmode_password" class="form-control" placeholder="Senha" required>
                            </div>
                            <button type="submit" name="godmode_login" class="btn btn-danger btn-block">
                                <i class="fas fa-sign-in-alt"></i> Entrar
                            </button>
                        </form>
                        <?php if (isset($error_msg)): ?>
                            <div class="alert alert-danger" style="margin-top: 15px;">
                                <i class="fas fa-exclamation-triangle"></i> <?= $error_msg ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h1 style="margin: 0;">
                        <i class="fas fa-crown"></i> Visão Geral - Todos os Consultores
                        <span class="month-badge" style="background: #dc3545;">
                            <i class="fas fa-eye"></i> GODMODE
                        </span>
                    </h1>
                    <div style="display: flex; gap: 10px;">
                        <?php if ($is_main_admin): ?>
                            <button onclick="openUsersModal()" class="btn btn-primary btn-sm">
                                <i class="fas fa-users-cog"></i> Gerenciar Usuários
                            </button>
                        <?php endif; ?>
                        <form method="POST" style="margin: 0;">
                            <button type="submit" name="godmode_logout" class="btn btn-secondary btn-sm">
                                <i class="fas fa-sign-out-alt"></i> Sair (<?= htmlspecialchars($_SESSION['godmode_user'] ?? 'Admin') ?>)
                            </button>
                        </form>
                    </div>
                </div>
                
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
                
                <div class="month-selector" style="border-color: #dc3545; background: #fff3cd;">
                    <label for="godmode_search">
                        <i class="fas fa-search"></i> Buscar Consultor:
                    </label>
                    <select id="godmode_search" class="form-control">
                        <option value="">-- Digite o nome do consultor --</option>
                        <?php foreach (array_keys($godmode_data) as $promoter_name): ?>
                            <option value="<?= htmlspecialchars($promoter_name) ?>">
                                <?= htmlspecialchars($promoter_name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="export-buttons">
                    <button onclick="window.print()" class="btn btn-success">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <button onclick="exportGodmodeToExcel()" class="btn btn-primary">
                        <i class="fas fa-file-excel"></i> Exportar Excel
                    </button>
                </div>
                
                <div class="table-container">
                    <table class="godmode-table" id="godmodeTable">
                        <thead>
                            <tr>
                                <th rowspan="2" style="vertical-align: middle;">Consultor</th>
                                <?php 
                                    // Pega apenas o último mês
                                    $last_month = $available_months[0] ?? null;
                                ?>
                                <?php if ($last_month): ?>
                                    <th colspan="3" class="last-month-column">
                                        <?= htmlspecialchars($last_month['label']) ?>
                                    </th>
                                <?php endif; ?>
                                <th colspan="3" class="total-column">TOTAL ACUMULADO</th>
                                <th rowspan="2" style="vertical-align: middle; width: 120px;">Ações</th>
                            </tr>
                            <tr>
                                <?php if ($last_month): ?>
                                    <th class="last-month-column" style="font-size: 11px;">Vouchers</th>
                                    <th class="last-month-column" style="font-size: 11px;">Valor</th>
                                    <th class="last-month-column" style="font-size: 11px;">Comissão</th>
                                <?php endif; ?>
                                <th class="total-column" style="font-size: 11px;">Vouchers</th>
                                <th class="total-column" style="font-size: 11px;">Valor</th>
                                <th class="total-column" style="font-size: 11px;">Comissão</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($godmode_data as $promoter_name => $promoter_data): ?>
                                <tr class="godmode-row" data-promoter="<?= strtolower(htmlspecialchars($promoter_name)) ?>">
                                    <td class="promoter-name">
                                        <i class="fas fa-user"></i> <?= htmlspecialchars($promoter_name) ?>
                                    </td>
                                    <?php if ($last_month): ?>
                                        <?php 
                                            $month_value = $last_month['value'];
                                            $month_info = $promoter_data['months'][$month_value] ?? null;
                                        ?>
                                        <?php if ($month_info): ?>
                                            <td class="last-month-column"><?= $month_info['vouchers'] ?></td>
                                            <td class="last-month-column">R$ <?= number_format($month_info['value'], 2, ',', '.') ?></td>
                                            <td class="last-month-column" style="color: #28a745; font-weight: 600;">
                                                R$ <?= number_format($month_info['commission'], 2, ',', '.') ?>
                                            </td>
                                        <?php else: ?>
                                            <td class="last-month-column" style="color: #999;">-</td>
                                            <td class="last-month-column" style="color: #999;">-</td>
                                            <td class="last-month-column" style="color: #999;">-</td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <td class="total-column"><?= $promoter_data['total_vouchers'] ?></td>
                                    <td class="total-column">
                                        R$ <?= number_format($promoter_data['total_value'], 2, ',', '.') ?>
                                        <?php if ($promoter_data['paid_value'] > 0): ?>
                                            <br><small style="color: #28a745;">Pago: R$ <?= number_format($promoter_data['paid_value'], 2, ',', '.') ?></small>
                                            <br><small style="color: #dc3545;">Pendente: R$ <?= number_format($promoter_data['unpaid_value'], 2, ',', '.') ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="total-column" style="color: #28a745; font-weight: 600;">
                                        R$ <?= number_format($promoter_data['total_commission'], 2, ',', '.') ?>
                                        <?php if ($promoter_data['paid_commission'] > 0): ?>
                                            <br><small style="color: #28a745;">Pago: R$ <?= number_format($promoter_data['paid_commission'], 2, ',', '.') ?></small>
                                            <br><small style="color: #dc3545;">Pendente: R$ <?= number_format($promoter_data['unpaid_commission'], 2, ',', '.') ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn-details" onclick="openDetailsModal('<?= htmlspecialchars(addslashes($promoter_name)) ?>')">
                                            <i class="fas fa-eye"></i> Ver Detalhes
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($godmode_enabled && $show_total_row): ?>
                        <tfoot>
                            <tr style="background: #667eea; color: white; font-weight: bold;">
                                <td>TOTAL GERAL</td>
                                <?php 
                                    // Calcula total do último mês
                                    $last_month_total = [
                                        'vouchers' => 0,
                                        'value' => 0,
                                        'commission' => 0
                                    ];
                                    
                                    if ($last_month) {
                                        $month_value = $last_month['value'];
                                        foreach ($godmode_data as $promoter_data) {
                                            if (isset($promoter_data['months'][$month_value])) {
                                                $last_month_total['vouchers'] += $promoter_data['months'][$month_value]['vouchers'];
                                                $last_month_total['value'] += $promoter_data['months'][$month_value]['value'];
                                                $last_month_total['commission'] += $promoter_data['months'][$month_value]['commission'];
                                            }
                                        }
                                    }
                                    
                                    // Totais gerais
                                    $grand_total_vouchers = 0;
                                    $grand_total_value = 0;
                                    $grand_total_commission = 0;
                                    
                                    foreach ($godmode_data as $promoter_data) {
                                        $grand_total_vouchers += $promoter_data['total_vouchers'];
                                        $grand_total_value += $promoter_data['total_value'];
                                        $grand_total_commission += $promoter_data['total_commission'];
                                    }
                                ?>
                                <?php if ($last_month): ?>
                                    <td><?= $last_month_total['vouchers'] ?></td>
                                    <td>R$ <?= number_format($last_month_total['value'], 2, ',', '.') ?></td>
                                    <td>R$ <?= number_format($last_month_total['commission'], 2, ',', '.') ?></td>
                                <?php endif; ?>
                                <td><?= $grand_total_vouchers ?></td>
                                <td>R$ <?= number_format($grand_total_value, 2, ',', '.') ?></td>
                                <td>R$ <?= number_format($grand_total_commission, 2, ',', '.') ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                
                <!-- Modal de Detalhes -->
                <div class="modal-overlay" id="detailsModal" onclick="closeModalOnOverlay(event)">
                    <div class="modal-content" onclick="event.stopPropagation()">
                        <div class="modal-header">
                            <h3>
                                <i class="fas fa-chart-bar"></i> 
                                <span id="modalPromoterName"></span> - Detalhamento por Mês
                            </h3>
                            <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div style="margin-bottom: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                                <button onclick="selectAllPayments(true)" class="btn btn-success btn-sm">
                                    <i class="fas fa-check-double"></i> Marcar Todos como Pago
                                </button>
                                <button onclick="selectAllPayments(false)" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-times"></i> Desmarcar Todos
                                </button>
                            </div>
                            <table class="modal-table">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">Pago</th>
                                        <th>Mês/Ano</th>
                                        <th>Vouchers</th>
                                        <th>Valor Total</th>
                                        <th>Comissão</th>
                                        <th>Status</th>
                                        <th style="width: 120px;">Comprovante</th>
                                    </tr>
                                </thead>
                                <tbody id="modalTableBody">
                                    <!-- Conteúdo preenchido via JavaScript -->
                                </tbody>
                                <tfoot>
                                    <tr style="background: #667eea; color: white; font-weight: bold;">
                                        <td></td>
                                        <td>TOTAL</td>
                                        <td id="modalTotalVouchers"></td>
                                        <td id="modalTotalValue"></td>
                                        <td id="modalTotalCommission"></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                    <tr style="background: #28a745; color: white; font-weight: bold;">
                                        <td colspan="2">TOTAL PAGO</td>
                                        <td id="modalPaidVouchers"></td>
                                        <td id="modalPaidValue"></td>
                                        <td id="modalPaidCommission"></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                    <tr style="background: #dc3545; color: white; font-weight: bold;">
                                        <td colspan="2">TOTAL PENDENTE</td>
                                        <td id="modalUnpaidVouchers"></td>
                                        <td id="modalUnpaidValue"></td>
                                        <td id="modalUnpaidCommission"></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Modal de Upload de Comprovante -->
                <div class="modal-overlay" id="receiptModal" onclick="closeReceiptModal(event)" style="display: none;">
                    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 600px;">
                        <div class="modal-header">
                            <h3>
                                <i class="fas fa-receipt"></i>
                                Upload de Comprovante de Pagamento
                            </h3>
                            <button class="modal-close" onclick="closeReceiptModal()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                                <h4 style="margin-bottom: 10px; color: #333;">
                                    <i class="fas fa-user"></i> <span id="receiptPromoterName"></span>
                                </h4>
                                <p style="margin: 0; color: #666;">
                                    <i class="fas fa-calendar"></i> Mês: <strong id="receiptMonth"></strong>
                                </p>
                            </div>

                            <form id="receiptUploadForm" enctype="multipart/form-data">
                                <input type="hidden" id="receipt_promoter" name="promoter">
                                <input type="hidden" id="receipt_month" name="month">

                                <!-- Modo de Armazenamento -->
                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                                        <i class="fas fa-database"></i> Modo de Armazenamento:
                                    </label>

                                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
                                        <label style="display: flex; flex-direction: column; align-items: center; padding: 15px; border: 2px solid #17a2b8; border-radius: 8px; cursor: pointer; transition: all 0.3s;" class="storage-option">
                                            <input type="radio" name="storage_mode" value="file" checked style="margin-bottom: 5px;">
                                            <i class="fas fa-folder" style="font-size: 24px; margin-bottom: 5px; color: #17a2b8;"></i>
                                            <strong style="font-size: 13px;">Arquivo</strong>
                                            <small style="text-align: center; color: #666; margin-top: 5px;">Salva no servidor</small>
                                        </label>

                                        <label style="display: flex; flex-direction: column; align-items: center; padding: 15px; border: 2px solid #6f42c1; border-radius: 8px; cursor: pointer; transition: all 0.3s;" class="storage-option">
                                            <input type="radio" name="storage_mode" value="base64" style="margin-bottom: 5px;">
                                            <i class="fas fa-database" style="font-size: 24px; margin-bottom: 5px; color: #6f42c1;"></i>
                                            <strong style="font-size: 13px;">Base64</strong>
                                            <small style="text-align: center; color: #666; margin-top: 5px;">Salva no banco</small>
                                        </label>

                                        <label style="display: flex; flex-direction: column; align-items: center; padding: 15px; border: 2px solid #6c757d; border-radius: 8px; cursor: pointer; transition: all 0.3s;" class="storage-option">
                                            <input type="radio" name="storage_mode" value="none" style="margin-bottom: 5px;">
                                            <i class="fas fa-times-circle" style="font-size: 24px; margin-bottom: 5px; color: #6c757d;"></i>
                                            <strong style="font-size: 13px;">Nenhum</strong>
                                            <small style="text-align: center; color: #666; margin-top: 5px;">Remover</small>
                                        </label>
                                    </div>
                                </div>

                                <!-- Upload de Arquivo -->
                                <div id="fileUploadSection" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                                        <i class="fas fa-file-upload"></i> Selecione o Arquivo:
                                    </label>
                                    <input type="file" id="receipt_file" name="receipt" accept="image/*,application/pdf"
                                           class="form-control" style="padding: 10px;">
                                    <small style="display: block; margin-top: 5px; color: #666;">
                                        <i class="fas fa-info-circle"></i> Formatos aceitos: JPG, PNG, GIF, WEBP, PDF (máx 10MB)
                                    </small>
                                </div>

                                <!-- Preview -->
                                <div id="receiptPreview" style="display: none; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                                    <p style="margin-bottom: 10px; font-weight: 600;">Preview:</p>
                                    <img id="previewImage" style="max-width: 100%; max-height: 300px; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                                </div>

                                <!-- Botões -->
                                <div style="display: flex; gap: 10px; margin-top: 20px;">
                                    <button type="button" onclick="closeReceiptModal()" class="btn btn-secondary" style="flex: 1;">
                                        <i class="fas fa-times"></i> Cancelar
                                    </button>
                                    <button type="submit" class="btn btn-success" style="flex: 2;">
                                        <i class="fas fa-upload"></i> <span id="uploadButtonText">Enviar Comprovante</span>
                                    </button>
                                </div>

                                <!-- Mensagem de Progresso -->
                                <div id="uploadProgress" style="display: none; margin-top: 15px; padding: 10px; background: #d1ecf1; border-radius: 5px; text-align: center; color: #0c5460;">
                                    <i class="fas fa-spinner fa-spin"></i> Enviando...
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Modal de Gestão de Usuários -->
                <?php if ($is_main_admin): ?>
                <div class="modal-overlay" id="usersModal" onclick="closeModalOnOverlay(event)">
                    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 900px;">
                        <div class="modal-header">
                            <h3>
                                <i class="fas fa-users-cog"></i> Gerenciar Usuários GODMODE
                            </h3>
                            <button class="modal-close" onclick="closeUsersModal()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <!-- Formulário Adicionar Usuário -->
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                                <h4 style="margin-bottom: 15px;"><i class="fas fa-user-plus"></i> Adicionar Novo Usuário</h4>
                                <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">Usuário:</label>
                                        <input type="text" name="new_username" class="form-control" required placeholder="username">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">Nome Completo:</label>
                                        <input type="text" name="new_name" class="form-control" required placeholder="Nome completo">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">Senha:</label>
                                        <input type="password" name="new_password" class="form-control" required placeholder="Senha">
                                    </div>
                                    <div style="display: flex; align-items: flex-end;">
                                        <button type="submit" name="add_user" class="btn btn-success" style="width: 100%;">
                                            <i class="fas fa-plus"></i> Adicionar
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Lista de Usuários -->
                            <h4 style="margin-bottom: 15px;"><i class="fas fa-list"></i> Usuários Cadastrados</h4>
                            <table class="modal-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Usuário</th>
                                        <th>Nome</th>
                                        <th>Criado em</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $users_data = loadUsers();
                                    foreach ($users_data['users'] as $user): 
                                    ?>
                                        <tr>
                                            <td><?= $user['id'] ?></td>
                                            <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                            <td><?= htmlspecialchars($user['name']) ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                                            <td>
                                                <?php if ($user['active']): ?>
                                                    <span class="badge badge-success">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 5px; justify-content: center;">
                                                    <button onclick="editUserModal(<?= htmlspecialchars(json_encode($user)) ?>)" 
                                                            class="btn btn-primary btn-sm" style="padding: 5px 10px;">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <?php if ($user['id'] != 1): ?>
                                                        <form method="POST" style="margin: 0; display: inline;">
                                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                            <input type="hidden" name="active" value="<?= $user['active'] ? '0' : '1' ?>">
                                                            <button type="submit" name="toggle_user" 
                                                                    class="btn btn-<?= $user['active'] ? 'warning' : 'success' ?> btn-sm" 
                                                                    style="padding: 5px 10px;">
                                                                <i class="fas fa-<?= $user['active'] ? 'ban' : 'check' ?>"></i>
                                                            </button>
                                                        </form>
                                                        
                                                        <form method="POST" style="margin: 0; display: inline;" 
                                                              onsubmit="return confirm('Deseja realmente deletar este usuário?')">
                                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                            <button type="submit" name="delete_user" 
                                                                    class="btn btn-danger btn-sm" style="padding: 5px 10px;">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Editar Usuário -->
                <div class="modal-overlay" id="editUserModal" onclick="closeModalOnOverlay(event)">
                    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 500px;">
                        <div class="modal-header">
                            <h3><i class="fas fa-user-edit"></i> Editar Usuário</h3>
                            <button class="modal-close" onclick="closeEditUserModal()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <form method="POST" id="editUserForm">
                                <input type="hidden" name="edit_id" id="edit_id">

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Usuário:</label>
                                    <input type="text" name="edit_username" id="edit_username" class="form-control" required>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Nome Completo:</label>
                                    <input type="text" name="edit_name" id="edit_name" class="form-control" required>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                                        <i class="fas fa-shield-alt"></i> Nível de Acesso:
                                    </label>
                                    <select name="edit_role" id="edit_role" class="form-control" required>
                                        <option value="viewer">Visualizador (apenas leitura)</option>
                                        <option value="admin">Administrador (editar e gerenciar)</option>
                                        <option value="superadmin">Super Administrador (acesso total)</option>
                                    </select>
                                    <small style="color: #666; display: block; margin-top: 5px;">
                                        <strong>Visualizador:</strong> Apenas visualiza dados<br>
                                        <strong>Admin:</strong> Pode editar pagamentos e dados<br>
                                        <strong>Super Admin:</strong> Acesso total + logs + usuários
                                    </small>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Nova Senha:</label>
                                    <input type="password" name="edit_password" id="edit_password" class="form-control" placeholder="Deixe em branco para manter a atual">
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                                        <i class="fas fa-key"></i> PIN Mestre (Admin):
                                    </label>
                                    <input type="text" name="edit_master_pin" id="edit_master_pin" class="form-control" placeholder="4 dígitos para acesso rápido a relatórios" maxlength="10" pattern="[0-9]{4,10}">
                                    <small style="color: #666; display: block; margin-top: 5px;">
                                        PIN pessoal do admin para acessar relatórios de consultores rapidamente. Deixe em branco para não alterar.
                                    </small>
                                </div>

                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" name="edit_user" class="btn btn-primary" style="flex: 1;">
                                        <i class="fas fa-save"></i> Salvar
                                    </button>
                                    <button type="button" onclick="closeEditUserModal()" class="btn btn-secondary" style="flex: 1;">
                                        <i class="fas fa-times"></i> Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <script>
                // Dados dos consultores em formato JSON para o modal
                const promotersData = <?= json_encode($godmode_data) ?>;
                const availableMonths = <?= json_encode(array_reverse($available_months)) ?>;
                </script>
                <script src="godmode.js"></script>
                
                <?php endif; // fim godmode_authenticated ?>
                
            <?php else: ?>
            
            <h1>
                <i class="fas fa-chart-line"></i> Minhas Vendas
                <?php if (!empty($available_months) && $selected_month): ?>
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
                            <option value="">-- Selecione o período --</option>
                            <?php foreach ($available_months as $month): ?>
                                <option value="<?= htmlspecialchars($month['value']) ?>" <?= $selected_month === $month['value'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($month['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php endif; ?>
            
            <?php if (empty($available_months)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i>
                    <h4>Nenhum arquivo disponível</h4>
                    <p>Aguarde o administrador carregar os dados de vendas.</p>
                </div>
            <?php elseif (!$selected_month): ?>
                <div class="alert alert-info">
                    <i class="fas fa-hand-point-up"></i>
                    <h4>Selecione um período</h4>
                    <p>Por favor, selecione um mês acima para visualizar suas vendas.</p>
                </div>
            <?php elseif ($csv_data && count($promoters) > 0): ?>
                <div class="filter-section">
                    <?php if (!empty($promoter_auth_error)): ?>
                        <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($promoter_auth_error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($selected_promoter && isset($_SESSION['promoter_authenticated'])): ?>
                        <!-- Promotor já autenticado -->
                        <div style="background: #d4edda; border: 2px solid #c3e6cb; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h4 style="margin: 0; color: #155724;">
                                        <i class="fas fa-user-check"></i> Olá, <?= htmlspecialchars($selected_promoter) ?>!
                                    </h4>
                                    <small style="color: #155724;">Você está autenticado e pode visualizar suas comissões.</small>
                                </div>
                                <form method="POST" style="margin: 0;">
                                    <button type="submit" name="promoter_logout" class="btn" style="background: #856404; color: white;">
                                        <i class="fas fa-sign-out-alt"></i> Sair
                                    </button>
                                </form>
                            </div>
                        </div>

                    <?php elseif ($promoter_auth_step === 'verify_pin'): ?>
                        <!-- Pede PIN -->
                        <div style="background: #fff3cd; border: 2px solid #ffc107; padding: 20px; border-radius: 10px;">
                            <h4 style="color: #856404; margin-bottom: 15px;">
                                <i class="fas fa-lock"></i> Digite seu PIN
                            </h4>
                            <p style="color: #856404; margin-bottom: 15px;">
                                Olá, <strong><?= htmlspecialchars($_SESSION['promoter_login_attempt']) ?></strong>!
                                Por favor, digite seu PIN de 4 dígitos para continuar.
                            </p>
                            <form method="POST" style="max-width: 400px;">
                                <input type="hidden" name="reference_month" value="<?= htmlspecialchars($selected_month) ?>">
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">PIN:</label>
                                    <input type="password" name="pin" maxlength="8" class="form-control"
                                           placeholder="Digite seu PIN" required autofocus
                                           style="font-size: 18px; letter-spacing: 2px; text-align: center;">
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" name="verify_pin" class="btn btn-success">
                                        <i class="fas fa-check"></i> Verificar PIN
                                    </button>
                                    <button type="submit" name="promoter_logout" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php elseif ($promoter_auth_step === 'verify_info'): ?>
                        <!-- Pede verificação de informações pessoais -->
                        <div style="background: #d1ecf1; border: 2px solid #17a2b8; padding: 20px; border-radius: 10px;">
                            <h4 style="color: #0c5460; margin-bottom: 15px;">
                                <i class="fas fa-user-shield"></i> Verificação de Identidade
                            </h4>
                            <p style="color: #0c5460; margin-bottom: 15px;">
                                Olá, <strong><?= htmlspecialchars($_SESSION['promoter_login_attempt']) ?></strong>!<br>
                                Para sua segurança, precisamos verificar sua identidade. Escolha uma das opções abaixo:
                            </p>
                            <form method="POST" style="max-width: 500px;">
                                <input type="hidden" name="reference_month" value="<?= htmlspecialchars($selected_month) ?>">
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Tipo de Verificação:</label>
                                    <select name="verification_type" id="verification_type" class="form-control" required
                                            onchange="updateVerificationPlaceholder()">
                                        <option value="">-- Selecione --</option>
                                        <option value="cpf_last4">Últimos 4 dígitos do CPF</option>
                                        <option value="cpf_first4">Primeiros 4 dígitos do CPF</option>
                                        <option value="middle_name">Nome do meio</option>
                                    </select>
                                </div>
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Informação:</label>
                                    <input type="text" name="verification_value" id="verification_value"
                                           class="form-control" placeholder="Digite a informação" required
                                           style="font-size: 16px;">
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" name="verify_info" class="btn btn-info">
                                        <i class="fas fa-check-circle"></i> Verificar
                                    </button>
                                    <button type="submit" name="promoter_logout" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Cancelar
                                    </button>
                                </div>
                            </form>
                            <script>
                                function updateVerificationPlaceholder() {
                                    const type = document.getElementById('verification_type').value;
                                    const input = document.getElementById('verification_value');

                                    switch(type) {
                                        case 'cpf_last4':
                                            input.placeholder = 'Digite os 4 últimos dígitos do CPF';
                                            input.maxLength = 4;
                                            input.type = 'number';
                                            break;
                                        case 'cpf_first4':
                                            input.placeholder = 'Digite os 4 primeiros dígitos do CPF';
                                            input.maxLength = 4;
                                            input.type = 'number';
                                            break;
                                        case 'middle_name':
                                            input.placeholder = 'Digite seu nome do meio';
                                            input.maxLength = 50;
                                            input.type = 'text';
                                            break;
                                        default:
                                            input.placeholder = 'Digite a informação';
                                            input.type = 'text';
                                    }
                                }
                            </script>
                        </div>

                    <?php elseif ($promoter_auth_step === 'create_pin'): ?>
                        <!-- Criar novo PIN -->
                        <div style="background: #d4edda; border: 2px solid #c3e6cb; padding: 20px; border-radius: 10px;">
                            <h4 style="color: #155724; margin-bottom: 15px;">
                                <i class="fas fa-key"></i> Criar seu PIN
                            </h4>
                            <p style="color: #155724; margin-bottom: 15px;">
                                <strong>Parabéns, <?= htmlspecialchars($_SESSION['promoter_login_attempt']) ?>!</strong><br>
                                Sua identidade foi verificada. Agora crie um PIN de 4 a 8 dígitos para facilitar seus próximos acessos.
                            </p>
                            <form method="POST" style="max-width: 400px;">
                                <input type="hidden" name="reference_month" value="<?= htmlspecialchars($selected_month) ?>">
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Novo PIN:</label>
                                    <input type="password" name="new_pin" class="form-control"
                                           placeholder="Digite seu novo PIN (4-8 dígitos)" required
                                           minlength="4" maxlength="8"
                                           style="font-size: 18px; letter-spacing: 2px; text-align: center;">
                                </div>
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Confirmar PIN:</label>
                                    <input type="password" name="confirm_pin" class="form-control"
                                           placeholder="Digite novamente" required
                                           minlength="4" maxlength="8"
                                           style="font-size: 18px; letter-spacing: 2px; text-align: center;">
                                </div>
                                <button type="submit" name="create_pin" class="btn btn-success">
                                    <i class="fas fa-save"></i> Criar PIN e Entrar
                                </button>
                            </form>
                        </div>

                    <?php else: ?>
                        <!-- Seleção de nome (login) -->
                        <form method="POST">
                            <input type="hidden" name="reference_month" value="<?= htmlspecialchars($selected_month) ?>">
                            <label for="promoter_name" style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                                <i class="fas fa-user"></i> Selecione seu nome para fazer login:
                            </label>
                            <div style="display: grid; grid-template-columns: 1fr auto; gap: 10px;">
                                <select name="promoter_name" id="promoter_name" class="form-control select2-promoter" required style="width: 100%;">
                                    <option value="">-- Digite ou selecione seu nome --</option>
                                    <?php foreach ($promoters as $promoter): ?>
                                        <option value="<?= htmlspecialchars($promoter) ?>">
                                            <?= htmlspecialchars(maskMiddleNames($promoter)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="promoter_login" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt"></i> Entrar
                                </button>
                            </div>
                            <?php if ($godmode_enabled && $godmode_authenticated): ?>
                                <!-- Opção de master code para admins -->
                                <div style="margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 8px; border: 1px solid #ffc107;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 10px;">
                                        <input type="checkbox" id="use_master_code" onchange="toggleMasterCode()">
                                        <span style="font-size: 13px; font-weight: 600; color: #856404;">
                                            <i class="fas fa-unlock"></i> Usar Código Mestre (Admin)
                                        </span>
                                    </label>
                                    <div id="master_code_field" style="display: none;">
                                        <input type="password" name="master_code" class="form-control"
                                               placeholder="Digite o código mestre" style="margin-bottom: 10px;">
                                        <button type="submit" name="verify_master_code" class="btn btn-warning btn-sm">
                                            <i class="fas fa-key"></i> Acessar com Código Mestre
                                        </button>
                                    </div>
                                </div>
                                <script>
                                    function toggleMasterCode() {
                                        const checkbox = document.getElementById('use_master_code');
                                        const field = document.getElementById('master_code_field');
                                        field.style.display = checkbox.checked ? 'block' : 'none';
                                    }
                                </script>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
                
                <?php if ($selected_promoter && isset($promoter_stats[$selected_promoter])): ?>
                    <?php $stats = $promoter_stats[$selected_promoter]; ?>
                    
                    <?php if (!empty($accumulated_data['months'])): ?>
                        <div class="alert alert-info" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white; border: none; padding: 20px; margin-bottom: 20px;">
                            <h4 style="margin-bottom: 15px; text-align: center;">
                                <i class="fas fa-history"></i> Histórico de Vendas por Mês
                            </h4>

                            <!-- Detalhamento por Mês -->
                            <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                <table style="width: 100%; color: white;">
                                    <thead>
                                        <tr style="border-bottom: 2px solid rgba(255,255,255,0.3);">
                                            <th style="padding: 10px; text-align: left;">Mês</th>
                                            <th style="padding: 10px; text-align: center;">Vouchers</th>
                                            <th style="padding: 10px; text-align: right;">Valor</th>
                                            <th style="padding: 10px; text-align: center;">%</th>
                                            <th style="padding: 10px; text-align: right;">Comissão</th>
                                            <th style="padding: 10px; text-align: center;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($accumulated_data['months'] as $month_data):
                                            list($y, $m) = explode('-', $month_data['month']);
                                            $months_pt = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr',
                                                          '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
                                                          '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];
                                        ?>
                                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                                                <td style="padding: 10px; font-weight: bold;"><?= $months_pt[$m] . '/' . $y ?></td>
                                                <td style="padding: 10px; text-align: center;"><?= $month_data['quantity'] ?></td>
                                                <td style="padding: 10px; text-align: right;">R$ <?= number_format($month_data['total'], 2, ',', '.') ?></td>
                                                <td style="padding: 10px; text-align: center; font-weight: bold; color: #ffd700;"><?= number_format($month_data['commission_percentage'], 1) ?>%</td>
                                                <td style="padding: 10px; text-align: right;">R$ <?= number_format($month_data['commission'], 2, ',', '.') ?></td>
                                                <td style="padding: 10px; text-align: center;">
                                                    <?php if ($month_data['paid']): ?>
                                                        <span style="background: #28a745; padding: 5px 12px; border-radius: 15px; font-size: 12px; font-weight: bold;">
                                                            <i class="fas fa-check-circle"></i> PAGO
                                                        </span>
                                                        <?php if ($month_data['paid_at']): ?>
                                                            <br><small style="font-size: 10px; opacity: 0.8;"><?= date('d/m/Y', strtotime($month_data['paid_at'])) ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="background: #dc3545; padding: 5px 12px; border-radius: 15px; font-size: 12px; font-weight: bold;">
                                                            <i class="fas fa-clock"></i> PENDENTE
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Totais (apenas meses não pagos) -->
                            <?php if ($accumulated_balance['quantity'] > 0): ?>
                                <div style="border-top: 2px solid rgba(255,255,255,0.3); padding-top: 15px;">
                                    <h5 style="margin-bottom: 10px; text-align: center;">
                                        <i class="fas fa-exclamation-circle"></i> Total Pendente (Meses Não Pagos)
                                    </h5>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                                        <div style="text-align: center;">
                                            <div style="font-size: 12px; opacity: 0.9; margin-bottom: 5px;">Vouchers</div>
                                            <div style="font-size: 24px; font-weight: bold;"><?= $accumulated_balance['quantity'] ?></div>
                                        </div>
                                        <div style="text-align: center;">
                                            <div style="font-size: 12px; opacity: 0.9; margin-bottom: 5px;">Valor Total</div>
                                            <div style="font-size: 24px; font-weight: bold;">R$ <?= number_format($accumulated_balance['total'], 2, ',', '.') ?></div>
                                        </div>
                                        <div style="text-align: center;">
                                            <div style="font-size: 12px; opacity: 0.9; margin-bottom: 5px;">Comissão</div>
                                            <div style="font-size: 24px; font-weight: bold;">R$ <?= number_format($accumulated_balance['commission'], 2, ',', '.') ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="stats-card">
                        <h2 style="margin-bottom: 20px; text-align: center;">
                            <i class="fas fa-user-circle"></i> <?= htmlspecialchars($selected_promoter) ?>
                            <br>
                            <small style="font-size: 16px; opacity: 0.9; font-weight: normal;">
                                Mês de Referência: 
                                <?php
                                    list($year, $month) = explode('-', $selected_month);
                                    $months_pt = ['01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril', 
                                                  '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
                                                  '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'];
                                    echo $months_pt[$month] . '/' . $year;
                                ?>
                            </small>
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
                                <div class="stat-label">
                                    <i class="fas fa-hand-holding-usd"></i> Comissão
                                    <?php if (isset($stats['commission_type'])): ?>
                                        (<?= $stats['commission_type'] === 'fixed' ? 'R$ ' . number_format($stats['commission_value'], 2, ',', '.') . '/venda' : number_format($stats['commission_value'], 2, ',', '.') . '%' ?>)
                                    <?php endif; ?>
                                </div>
                                <div class="stat-value">R$ <?= number_format($stats['commission'], 2, ',', '.') ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($accumulated_balance['quantity'] > 0): ?>
                        <div class="stats-card" style="background: linear-gradient(135deg, #28a745 0%, #218838 100%); margin-top: 20px;">
                            <h3 style="margin-bottom: 15px; text-align: center;">
                                <i class="fas fa-calculator"></i> Total Geral (Todos os Meses)
                            </h3>
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-label"><i class="fas fa-shopping-cart"></i> Vouchers</div>
                                    <div class="stat-value"><?= $accumulated_balance['quantity'] ?></div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label"><i class="fas fa-dollar-sign"></i> Valor Total</div>
                                    <div class="stat-value">R$ <?= number_format($accumulated_balance['total'], 2, ',', '.') ?></div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label"><i class="fas fa-hand-holding-usd"></i> Comissão</div>
                                    <div class="stat-value">R$ <?= number_format($accumulated_balance['commission'], 2, ',', '.') ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert" style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #000; border: 3px solid #ff6b6b; margin-top: 20px; padding: 25px; box-shadow: 0 4px 15px rgba(255,107,107,0.3);">
                            <h4 style="margin-bottom: 15px; text-align: center; color: #000;">
                                <i class="fas fa-exclamation-triangle"></i> ATENÇÃO - INFORMAÇÃO FISCAL IMPORTANTE
                            </h4>
                            <div style="background: white; padding: 20px; border-radius: 8px; border-left: 5px solid #dc3545;">
                                <p style="margin: 0 0 15px 0; font-size: 16px; font-weight: 600; color: #333;">
                                    <i class="fas fa-file-invoice-dollar" style="color: #dc3545;"></i> 
                                    Caso você já tenha recebido o pagamento dos meses anteriores:
                                </p>
                                <div style="background: #e7f3ff; padding: 15px; border-radius: 5px; border-left: 4px solid #667eea; margin-bottom: 15px;">
                                    <p style="margin: 0 0 10px 0; color: #333; font-size: 15px;">
                                        <strong>Para evitar problemas fiscais, recusas e cancelamentos de nota fiscal:</strong>
                                    </p>
                                    <p style="margin: 0; color: #333; font-size: 15px;">
                                        <i class="fas fa-arrow-right" style="color: #667eea;"></i> 
                                        Emita a nota fiscal <strong>APENAS</strong> com o valor do mês de referência: 
                                        <strong style="color: #dc3545;">
                                            <?php
                                                list($year, $month) = explode('-', $selected_month);
                                                $months_pt = ['01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril', 
                                                              '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
                                                              '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'];
                                                echo $months_pt[$month] . '/' . $year;
                                            ?>
                                        </strong>
                                    </p>
                                </div>
                                <div style="background: #f8d7da; padding: 15px; border-radius: 5px; border-left: 4px solid #dc3545;">
                                    <p style="margin: 0; color: #721c24; font-size: 14px;">
                                        <i class="fas fa-check-circle" style="color: #28a745;"></i> 
                                        <strong>Valor a ser informado na nota:</strong> R$ <?= number_format($stats['commission'], 2, ',', '.') ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
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
                    <i class="fas fa-exclamation-triangle"></i> Nenhum consultor encontrado neste período.
                </div>
            <?php endif; ?>
            
            <?php endif; // fim else godmode ?>
            
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
                            <label>Usuário:</label>
                            <input type="text" name="admin_username" class="form-control" required autofocus placeholder="Digite o nome de usuário">
                        </div>
                        <div class="form-group">
                            <label>Senha:</label>
                            <input type="password" name="admin_password" class="form-control" required placeholder="Digite a senha">
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
                        <div style="display: flex; gap: 10px;">
                            <?php 
                            // Debug: sempre mostrar o botão para admin autenticado
                            if ($is_admin_authenticated): 
                            ?>
                                <button onclick="openUsersModal()" class="btn btn-primary btn-sm">
                                    <i class="fas fa-users-cog"></i> Gerenciar Usuários
                                </button>
                            <?php endif; ?>
                            <form method="POST" style="margin: 0;">
                                <button type="submit" name="admin_logout" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-sign-out-alt"></i> Sair
                                </button>
                            </form>
                        </div>
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
                        <div style="flex: 1; min-width: 200px;">
                            <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                                <i class="fas fa-database"></i> Opções:
                            </label>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="import_to_db" value="1" checked>
                                    <span style="font-size: 13px;">Importar para Banco de Dados</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="replace_data" value="1">
                                    <span style="font-size: 13px; color: #dc3545;">Substituir dados existentes</span>
                                </label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-danger" style="align-self: flex-end;">
                            <i class="fas fa-upload"></i> Enviar
                        </button>
                    </form>

                    <?php
                    // Busca meses no banco de dados
                    $months_in_db = getMonthsInDatabase();
                    if (!empty($months_in_db)):
                    ?>
                        <div style="margin-top: 30px; padding: 20px; background: #e7f3ff; border-radius: 10px; border: 2px solid #667eea;">
                            <h5 style="margin-bottom: 15px; color: #333;">
                                <i class="fas fa-database"></i> Dados no Banco de Dados
                            </h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                                <?php foreach ($months_in_db as $month_db):
                                    list($year, $month) = explode('-', $month_db['month_reference']);
                                    $months_pt = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr',
                                                  '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
                                                  '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];
                                    $month_label = $months_pt[$month] . '/' . $year;
                                ?>
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #667eea;">
                                        <div style="font-weight: bold; color: #333; font-size: 16px; margin-bottom: 10px;">
                                            <i class="fas fa-calendar-check"></i> <?= htmlspecialchars($month_label) ?>
                                        </div>

                                        <!-- Vendas de Promotores -->
                                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 12px; border-radius: 6px; margin-bottom: 10px; color: white;">
                                            <div style="font-size: 11px; opacity: 0.9; margin-bottom: 5px; font-weight: 600;">
                                                <i class="fas fa-users"></i> VENDAS DE CONSULTORES
                                            </div>
                                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; font-size: 11px;">
                                                <div>
                                                    <span style="opacity: 0.8;">Registros:</span>
                                                    <strong style="display: block; font-size: 16px;"><?= number_format($month_db['promoter_records']) ?></strong>
                                                </div>
                                                <div>
                                                    <span style="opacity: 0.8;">Vouchers:</span>
                                                    <strong style="display: block; font-size: 16px;"><?= number_format($month_db['promoter_vouchers']) ?></strong>
                                                </div>
                                                <div>
                                                    <span style="opacity: 0.8;">Consultores:</span>
                                                    <strong style="display: block; font-size: 16px;"><?= $month_db['promoter_count'] ?></strong>
                                                </div>
                                                <div>
                                                    <span style="opacity: 0.8;">Valor:</span>
                                                    <strong style="display: block; font-size: 14px;">R$ <?= number_format($month_db['promoter_value'], 2, ',', '.') ?></strong>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Vendas do Site -->
                                        <?php if ($month_db['site_records'] > 0): ?>
                                        <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 12px; border-radius: 6px; margin-bottom: 10px; color: white;">
                                            <div style="font-size: 11px; opacity: 0.9; margin-bottom: 5px; font-weight: 600;">
                                                <i class="fas fa-globe"></i> VENDAS DO SITE (sem promotor)
                                            </div>
                                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; font-size: 11px;">
                                                <div>
                                                    <span style="opacity: 0.8;">Registros:</span>
                                                    <strong style="display: block; font-size: 16px;"><?= number_format($month_db['site_records']) ?></strong>
                                                </div>
                                                <div>
                                                    <span style="opacity: 0.8;">Vouchers:</span>
                                                    <strong style="display: block; font-size: 16px;"><?= number_format($month_db['site_vouchers']) ?></strong>
                                                </div>
                                                <div colspan="2">
                                                    <span style="opacity: 0.8;">Valor:</span>
                                                    <strong style="display: block; font-size: 14px;">R$ <?= number_format($month_db['site_value'], 2, ',', '.') ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Total Geral -->
                                        <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; border: 2px dashed #667eea;">
                                            <div style="font-size: 11px; color: #667eea; font-weight: 600; margin-bottom: 5px;">
                                                <i class="fas fa-calculator"></i> TOTAL GERAL
                                            </div>
                                            <div style="font-size: 12px; color: #333; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                                <div>
                                                    <span style="opacity: 0.7;">Registros:</span>
                                                    <strong><?= number_format($month_db['total_records']) ?></strong>
                                                </div>
                                                <div>
                                                    <span style="opacity: 0.7;">Vouchers:</span>
                                                    <strong><?= number_format($month_db['total_vouchers']) ?></strong>
                                                </div>
                                                <div style="flex-basis: 100%;">
                                                    <span style="opacity: 0.7;">Valor Total:</span>
                                                    <strong style="color: #667eea; font-size: 16px;">R$ <?= number_format($month_db['total_value'], 2, ',', '.') ?></strong>
                                                </div>
                                            </div>
                                        </div>

                                        <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #ddd; font-size: 11px; color: #999;">
                                            <i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($month_db['last_import'])) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Ferramentas de Gerenciamento -->
                            <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #667eea;">
                                <h6 style="color: #333; margin-bottom: 15px;">
                                    <i class="fas fa-tools"></i> Ferramentas de Gerenciamento
                                </h6>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                                    <!-- Remover Duplicatas -->
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #17a2b8;">
                                        <h6 style="color: #0c5460; margin-bottom: 10px;">
                                            <i class="fas fa-copy"></i> Remover Duplicatas
                                        </h6>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                            Remove registros duplicados mantendo apenas a versão mais recente
                                        </p>
                                        <form method="POST" onsubmit="return confirm('Deseja remover todas as duplicatas? Esta ação não pode ser desfeita!')">
                                            <button type="submit" name="remove_duplicates" class="btn btn-info btn-sm" style="width: 100%;">
                                                <i class="fas fa-broom"></i> Limpar Duplicatas
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Deletar Mês Específico -->
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #ffc107;">
                                        <h6 style="color: #856404; margin-bottom: 10px;">
                                            <i class="fas fa-calendar-times"></i> Deletar Mês
                                        </h6>
                                        <form method="POST" onsubmit="return confirm('ATENÇÃO: Todos os dados do mês selecionado serão deletados permanentemente!')">
                                            <select name="month_to_delete" class="form-control" required style="margin-bottom: 10px; font-size: 12px;">
                                                <option value="">-- Selecione --</option>
                                                <?php foreach ($months_in_db as $m): ?>
                                                    <option value="<?= htmlspecialchars($m['month_reference']) ?>">
                                                        <?php
                                                        list($y, $mon) = explode('-', $m['month_reference']);
                                                        $months_pt = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr',
                                                                      '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
                                                                      '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];
                                                        echo $months_pt[$mon] . '/' . $y;
                                                        ?> (<?= number_format($m['total_records']) ?> registros)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="delete_month_sales" class="btn btn-warning btn-sm" style="width: 100%;">
                                                <i class="fas fa-trash-alt"></i> Deletar Mês
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Deletar Tudo -->
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #dc3545;">
                                        <h6 style="color: #721c24; margin-bottom: 10px;">
                                            <i class="fas fa-exclamation-triangle"></i> Deletar Tudo
                                        </h6>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                            <strong>PERIGO:</strong> Remove todos os dados de vendas
                                        </p>
                                        <button type="button" class="btn btn-danger btn-sm" style="width: 100%;" onclick="showDeleteAllConfirmation()">
                                            <i class="fas fa-bomb"></i> Deletar Tudo
                                        </button>
                                    </div>

                                    <!-- Debug de Mês -->
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #6f42c1;">
                                        <h6 style="color: #4a2c7b; margin-bottom: 10px;">
                                            <i class="fas fa-bug"></i> Debug de Divergências
                                        </h6>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                            Analisa duplicatas e divergências de dados
                                        </p>
                                        <form method="POST">
                                            <select name="month_to_debug" class="form-control" required style="margin-bottom: 10px; font-size: 12px;">
                                                <option value="">-- Selecione --</option>
                                                <?php foreach ($months_in_db as $m): ?>
                                                    <option value="<?= htmlspecialchars($m['month_reference']) ?>">
                                                        <?php
                                                        list($y, $mon) = explode('-', $m['month_reference']);
                                                        $months_pt = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr',
                                                                      '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
                                                                      '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];
                                                        echo $months_pt[$mon] . '/' . $y;
                                                        ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="debug_month" class="btn btn-sm" style="width: 100%; background: #6f42c1; color: white;">
                                                <i class="fas fa-search"></i> Analisar Mês
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Gerenciar Comissões -->
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #28a745;">
                                        <h6 style="color: #155724; margin-bottom: 10px;">
                                            <i class="fas fa-percent"></i> Gerenciar Comissões
                                        </h6>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                            Configura comissão por promotor/mês (% ou R$)
                                        </p>
                                        <a href="admin/manage_commissions.php" class="btn btn-success btn-sm" style="width: 100%;" target="_blank">
                                            <i class="fas fa-cog"></i> Abrir Gerenciador
                                        </a>
                                    </div>

                                    <?php if (canViewAuditLogs()): ?>
                                    <!-- Logs de Auditoria -->
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #fd7e14;">
                                        <h6 style="color: #8b4513; margin-bottom: 10px;">
                                            <i class="fas fa-history"></i> Logs de Auditoria
                                        </h6>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                            Visualiza histórico completo de ações
                                        </p>
                                        <a href="admin/audit_logs.php" class="btn btn-sm" style="width: 100%; background: #fd7e14; color: white;" target="_blank">
                                            <i class="fas fa-eye"></i> Ver Logs
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (canManageUsers()): ?>
                                    <!-- Migrar Roles (uma vez) -->
                                    <?php if (!$migrations_done['user_roles']): ?>
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #e83e8c;">
                                        <h6 style="color: #7d2050; margin-bottom: 10px;">
                                            <i class="fas fa-user-shield"></i> Níveis de Acesso
                                        </h6>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                            Configurar roles dos usuários
                                        </p>
                                        <a href="admin/migrate_user_roles.php" class="btn btn-sm" style="width: 100%; background: #e83e8c; color: white;" target="_blank">
                                            <i class="fas fa-shield-alt"></i> Configurar Roles
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (canEdit()): ?>
                                    <!-- Migração de Comprovantes -->
                                    <?php if (!$migrations_done['payment_receipts']): ?>
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #20c997;">
                                        <h6 style="color: #0d6e4f; margin-bottom: 10px;">
                                            <i class="fas fa-receipt"></i> Sistema de Comprovantes
                                        </h6>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                            Habilitar upload de comprovantes (executar uma vez)
                                        </p>
                                        <a href="admin/migrate_payment_receipts.php" class="btn btn-sm" style="width: 100%; background: #20c997; color: white;" target="_blank">
                                            <i class="fas fa-database"></i> Migrar Tabela
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Migração de Comissões Gerais -->
                                    <?php if (!$migrations_done['commission_defaults']): ?>
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #ffc107;">
                                        <h6 style="color: #856404; margin-bottom: 10px;">
                                            <i class="fas fa-users-cog"></i> Comissões Gerais
                                        </h6>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                            Permitir comissão por mês (todos) + override
                                        </p>
                                        <a href="admin/migrate_commission_defaults.php" class="btn btn-sm" style="width: 100%; background: #ffc107; color: #333;" target="_blank">
                                            <i class="fas fa-cog"></i> Migrar Comissões
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Migração de PIN Mestre -->
                                    <?php if (!$migrations_done['master_pin']): ?>
                                    <div style="background: white; padding: 15px; border-radius: 8px; border: 2px solid #17a2b8; margin-top: 15px;">
                                        <h6 style="color: #0c5460; margin-bottom: 10px;">
                                            <i class="fas fa-key"></i> PIN Mestre Admins
                                        </h6>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                            Adicionar campo para PIN mestre dos administradores
                                        </p>
                                        <a href="admin/migrate_master_pin.php" class="btn btn-sm" style="width: 100%; background: #17a2b8; color: white;" target="_blank">
                                            <i class="fas fa-cog"></i> Migrar PIN Mestre
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Resultado do Debug -->
                            <?php if ($debug_result): ?>
                                <div style="margin-top: 20px; padding: 20px; background: white; border-radius: 10px; border: 2px solid #6f42c1;">
                                    <h5 style="color: #4a2c7b; margin-bottom: 15px;">
                                        <i class="fas fa-chart-line"></i> Análise do Mês: <?= htmlspecialchars($debug_result['month']) ?>
                                    </h5>

                                    <!-- Estatísticas do Banco de Dados -->
                                    <?php
                                    $divergence_records = $debug_result['database']['total_records'] - $debug_result['database']['unique_sale_items'];
                                    $has_duplicates = !empty($debug_result['issues']['duplicated_sale_items']);
                                    ?>
                                    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 10px; margin-bottom: 20px; color: white; box-shadow: 0 10px 25px rgba(102,126,234,0.3);">
                                        <h6 style="margin-bottom: 15px; font-size: 18px; font-weight: bold;">
                                            <i class="fas fa-database"></i> Dados do Banco MySQL (<?= htmlspecialchars($debug_result['month']) ?>)
                                        </h6>
                                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 15px;">
                                            <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 8px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);">
                                                <small style="display: block; opacity: 0.9; margin-bottom: 5px; font-size: 12px;">Total de Registros</small>
                                                <strong style="font-size: 28px; display: block; font-weight: 700;">
                                                    <?= number_format($debug_result['database']['total_records']) ?>
                                                </strong>
                                            </div>
                                            <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 8px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);">
                                                <small style="display: block; opacity: 0.9; margin-bottom: 5px; font-size: 12px;">Vouchers Únicos</small>
                                                <strong style="font-size: 28px; display: block; font-weight: 700;">
                                                    <?= number_format($debug_result['database']['unique_vouchers']) ?>
                                                </strong>
                                            </div>
                                            <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 8px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);">
                                                <small style="display: block; opacity: 0.9; margin-bottom: 5px; font-size: 12px;">Sale Items Únicos</small>
                                                <strong style="font-size: 28px; display: block; font-weight: 700;">
                                                    <?= number_format($debug_result['database']['unique_sale_items']) ?>
                                                </strong>
                                            </div>
                                            <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 8px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);">
                                                <small style="display: block; opacity: 0.9; margin-bottom: 5px; font-size: 12px;">Promotores</small>
                                                <strong style="font-size: 28px; display: block; font-weight: 700;">
                                                    <?= number_format($debug_result['database']['unique_promoters']) ?>
                                                </strong>
                                            </div>
                                            <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 8px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);">
                                                <small style="display: block; opacity: 0.9; margin-bottom: 5px; font-size: 12px;">Valor Total</small>
                                                <strong style="font-size: 22px; display: block; font-weight: 700;">
                                                    R$ <?= number_format($debug_result['database']['total_value'], 2, ',', '.') ?>
                                                </strong>
                                            </div>
                                        </div>
                                        <?php if ($has_duplicates): ?>
                                            <div style="background: rgba(255,59,48,1); padding: 18px; border-radius: 8px; border: 2px solid #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <i class="fas fa-exclamation-triangle" style="font-size: 24px;"></i>
                                                    <div style="flex: 1;">
                                                        <strong style="font-size: 16px; display: block; margin-bottom: 5px;">DUPLICATAS ENCONTRADAS!</strong>
                                                        <span style="font-size: 14px; opacity: 0.95;">
                                                            Há <strong><?= number_format($divergence_records) ?> registros duplicados</strong> no banco.
                                                            <?= number_format(count($debug_result['issues']['duplicated_sale_items'])) ?> Sale Item IDs estão repetidos.
                                                            Use "Limpar Duplicatas" para corrigir.
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div style="background: rgba(52,199,89,1); padding: 18px; border-radius: 8px; border: 2px solid #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <i class="fas fa-check-circle" style="font-size: 24px;"></i>
                                                    <div style="flex: 1;">
                                                        <strong style="font-size: 16px; display: block; margin-bottom: 5px;">DADOS CONSISTENTES!</strong>
                                                        <span style="font-size: 14px; opacity: 0.95;">
                                                            Nenhuma duplicata encontrada. Total de registros = Total de Sale Items únicos.
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Histórico de Importação -->
                                    <?php if (!empty($debug_result['import_history'])): ?>
                                        <div style="background: #e7f3ff; padding: 15px; border-radius: 8px; border: 1px solid #007bff; margin-bottom: 15px;">
                                            <h6 style="color: #004085; margin-bottom: 10px;">
                                                <i class="fas fa-history"></i> Histórico de Importações
                                            </h6>
                                            <table style="width: 100%; font-size: 13px;">
                                                <thead>
                                                    <tr style="background: #cce5ff; border-bottom: 2px solid #007bff;">
                                                        <th style="padding: 8px; text-align: left;">Data da Importação</th>
                                                        <th style="padding: 8px; text-align: center;">Registros Importados</th>
                                                        <th style="padding: 8px; text-align: center;">Vouchers Únicos</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($debug_result['import_history'] as $import): ?>
                                                        <tr style="border-bottom: 1px solid #bee5eb;">
                                                            <td style="padding: 8px;"><?= date('d/m/Y', strtotime($import['import_date'])) ?></td>
                                                            <td style="padding: 8px; text-align: center; font-weight: bold;"><?= number_format($import['records_imported']) ?></td>
                                                            <td style="padding: 8px; text-align: center;"><?= number_format($import['unique_vouchers']) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                            <?php if (count($debug_result['import_history']) > 1): ?>
                                                <div style="margin-top: 10px; background: #fff3cd; padding: 10px; border-radius: 5px;">
                                                    <strong>⚠️ ATENÇÃO:</strong> O CSV foi importado <strong><?= count($debug_result['import_history']) ?> vezes</strong>!
                                                    Isso pode ter causado duplicatas.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Sale Items Duplicados -->
                                    <?php if (!empty($debug_result['issues']['duplicated_sale_items'])): ?>
                                        <div style="background: #f8d7da; padding: 15px; border-radius: 8px; border: 2px solid #dc3545; margin-bottom: 15px;">
                                            <h6 style="color: #721c24; margin-bottom: 10px;">
                                                <i class="fas fa-exclamation-circle"></i> Sale Items Duplicados (PROBLEMA REAL!)
                                            </h6>
                                            <p style="margin-bottom: 10px; color: #721c24;">
                                                <strong>Estes Sale Item IDs aparecem múltiplas vezes no banco:</strong>
                                            </p>
                                            <div style="max-height: 400px; overflow-y: auto; background: white; padding: 10px; border-radius: 5px;">
                                                <table style="width: 100%; font-size: 12px;">
                                                    <thead>
                                                        <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                                                            <th style="padding: 8px; text-align: left;">Sale Item ID</th>
                                                            <th style="padding: 8px; text-align: center;">Repetições</th>
                                                            <th style="padding: 8px; text-align: center;">Vouchers Diferentes</th>
                                                            <th style="padding: 8px; text-align: left;">Vouchers</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($debug_result['issues']['duplicated_sale_items'] as $item): ?>
                                                            <tr style="border-bottom: 1px solid #dee2e6;">
                                                                <td style="padding: 8px; font-family: monospace; font-weight: bold;"><?= htmlspecialchars($item['sale_item_id']) ?></td>
                                                                <td style="padding: 8px; text-align: center;">
                                                                    <span style="background: #dc3545; color: white; padding: 2px 8px; border-radius: 12px; font-weight: bold;">
                                                                        <?= $item['count'] ?>×
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 8px; text-align: center;"><?= $item['different_vouchers'] ?></td>
                                                                <td style="padding: 8px; font-family: monospace; font-size: 10px;"><?= htmlspecialchars(substr($item['vouchers'], 0, 50)) ?><?= strlen($item['vouchers']) > 50 ? '...' : '' ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div style="margin-top: 10px; background: #fff3cd; padding: 10px; border-radius: 5px;">
                                                <strong>⚠️ AÇÃO NECESSÁRIA:</strong> Use a ferramenta "Limpar Duplicatas" para remover estes registros duplicados.
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Vouchers Duplicados -->
                                    <?php if (!empty($debug_result['issues']['duplicated_vouchers'])): ?>
                                        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border: 1px solid #ffc107; margin-bottom: 15px;">
                                            <h6 style="color: #856404; margin-bottom: 10px;">
                                                <i class="fas fa-copy"></i> Vouchers com Registros Duplicados
                                            </h6>
                                            <p style="margin-bottom: 10px; color: #856404; font-size: 13px;">
                                                <strong>Estes vouchers têm o mesmo Sale Item ID repetido:</strong>
                                            </p>
                                            <div style="max-height: 300px; overflow-y: auto; background: white; padding: 10px; border-radius: 5px;">
                                                <table style="width: 100%; font-size: 12px;">
                                                    <thead>
                                                        <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                                                            <th style="padding: 8px; text-align: left;">Voucher Code</th>
                                                            <th style="padding: 8px; text-align: center;">Total Registros</th>
                                                            <th style="padding: 8px; text-align: center;">Sale IDs Únicos</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($debug_result['issues']['duplicated_vouchers'] as $v): ?>
                                                            <tr style="border-bottom: 1px solid #dee2e6;">
                                                                <td style="padding: 8px; font-family: monospace;"><?= htmlspecialchars($v['voucher_code']) ?></td>
                                                                <td style="padding: 8px; text-align: center;">
                                                                    <span style="background: #ffc107; color: #333; padding: 2px 8px; border-radius: 12px; font-weight: bold;">
                                                                        <?= $v['total_occurrences'] ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 8px; text-align: center;"><?= $v['different_sale_items'] ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Conclusão Final -->
                                    <?php if ($has_duplicates): ?>
                                        <div style="margin-top: 15px; padding: 20px; background: #dc3545; color: white; border-radius: 8px; box-shadow: 0 4px 15px rgba(220,53,69,0.3);">
                                            <h6 style="margin-bottom: 10px;"><i class="fas fa-exclamation-triangle"></i> AÇÃO NECESSÁRIA:</h6>
                                            <p style="margin: 0; font-size: 14px; line-height: 1.6;">
                                                O banco de dados possui <strong><?= number_format($divergence_records) ?> registros duplicados</strong>.
                                                <br>
                                                Total atual: <strong><?= number_format($debug_result['database']['total_records']) ?> registros</strong>, mas deveria ter apenas <strong><?= number_format($debug_result['database']['unique_sale_items']) ?> (Sale Items únicos)</strong>.
                                                <br><br>
                                                <strong>Causa provável:</strong> O arquivo CSV foi importado <strong><?= count($debug_result['import_history']) ?> vez(es)</strong>,
                                                criando registros duplicados antes do UNIQUE KEY ser implementado.
                                                <br><br>
                                                <strong>Solução:</strong> Use o botão "Limpar Duplicatas" acima para remover os <strong><?= number_format($divergence_records) ?> registros duplicados</strong>.
                                            </p>
                                        </div>
                                    <?php else: ?>
                                        <div style="margin-top: 15px; padding: 20px; background: #28a745; color: white; border-radius: 8px; box-shadow: 0 4px 15px rgba(40,167,69,0.3);">
                                            <h6 style="margin-bottom: 10px;"><i class="fas fa-check-circle"></i> BANCO DE DADOS LIMPO:</h6>
                                            <p style="margin: 0; font-size: 14px; line-height: 1.6;">
                                                O banco de dados está consistente! Não há duplicatas.
                                                <br>
                                                Total de registros (<strong><?= number_format($debug_result['database']['total_records']) ?></strong>) = Sale Items únicos (<strong><?= number_format($debug_result['database']['unique_sale_items']) ?></strong>).
                                                <br><br>
                                                O arquivo CSV foi importado <strong><?= count($debug_result['import_history']) ?> vez(es)</strong>, e o sistema está funcionando corretamente.
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Modal de Confirmação de Deletar Tudo -->
                        <div id="deleteAllModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center;">
                            <div style="background: white; padding: 30px; border-radius: 15px; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                                <h4 style="color: #dc3545; margin-bottom: 20px;">
                                    <i class="fas fa-exclamation-triangle"></i> CONFIRMAÇÃO NECESSÁRIA
                                </h4>
                                <p style="margin-bottom: 20px; color: #333;">
                                    Você está prestes a <strong style="color: #dc3545;">DELETAR PERMANENTEMENTE</strong> todos os dados de vendas do banco de dados!
                                </p>
                                <p style="margin-bottom: 20px; background: #fff3cd; padding: 15px; border-radius: 8px; border: 1px solid #ffc107;">
                                    <strong>Para confirmar, digite:</strong><br>
                                    <code style="background: #333; color: #fff; padding: 5px 10px; border-radius: 4px; font-size: 14px;">DELETAR TUDO</code>
                                </p>
                                <form method="POST">
                                    <input type="text" name="confirm_delete_all" class="form-control" placeholder="Digite: DELETAR TUDO" required style="margin-bottom: 15px; font-size: 16px; text-align: center;">
                                    <div style="display: flex; gap: 10px;">
                                        <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="closeDeleteAllModal()">
                                            <i class="fas fa-times"></i> Cancelar
                                        </button>
                                        <button type="submit" name="delete_all_sales" class="btn btn-danger" style="flex: 1;">
                                            <i class="fas fa-trash-alt"></i> Confirmar Exclusão
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <script>
                            function showDeleteAllConfirmation() {
                                document.getElementById('deleteAllModal').style.display = 'flex';
                            }

                            function closeDeleteAllModal() {
                                document.getElementById('deleteAllModal').style.display = 'none';
                            }
                        </script>
                    <?php endif; ?>

                    <!-- Formulário para importar CSVs existentes -->
                    <?php if (!empty($available_months)): ?>
                        <div style="margin-top: 30px; padding: 20px; background: #fff3cd; border-radius: 10px; border: 2px solid #ffc107;">
                            <h5 style="margin-bottom: 15px; color: #856404;">
                                <i class="fas fa-file-import"></i> Importar CSV Existente para Banco de Dados
                            </h5>
                            <form method="POST" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px; align-items: end;">
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">
                                        <i class="fas fa-calendar"></i> Selecione o Mês:
                                    </label>
                                    <select name="import_month" class="form-control" required>
                                        <option value="">-- Selecione --</option>
                                        <?php foreach ($available_months as $month):
                                            // Verifica se já tem no banco
                                            $check = checkMonthDataInDatabase($month['value']);
                                        ?>
                                            <option value="<?= htmlspecialchars($month['value']) ?>">
                                                <?= htmlspecialchars($month['label']) ?>
                                                <?php if ($check['has_data']): ?>
                                                    (<?= number_format($check['count']) ?> registros no banco)
                                                <?php else: ?>
                                                    (não importado)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 5px;">
                                        <input type="checkbox" name="replace_existing" value="1">
                                        <span style="font-size: 14px; font-weight: 600; color: #dc3545;">
                                            <i class="fas fa-exclamation-triangle"></i> Substituir dados existentes
                                        </span>
                                    </label>
                                    <small style="color: #666; display: block; margin-top: 5px;">
                                        Se marcado, deletará todos os registros do mês antes de importar
                                    </small>
                                </div>
                                <button type="submit" name="import_existing_csv" class="btn btn-warning">
                                    <i class="fas fa-database"></i> Importar para Banco
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- Formulário para importar CSV de Promotores -->
                    <div style="margin-top: 30px; padding: 20px; background: #d1ecf1; border-radius: 10px; border: 2px solid #17a2b8;">
                        <h5 style="margin-bottom: 15px; color: #0c5460;">
                            <i class="fas fa-user-tie"></i> Importar Cadastro de Promotores (Consultores)
                        </h5>
                        <p style="font-size: 13px; color: #0c5460; margin-bottom: 15px;">
                            <i class="fas fa-info-circle"></i> Faça upload de um CSV com os dados pessoais dos promotores para habilitar autenticação personalizada.
                            <br><small>Formato esperado: Nome_Promotor, Titulo, Comissão, Status, Documento, Rg, Rua, Numero, Compl, Bairro, Cidade, UF, PostalCode, Celular</small>
                        </p>
                        <form method="POST" enctype="multipart/form-data" style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 15px; align-items: end;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">
                                    <i class="fas fa-file-csv"></i> Arquivo CSV de Promotores:
                                </label>
                                <input type="file" name="promoters_csv_file" accept=".csv" class="form-control" required>
                            </div>
                            <div>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 5px;">
                                    <input type="checkbox" name="replace_promoters" value="1">
                                    <span style="font-size: 14px; font-weight: 600; color: #dc3545;">
                                        <i class="fas fa-exclamation-triangle"></i> Substituir todos os promotores
                                    </span>
                                </label>
                                <small style="color: #666; display: block; margin-top: 5px;">
                                    Se marcado, deletará todos os promotores existentes antes de importar
                                </small>
                            </div>
                            <button type="submit" class="btn btn-info">
                                <i class="fas fa-upload"></i> Importar Promotores
                            </button>
                        </form>

                        <?php
                        // Mostra promotores já cadastrados
                        $promoters_list = getAllPromoters();
                        if (!empty($promoters_list)):
                        ?>
                            <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #17a2b8;">
                                <h6 style="color: #0c5460; margin-bottom: 10px;">
                                    <i class="fas fa-users"></i> Promotores Cadastrados: <?= count($promoters_list) ?>
                                </h6>
                                <div style="max-height: 300px; overflow-y: auto; background: white; padding: 15px; border-radius: 8px;">
                                    <table style="width: 100%; font-size: 12px;">
                                        <thead>
                                            <tr style="border-bottom: 2px solid #17a2b8;">
                                                <th style="padding: 8px; text-align: left;">Nome</th>
                                                <th style="padding: 8px; text-align: center;">Título</th>
                                                <th style="padding: 8px; text-align: center;">Comissão</th>
                                                <th style="padding: 8px; text-align: center;">CPF</th>
                                                <th style="padding: 8px; text-align: center;">Status</th>
                                                <th style="padding: 8px; text-align: center;">PIN</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($promoters_list as $promo): ?>
                                                <tr style="border-bottom: 1px solid #ddd;">
                                                    <td style="padding: 8px;"><?= htmlspecialchars($promo['name']) ?></td>
                                                    <td style="padding: 8px; text-align: center;"><?= htmlspecialchars($promo['title'] ?? '-') ?></td>
                                                    <td style="padding: 8px; text-align: center;"><?= number_format($promo['commission_percentage'], 2, ',', '.') ?>%</td>
                                                    <td style="padding: 8px; text-align: center; font-family: monospace;"><?= htmlspecialchars($promo['document'] ?? '-') ?></td>
                                                    <td style="padding: 8px; text-align: center;">
                                                        <span style="padding: 3px 8px; border-radius: 4px; font-size: 11px; <?= $promo['status'] === 'Ativo' ? 'background: #d4edda; color: #155724;' : 'background: #f8d7da; color: #721c24;' ?>">
                                                            <?= htmlspecialchars($promo['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td style="padding: 8px; text-align: center;">
                                                        <?php if (!empty($promo['pin'])): ?>
                                                            <i class="fas fa-check-circle" style="color: #28a745;"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 8px; border: 1px solid #ffc107;">
                                <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                                <span style="color: #856404; font-size: 13px;">
                                    Nenhum promotor cadastrado ainda. Faça o upload do CSV para começar.
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

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
                                            $promoter = trim($row['Promoter'] ?? '', " \"\n\r\t"); // Remove espaços, aspas e quebras de linha
                                            $campaign = $row['CampaignName'] ?? '';
                                            
                                            // Ignora vendas de "Dayuse SITE"
                                            if (stripos($campaign, 'SITE') !== false) {
                                                continue;
                                            }
                                            
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
                
                <!-- Modais de Gestão de Usuários (Admin) -->
                <?php if ($is_admin_authenticated): ?>
                <!-- Modal de Gestão de Usuários -->
                <div class="modal-overlay" id="usersModal" onclick="closeModalOnOverlay(event)">
                    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 900px;">
                        <div class="modal-header">
                            <h3>
                                <i class="fas fa-users-cog"></i> Gerenciar Usuários GODMODE
                            </h3>
                            <button class="modal-close" onclick="closeUsersModal()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <!-- Formulário Adicionar Usuário -->
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                                <h4 style="margin-bottom: 15px;"><i class="fas fa-user-plus"></i> Adicionar Novo Usuário</h4>
                                <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">Usuário:</label>
                                        <input type="text" name="new_username" class="form-control" required placeholder="username">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">Nome Completo:</label>
                                        <input type="text" name="new_name" class="form-control" required placeholder="Nome completo">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">Senha:</label>
                                        <input type="password" name="new_password" class="form-control" required placeholder="Senha">
                                    </div>
                                    <div style="display: flex; align-items: flex-end;">
                                        <button type="submit" name="add_user" class="btn btn-success" style="width: 100%;">
                                            <i class="fas fa-plus"></i> Adicionar
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Lista de Usuários -->
                            <h4 style="margin-bottom: 15px;"><i class="fas fa-list"></i> Usuários Cadastrados</h4>
                            <table class="modal-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Usuário</th>
                                        <th>Nome</th>
                                        <th>Criado em</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $users_data = loadUsers();
                                    foreach ($users_data['users'] as $user): 
                                    ?>
                                        <tr>
                                            <td><?= $user['id'] ?></td>
                                            <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                            <td><?= htmlspecialchars($user['name']) ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                                            <td>
                                                <?php if ($user['active']): ?>
                                                    <span class="badge badge-success">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 5px; justify-content: center;">
                                                    <button onclick="editUserModal(<?= htmlspecialchars(json_encode($user)) ?>)" 
                                                            class="btn btn-primary btn-sm" style="padding: 5px 10px;">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <?php if ($user['id'] != 1): ?>
                                                        <form method="POST" style="margin: 0; display: inline;">
                                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                            <input type="hidden" name="active" value="<?= $user['active'] ? '0' : '1' ?>">
                                                            <button type="submit" name="toggle_user" 
                                                                    class="btn btn-<?= $user['active'] ? 'warning' : 'success' ?> btn-sm" 
                                                                    style="padding: 5px 10px;">
                                                                <i class="fas fa-<?= $user['active'] ? 'ban' : 'check' ?>"></i>
                                                            </button>
                                                        </form>
                                                        
                                                        <form method="POST" style="margin: 0; display: inline;" 
                                                              onsubmit="return confirm('Deseja realmente deletar este usuário?')">
                                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                            <button type="submit" name="delete_user" 
                                                                    class="btn btn-danger btn-sm" style="padding: 5px 10px;">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Editar Usuário -->
                <div class="modal-overlay" id="editUserModal" onclick="closeModalOnOverlay(event)">
                    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 500px;">
                        <div class="modal-header">
                            <h3><i class="fas fa-user-edit"></i> Editar Usuário</h3>
                            <button class="modal-close" onclick="closeEditUserModal()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <form method="POST" id="editUserForm">
                                <input type="hidden" name="edit_id" id="edit_id">

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Usuário:</label>
                                    <input type="text" name="edit_username" id="edit_username" class="form-control" required>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Nome Completo:</label>
                                    <input type="text" name="edit_name" id="edit_name" class="form-control" required>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                                        <i class="fas fa-shield-alt"></i> Nível de Acesso:
                                    </label>
                                    <select name="edit_role" id="edit_role" class="form-control" required>
                                        <option value="viewer">Visualizador (apenas leitura)</option>
                                        <option value="admin">Administrador (editar e gerenciar)</option>
                                        <option value="superadmin">Super Administrador (acesso total)</option>
                                    </select>
                                    <small style="color: #666; display: block; margin-top: 5px;">
                                        <strong>Visualizador:</strong> Apenas visualiza dados<br>
                                        <strong>Admin:</strong> Pode editar pagamentos e dados<br>
                                        <strong>Super Admin:</strong> Acesso total + logs + usuários
                                    </small>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Nova Senha:</label>
                                    <input type="password" name="edit_password" id="edit_password" class="form-control" placeholder="Deixe em branco para manter a atual">
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                                        <i class="fas fa-key"></i> PIN Mestre (Admin):
                                    </label>
                                    <input type="text" name="edit_master_pin" id="edit_master_pin" class="form-control" placeholder="4 dígitos para acesso rápido a relatórios" maxlength="10" pattern="[0-9]{4,10}">
                                    <small style="color: #666; display: block; margin-top: 5px;">
                                        PIN pessoal do admin para acessar relatórios de consultores rapidamente. Deixe em branco para não alterar.
                                    </small>
                                </div>

                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" name="edit_user" class="btn btn-primary" style="flex: 1;">
                                        <i class="fas fa-save"></i> Salvar
                                    </button>
                                    <button type="button" onclick="closeEditUserModal()" class="btn btn-secondary" style="flex: 1;">
                                        <i class="fas fa-times"></i> Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <script src="godmode.js"></script>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        $(document).ready(function() {
            // Inicializa Select2 no campo de busca do GODMODE
            $('#godmode_search').select2({
                placeholder: '-- Digite o nome do consultor --',
                allowClear: true,
                width: '100%'
            });

            // Inicializa Select2 no campo de login de promotor
            $('#promoter_name').select2({
                placeholder: '-- Digite ou selecione seu nome --',
                allowClear: true,
                width: '100%',
                language: {
                    noResults: function() {
                        return "Nenhum promotor encontrado";
                    },
                    searching: function() {
                        return "Buscando...";
                    }
                }
            });
            
            // Filtra tabela ao selecionar consultor
            $('#godmode_search').on('change', function() {
                const selectedPromoter = $(this).val().toLowerCase().trim();
                const rows = $('.godmode-row');
                
                if (selectedPromoter === '') {
                    rows.show();
                } else {
                    rows.each(function() {
                        const promoter = $(this).data('promoter');
                        if (promoter === selectedPromoter) {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                }
            });
        });
        
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
        
        function exportGodmodeToExcel() {
            const table = document.getElementById('godmodeTable');
            const wb = XLSX.utils.table_to_book(table, {sheet: "Todos_Consultores"});
            const date = new Date().toISOString().split('T')[0];
            XLSX.writeFile(wb, `Vendas_Todos_Consultores_${date}.xlsx`);
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