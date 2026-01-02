<?php
/**
 * Funções Auxiliares do Sistema
 * Versão 2.0 - Com Banco de Dados
 */

require_once __DIR__ . '/Database.php';

// ===== CONFIGURAÇÕES =====

/**
 * Carrega configurações do banco de dados
 */
function loadConfig() {
    try {
        $configs = Database::fetchAll("SELECT `key`, `value` FROM config");
        $result = [];

        foreach ($configs as $config) {
            $result[$config['key']] = $config['value'];
        }

        // Fallback para valores padrão se não existir no BD
        return array_merge([
            'admin_password' => password_hash('admin@2025', PASSWORD_BCRYPT),
            'commission_percentage' => 0.25,
            'app_name' => 'Sistema de Vendas',
            'timezone' => 'America/Sao_Paulo',
            'session_timeout' => 3600
        ], $result);

    } catch (Exception $e) {
        // Se banco não estiver configurado, retorna valores padrão
        return [
            'admin_password' => password_hash('admin@2025', PASSWORD_BCRYPT),
            'commission_percentage' => 0.25,
            'app_name' => 'Sistema de Vendas',
            'timezone' => 'America/Sao_Paulo',
            'session_timeout' => 3600
        ];
    }
}

/**
 * Salva/atualiza configuração
 */
function saveConfig($key, $value, $description = null) {
    try {
        $sql = "INSERT INTO config (`key`, `value`, `description`)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE `value` = ?, `description` = COALESCE(?, `description`)";
        Database::execute($sql, [$key, $value, $description, $value, $description]);
        return true;
    } catch (Exception $e) {
        error_log("Erro ao salvar config: " . $e->getMessage());
        return false;
    }
}

// ===== GESTÃO DE USUÁRIOS =====

/**
 * Autentica usuário (com hash de senha)
 *
 * @param string $username
 * @param string $password
 * @return array|false Retorna dados do usuário ou false
 */
function authenticateUser($username, $password) {
    try {
        $sql = "SELECT * FROM users WHERE username = ? AND active = 1";
        $user = Database::fetchOne($sql, [$username]);

        if ($user && password_verify($password, $user['password'])) {
            // Atualiza último login
            updateLastLogin($user['id']);

            // Log de auditoria
            logAudit($user['id'], 'login', 'user', $user['id']);

            return $user;
        }

        return false;
    } catch (Exception $e) {
        error_log("Erro ao autenticar usuário: " . $e->getMessage());
        return false;
    }
}

/**
 * Atualiza último login do usuário
 */
function updateLastLogin($userId) {
    try {
        $sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
        Database::execute($sql, [$userId]);
    } catch (Exception $e) {
        error_log("Erro ao atualizar last_login: " . $e->getMessage());
    }
}

/**
 * Adiciona novo usuário
 *
 * @param string $username
 * @param string $password
 * @param string $name
 * @param string $email (opcional)
 * @return array ['success' => bool, 'message' => string]
 */
