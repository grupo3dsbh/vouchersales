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