function addUser($username, $password, $name, $email = null) {
    try {
        // Valida campos
        if (empty($username) || empty($password) || empty($name)) {
            return ['success' => false, 'message' => 'Todos os campos são obrigatórios!'];
        }

        // Valida tamanho da senha
        if (strlen($password) < 6) {
            return ['success' => false, 'message' => 'A senha deve ter no mínimo 6 caracteres!'];
        }

        // Verifica se username já existe
        $sql = "SELECT COUNT(*) as count FROM users WHERE username = ?";
        $result = Database::fetchOne($sql, [$username]);

        if ($result['count'] > 0) {
            return ['success' => false, 'message' => 'Nome de usuário já existe!'];
        }

        // Hash da senha
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        // Insere usuário
        $sql = "INSERT INTO users (username, password, name, email, active) VALUES (?, ?, ?, ?, 1)";
        $userId = Database::insert($sql, [$username, $hashedPassword, $name, $email]);

        // Log de auditoria
        if (isset($_SESSION['godmode_user_id'])) {
            logAudit($_SESSION['godmode_user_id'], 'create_user', 'user', $userId, null, json_encode(['username' => $username, 'name' => $name]));
        }

        return ['success' => true, 'message' => 'Usuário criado com sucesso!', 'id' => $userId];

    } catch (Exception $e) {
        error_log("Erro ao adicionar usuário: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao criar usuário!'];
    }
}

/**
 * Edita usuário existente
 *
 * @param int $id
 * @param string $username
 * @param string $password (opcional, se vazio não altera)
 * @param string $name
 * @param string $email (opcional)
 * @return array ['success' => bool, 'message' => string]
 */
function editUser($id, $username, $password, $name, $email = null) {
    try {
        // Busca usuário atual
        $oldUser = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$oldUser) {
            return ['success' => false, 'message' => 'Usuário não encontrado!'];
        }

        // Verifica se username já existe em outro usuário
        $sql = "SELECT COUNT(*) as count FROM users WHERE username = ? AND id != ?";
        $result = Database::fetchOne($sql, [$username, $id]);

        if ($result['count'] > 0) {
            return ['success' => false, 'message' => 'Nome de usuário já existe!'];
        }

        // Atualiza usuário
        if (!empty($password)) {
            // Valida tamanho da senha
            if (strlen($password) < 6) {
                return ['success' => false, 'message' => 'A senha deve ter no mínimo 6 caracteres!'];
            }

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $sql = "UPDATE users SET username = ?, password = ?, name = ?, email = ? WHERE id = ?";
            Database::execute($sql, [$username, $hashedPassword, $name, $email, $id]);
        } else {
            $sql = "UPDATE users SET username = ?, name = ?, email = ? WHERE id = ?";
            Database::execute($sql, [$username, $name, $email, $id]);
        }

        // Log de auditoria
        if (isset($_SESSION['godmode_user_id'])) {
            logAudit($_SESSION['godmode_user_id'], 'update_user', 'user', $id, json_encode($oldUser), json_encode(['username' => $username, 'name' => $name]));
        }

        return ['success' => true, 'message' => 'Usuário atualizado com sucesso!'];

    } catch (Exception $e) {
        error_log("Erro ao editar usuário: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao atualizar usuário!'];
    }
}

/**
 * Ativa/desativa usuário
 *
 * @param int $id
 * @param bool $active
 * @return array ['success' => bool, 'message' => string]
 */
function toggleUserStatus($id, $active) {
    try {
        // Não permite desativar o usuário ID 1 (admin principal)
        if ($id == 1 && !$active) {
            return ['success' => false, 'message' => 'Não é possível desativar o administrador principal!'];
        }

        $sql = "UPDATE users SET active = ? WHERE id = ?";
        Database::execute($sql, [$active ? 1 : 0, $id]);

        // Log de auditoria
        if (isset($_SESSION['godmode_user_id'])) {
            logAudit($_SESSION['godmode_user_id'], $active ? 'activate_user' : 'deactivate_user', 'user', $id);
        }

        return ['success' => true, 'message' => $active ? 'Usuário ativado!' : 'Usuário desativado!'];

    } catch (Exception $e) {
        error_log("Erro ao alterar status do usuário: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao alterar status!'];
    }
}

/**
 * Deleta usuário
 *
 * @param int $id
 * @return array ['success' => bool, 'message' => string]
 */
function deleteUser($id) {
    try {
        // Não permite deletar o ID 1 (admin principal)
        if ($id == 1) {
            return ['success' => false, 'message' => 'Não é possível deletar o administrador principal!'];
        }

        // Busca dados do usuário antes de deletar
        $user = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$id]);

        $sql = "DELETE FROM users WHERE id = ?";
        Database::execute($sql, [$id]);

        // Log de auditoria
        if (isset($_SESSION['godmode_user_id'])) {
            logAudit($_SESSION['godmode_user_id'], 'delete_user', 'user', $id, json_encode($user));
        }

        return ['success' => true, 'message' => 'Usuário deletado com sucesso!'];

    } catch (Exception $e) {
        error_log("Erro ao deletar usuário: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao deletar usuário!'];
    }
}

/**
 * Lista todos os usuários
 *
 * @return array
 */
function loadUsers() {
    try {
        $users = Database::fetchAll("SELECT * FROM users ORDER BY id ASC");
        return ['users' => $users];
    } catch (Exception $e) {
        error_log("Erro ao carregar usuários: " . $e->getMessage());
        return ['users' => []];
    }
}

/**
 * Busca usuário por ID
 */
function getUserById($id) {
    try {
        return Database::fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    } catch (Exception $e) {
        error_log("Erro ao buscar usuário: " . $e->getMessage());
        return false;
    }
}

// ===== COMISSÕES E PAGAMENTOS =====

/**
 * Verifica se comissão foi paga
 *
 * @param string $promoter
 * @param string $month Formato: YYYY-MM
 * @return bool
 */
function isCommissionPaid($promoter, $month) {
    try {
        $sql = "SELECT paid FROM payments WHERE promoter = ? AND month = ?";
        $result = Database::fetchOne($sql, [$promoter, $month]);
        return $result ? (bool)$result['paid'] : false;
    } catch (Exception $e) {
        error_log("Erro ao verificar pagamento: " . $e->getMessage());
        return false;
    }
}

/**
 * Retorna dados do pagamento
 *
 * @param string $promoter
 * @param string $month
 * @return array|null
 */
function getPaymentData($promoter, $month) {
    try {
        $sql = "SELECT p.*, u1.name as paid_by_name, u2.name as unmarked_by_name
                FROM payments p
                LEFT JOIN users u1 ON p.paid_by = u1.id
                LEFT JOIN users u2 ON p.unmarked_by = u2.id
                WHERE p.promoter = ? AND p.month = ?";
        return Database::fetchOne($sql, [$promoter, $month]);
    } catch (Exception $e) {
        error_log("Erro ao buscar dados do pagamento: " . $e->getMessage());
        return null;
    }
}

/**
 * Marca/desmarca pagamento
 *
 * @param string $promoter
 * @param string $month
 * @param bool $paid
 * @param int $userId ID do usuário que está fazendo a ação
 * @param float $amount (opcional)
 * @param int $vouchers (opcional)
 * @return bool
 */
function togglePayment($promoter, $month, $paid, $userId, $amount = 0, $vouchers = 0) {
    try {
        if ($paid) {
            // Marcar como pago
            $sql = "INSERT INTO payments (promoter, month, paid, amount, vouchers, paid_by, paid_at)
                    VALUES (?, ?, 1, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        paid = 1,
                        paid_by = ?,
                        paid_at = NOW(),
                        amount = ?,
                        vouchers = ?,
                        unmarked_by = NULL,
                        unmarked_at = NULL";
            Database::execute($sql, [$promoter, $month, $amount, $vouchers, $userId, $userId, $amount, $vouchers]);
        } else {
            // Marcar como não pago
            $sql = "INSERT INTO payments (promoter, month, paid, unmarked_by, unmarked_at)
                    VALUES (?, ?, 0, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        paid = 0,
                        unmarked_by = ?,
                        unmarked_at = NOW(),
                        paid_by = NULL,
                        paid_at = NULL";
            Database::execute($sql, [$promoter, $month, $userId, $userId]);
        }

        // Log de auditoria
        logAudit($userId, $paid ? 'mark_payment' : 'unmark_payment', 'payment', null, null, json_encode(['promoter' => $promoter, 'month' => $month]));

        return true;

    } catch (Exception $e) {
        error_log("Erro ao marcar/desmarcar pagamento: " . $e->getMessage());
        return false;
    }
}

/**
 * Salva dados de comissão (usado pelo sistema ao processar vendas)
 */
function saveCommissionData($promoter, $month, $amount, $vouchers) {
    try {
        $sql = "INSERT INTO payments (promoter, month, amount, vouchers, paid)
                VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE
                    amount = ?,
                    vouchers = ?";
        Database::execute($sql, [$promoter, $month, $amount, $vouchers, $amount, $vouchers]);
        return true;
    } catch (Exception $e) {
        error_log("Erro ao salvar dados de comissão: " . $e->getMessage());
        return false;
    }
}

// ===== ARQUIVOS CSV =====

/**
 * Lista todos os meses disponíveis
 */
function getAvailableMonths() {
    $files = glob(DATA_DIR . '/ingressos_*.csv');
    $months = [];

    foreach ($files as $file) {
        $basename = basename($file, '.csv');
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

    usort($months, function($a, $b) {
        return strcmp($b['value'], $a['value']);
    });

    return $months;
}

/**
 * Gera nome do arquivo baseado no mês/ano
 */
function getDataFile($month_year) {
    if (empty($month_year)) {
        return null;
    }
    list($year, $month) = explode('-', $month_year);
    $months_pt = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $month_name = $months_pt[(int)$month - 1];
    return DATA_DIR . "/ingressos_{$month_name}_{$year}.csv";
}

/**
 * Carrega dados do CSV
 */
function loadCSVData($file_path) {
    $data = [];
    if (!file_exists($file_path)) {
        return $data;
    }

    if (($handle = fopen($file_path, 'r')) !== FALSE) {
        // Remove BOM se existir
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Detecta o delimitador
        $first_line = fgets($handle);
        rewind($handle);

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $semicolon_count = substr_count($first_line, ';');
        $comma_count = substr_count($first_line, ',');
        $delimiter = ($semicolon_count > $comma_count) ? ';' : ',';

        $headers = fgetcsv($handle, 10000, $delimiter);
        while (($row = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
            if (count($headers) === count($row)) {
                $data[] = array_combine($headers, $row);
            }
        }
        fclose($handle);
    }

    return $data;
}

/**
 * Processa vouchers de um arquivo CSV
 */
function processVouchers($csv_data) {
    $voucher_packages = [];

    foreach ($csv_data as $row) {
        $voucher = $row['VoucherCode'] ?? '';
        $promoter = trim($row['Promoter'] ?? '', " \"\n\r\t");
        $campaign = $row['CampaignName'] ?? '';

        // Ignora vendas de "Dayuse SITE"
        if (stripos($campaign, 'SITE') !== false) {
            continue;
        }

        if (!empty($voucher) && !empty($promoter)) {
            $value_raw = $row['ProductValue'] ?? '0';
            $value_clean = str_replace(['R$', ' '], '', $value_raw);
            if (strpos($value_clean, ',') !== false) {
                $value_clean = str_replace(['.', ','], ['', '.'], $value_clean);
            }
            $value = floatval($value_clean);

            if (!isset($voucher_packages[$voucher])) {
                $voucher_packages[$voucher] = [
                    'promoter' => $promoter,
                    'value' => 0
                ];
            }
            $voucher_packages[$voucher]['value'] += $value;
        }
    }

    return $voucher_packages;
}

/**
 * Calcula estatísticas de todos os meses para GODMODE
 */
function calculateGodmodeStats($available_months) {
    $godmode_data = [];

    foreach ($available_months as $month) {
        $month_file = $month['file'];
        $month_value = $month['value'];

        if (!file_exists($month_file)) {
            continue;
        }

        $month_data = loadCSVData($month_file);
        $month_vouchers = processVouchers($month_data);

        foreach ($month_vouchers as $voucher => $data) {
            $promoter = $data['promoter'];

            if (!isset($godmode_data[$promoter])) {
                $godmode_data[$promoter] = [
                    'total_vouchers' => 0,
                    'total_value' => 0,
                    'total_commission' => 0,
                    'paid_value' => 0,
                    'paid_commission' => 0,
                    'unpaid_value' => 0,
                    'unpaid_commission' => 0,
                    'months' => []
                ];
            }

            if (!isset($godmode_data[$promoter]['months'][$month_value])) {
                $godmode_data[$promoter]['months'][$month_value] = [
                    'vouchers' => 0,
                    'value' => 0,
                    'commission' => 0,
                    'paid' => false
                ];
            }

            $godmode_data[$promoter]['months'][$month_value]['vouchers']++;
            $godmode_data[$promoter]['months'][$month_value]['value'] += $data['value'];
            $godmode_data[$promoter]['months'][$month_value]['commission'] += ($data['value'] * 0.25);

            $godmode_data[$promoter]['total_vouchers']++;
            $godmode_data[$promoter]['total_value'] += $data['value'];
            $godmode_data[$promoter]['total_commission'] += ($data['value'] * 0.25);
        }
    }

    // Marca pagamentos e calcula valores pagos/não pagos
    foreach ($godmode_data as $promoter => &$data) {
        foreach ($data['months'] as $month => &$month_data) {
            $month_data['paid'] = isCommissionPaid($promoter, $month);

            if ($month_data['paid']) {
                $data['paid_value'] += $month_data['value'];
                $data['paid_commission'] += $month_data['commission'];
            } else {
                $data['unpaid_value'] += $month_data['value'];
                $data['unpaid_commission'] += $month_data['commission'];
            }

            // Salva dados de comissão no banco
            saveCommissionData($promoter, $month, $month_data['commission'], $month_data['vouchers']);
        }
    }

    ksort($godmode_data);
    return $godmode_data;
}

/**
 * Formata número para formato brasileiro
 */
function formatBRL($value) {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

// ===== AUDITORIA =====

/**
 * Registra log de auditoria
 *
 * @param int $userId
 * @param string $action
 * @param string $entityType
 * @param int $entityId
 * @param string $oldData (JSON)
 * @param string $newData (JSON)
 */
function logAudit($userId, $action, $entityType = null, $entityId = null, $oldData = null, $newData = null) {
    try {
        $sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_data, new_data, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        Database::execute($sql, [
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldData,
            $newData,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Erro ao registrar log de auditoria: " . $e->getMessage());
    }
}

// ===== SEGURANÇA =====

/**
 * Gera token CSRF
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida token CSRF
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Verifica timeout da sessão
 */
function checkSessionTimeout() {
    $timeout = (int)(loadConfig()['session_timeout'] ?? 3600);

    if (isset($_SESSION['LAST_ACTIVITY'])) {
        $elapsed = time() - $_SESSION['LAST_ACTIVITY'];

        if ($elapsed > $timeout) {
            // Sessão expirada
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['session_expired'] = true;
            return false;
        }
    }

    $_SESSION['LAST_ACTIVITY'] = time();
    return true;
}

/**
 * Sanitiza input para prevenir XSS
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// ===== IMPORTAÇÃO DE CSV PARA BANCO =====

/**
 * Importa CSV para o banco de dados
 *
 * @param string $csvFilePath Caminho do arquivo CSV
 * @param string $monthReference Mês de referência (YYYY-MM)
 * @param int $userId ID do usuário que está importando
 * @param bool $replace Se true, substitui dados existentes do mês
 * @return array ['success' => bool, 'message' => string, 'stats' => array]
 */
function importCSVToDatabase($csvFilePath, $monthReference, $userId, $replace = false) {
    try {
        if (!file_exists($csvFilePath)) {
            return ['success' => false, 'message' => 'Arquivo CSV não encontrado!'];
        }

        $db = Database::getConnection();

        // Se replace = true, deleta dados existentes do mês
        if ($replace) {
            $sql = "DELETE FROM sales WHERE month_reference = ?";
            $deleted = Database::execute($sql, [$monthReference]);

            // Log de auditoria
            logAudit($userId, 'delete_sales_month', 'sales', null, null, json_encode(['month' => $monthReference, 'deleted_rows' => $deleted]));
        }

        // Abre arquivo CSV
        $handle = fopen($csvFilePath, 'r');
        if ($handle === FALSE) {
            return ['success' => false, 'message' => 'Erro ao abrir arquivo CSV!'];
        }

        // Remove BOM se existir
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

        $delimiter = (substr_count($first_line, "\t") > substr_count($first_line, ',')) ? "\t" : ',';

        // Lê headers
        $headers = fgetcsv($handle, 10000, $delimiter);

        // Mapeia headers para campos do banco
        $headerMap = [
            'SaleItemsId' => 'sale_item_id',
            'VoucherCode' => 'voucher_code',
            'VoucherStatus' => 'voucher_status',
            'OriginPlace' => 'origin_place',
            'CampaignName' => 'campaign_name',
            'PackageName' => 'package_name',
            'ProductName' => 'product_name',
            'ProductValue' => 'product_value',
            'SaleWeekday' => 'sale_weekday',
            'SaleDateTime' => 'sale_datetime',
            'VisitWeekday' => 'visit_weekday',
            'VisitDate' => 'visit_date',
            'Manager' => 'manager',
            'Promoter' => 'promoter',
            'VisitorName' => 'visitor_name',
            'VisitorDocument' => 'visitor_document',
            'VisitorEmail' => 'visitor_email',
            'VisitorBirthDate' => 'visitor_birthdate',
            'VisitorSex' => 'visitor_sex',
            'VisitorAddressStreet' => 'visitor_address_street',
            'VisitorAddressNumber' => 'visitor_address_number',
            'VisitorAddressBurgh' => 'visitor_address_burgh',
            'VisitorMobilePhone' => 'visitor_mobile_phone',
            'VisitorAddressCity' => 'visitor_address_city',
            'VisitorAddressState' => 'visitor_address_state',
            'VisitorAddressPostalCode' => 'visitor_address_postal_code',
            'VisitorAddressCountry' => 'visitor_address_country',
            'DependenciesLastUpdateDate' => 'dependencies_last_update_date',
            'LastUpdateDate' => 'last_update_date'
        ];

        // Prepara SQL para inserção
        $sql = "INSERT INTO sales (
            sale_item_id, voucher_code, voucher_status, origin_place, campaign_name,
            package_name, product_name, product_value, sale_weekday, sale_datetime,
            visit_weekday, visit_date, manager, promoter, visitor_name,
            visitor_document, visitor_email, visitor_birthdate, visitor_sex,
            visitor_address_street, visitor_address_number, visitor_address_burgh,
            visitor_mobile_phone, visitor_address_city, visitor_address_state,
            visitor_address_postal_code, visitor_address_country,
            dependencies_last_update_date, last_update_date,
            month_reference, imported_by
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )";

        $stmt = $db->prepare($sql);

        $imported = 0;
        $skipped = 0;
        $errors = 0;

        // Inicia transação para melhor performance
        $db->beginTransaction();

        while (($row = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
            try {
                if (count($headers) !== count($row)) {
                    $skipped++;
                    continue;
                }

                $data = array_combine($headers, $row);

                // Converte valor para decimal
                $productValue = $data['ProductValue'] ?? '0';
                $productValue = str_replace(['R$', ' '], '', $productValue);
                if (strpos($productValue, ',') !== false) {
                    $productValue = str_replace(['.', ','], ['', '.'], $productValue);
                }
                $productValue = floatval($productValue);

                // Converte datas
                $saleDateTime = !empty($data['SaleDateTime']) && $data['SaleDateTime'] !== 'NULL'
                    ? date('Y-m-d H:i:s', strtotime($data['SaleDateTime']))
                    : null;

                $visitDate = !empty($data['VisitDate']) && $data['VisitDate'] !== 'NULL'
                    ? date('Y-m-d', strtotime($data['VisitDate']))
                    : null;

                $visitorBirthdate = !empty($data['VisitorBirthDate']) && $data['VisitorBirthDate'] !== 'NULL'
                    ? date('Y-m-d', strtotime($data['VisitorBirthDate']))
                    : null;

                $dependenciesLastUpdateDate = !empty($data['DependenciesLastUpdateDate']) && $data['DependenciesLastUpdateDate'] !== 'NULL'
                    ? date('Y-m-d H:i:s', strtotime($data['DependenciesLastUpdateDate']))
                    : null;

                $lastUpdateDate = !empty($data['LastUpdateDate']) && $data['LastUpdateDate'] !== 'NULL'
                    ? date('Y-m-d H:i:s', strtotime($data['LastUpdateDate']))
                    : null;

                // Limpa promoter (remove aspas, espaços, etc)
                $promoter = trim($data['Promoter'] ?? '', " \"\n\r\t");

                // Prepara dados para inserção
                $insertData = [
                    $data['SaleItemsId'] ?? '',
                    $data['VoucherCode'] ?? '',
                    $data['VoucherStatus'] ?? null,
                    $data['OriginPlace'] ?? null,
                    $data['CampaignName'] ?? null,
                    $data['PackageName'] ?? null,
                    $data['ProductName'] ?? null,
                    $productValue,
                    $data['SaleWeekday'] ?? null,
                    $saleDateTime,
                    $data['VisitWeekday'] ?? null,
                    $visitDate,
                    $data['Manager'] ?? null,
                    $promoter,
                    $data['VisitorName'] ?? null,
                    $data['VisitorDocument'] ?? null,
                    $data['VisitorEmail'] ?? null,
                    $visitorBirthdate,
                    $data['VisitorSex'] ?? null,
                    ($data['VisitorAddressStreet'] ?? '') === 'NULL' ? null : ($data['VisitorAddressStreet'] ?? null),
                    ($data['VisitorAddressNumber'] ?? '') === 'NULL' ? null : ($data['VisitorAddressNumber'] ?? null),
                    ($data['VisitorAddressBurgh'] ?? '') === 'NULL' ? null : ($data['VisitorAddressBurgh'] ?? null),
                    $data['VisitorMobilePhone'] ?? null,
                    ($data['VisitorAddressCity'] ?? '') === 'NULL' ? null : ($data['VisitorAddressCity'] ?? null),
                    ($data['VisitorAddressState'] ?? '') === 'NULL' ? null : ($data['VisitorAddressState'] ?? null),
                    ($data['VisitorAddressPostalCode'] ?? '') === 'NULL' ? null : ($data['VisitorAddressPostalCode'] ?? null),
                    ($data['VisitorAddressCountry'] ?? '') === 'NULL' ? null : ($data['VisitorAddressCountry'] ?? null),
                    $dependenciesLastUpdateDate,
                    $lastUpdateDate,
                    $monthReference,
                    $userId
                ];

                $stmt->execute($insertData);
                $imported++;

            } catch (PDOException $e) {
                $errors++;
                error_log("Erro ao importar linha: " . $e->getMessage());
                // Continua importando as outras linhas
            }
        }

        // Commit da transação
        $db->commit();
        fclose($handle);

        // Log de auditoria
        logAudit($userId, 'import_csv', 'sales', null, null, json_encode([
            'month' => $monthReference,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'replace' => $replace
        ]));

        $message = "Importação concluída! Registros importados: {$imported}";
        if ($skipped > 0) $message .= ", pulados: {$skipped}";
        if ($errors > 0) $message .= ", erros: {$errors}";

        return [
            'success' => true,
            'message' => $message,
            'stats' => [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'replace' => $replace
            ]
        ];

    } catch (Exception $e) {
        // Rollback em caso de erro
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }

        error_log("Erro na importação de CSV: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Erro ao importar CSV: ' . $e->getMessage()
        ];
    }
}

/**
 * Verifica se um mês já tem dados importados no banco
 *
 * @param string $monthReference
 * @return array ['has_data' => bool, 'count' => int]
 */
function checkMonthDataInDatabase($monthReference) {
    try {
        $sql = "SELECT COUNT(*) as count FROM sales WHERE month_reference = ?";
        $result = Database::fetchOne($sql, [$monthReference]);

        return [
            'has_data' => $result['count'] > 0,
            'count' => (int)$result['count']
        ];
    } catch (Exception $e) {
        error_log("Erro ao verificar dados do mês: " . $e->getMessage());
        return ['has_data' => false, 'count' => 0];
    }
}

/**
 * Lista meses com dados no banco
 *
 * @return array
 */
function getMonthsInDatabase() {
    try {
        $sql = "SELECT
                    month_reference,
                    COUNT(*) as total_records,
                    COUNT(DISTINCT promoter) as total_promoters,
                    COUNT(DISTINCT voucher_code) as total_vouchers,
                    SUM(product_value) as total_value,
                    MAX(imported_at) as last_import
                FROM sales
                GROUP BY month_reference
                ORDER BY month_reference DESC";

        return Database::fetchAll($sql);
    } catch (Exception $e) {
        error_log("Erro ao listar meses do banco: " . $e->getMessage());
        return [];
    }
}
