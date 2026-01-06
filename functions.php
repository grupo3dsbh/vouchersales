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
 * @param string $role
 * @param string $email (opcional)
 * @param string $master_pin (opcional, PIN mestre do admin)
 * @return array ['success' => bool, 'message' => string]
 */
function editUser($id, $username, $password, $name, $role = 'admin', $email = null, $master_pin = null) {
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

        // Valida role
        $validRoles = ['superadmin', 'admin', 'viewer'];
        if (!in_array($role, $validRoles)) {
            $role = 'admin'; // Fallback seguro
        }

        // Valida master_pin se fornecido
        if ($master_pin !== null && $master_pin !== '') {
            if (!preg_match('/^[0-9]{4,10}$/', $master_pin)) {
                return ['success' => false, 'message' => 'PIN mestre deve ter entre 4 e 10 dígitos!'];
            }
        }

        // Atualiza usuário
        if (!empty($password)) {
            // Valida tamanho da senha
            if (strlen($password) < 6) {
                return ['success' => false, 'message' => 'A senha deve ter no mínimo 6 caracteres!'];
            }

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            if ($master_pin !== null && $master_pin !== '') {
                $sql = "UPDATE users SET username = ?, password = ?, name = ?, role = ?, email = ?, master_pin = ? WHERE id = ?";
                Database::execute($sql, [$username, $hashedPassword, $name, $role, $email, $master_pin, $id]);
            } else {
                $sql = "UPDATE users SET username = ?, password = ?, name = ?, role = ?, email = ? WHERE id = ?";
                Database::execute($sql, [$username, $hashedPassword, $name, $role, $email, $id]);
            }
        } else {
            if ($master_pin !== null && $master_pin !== '') {
                $sql = "UPDATE users SET username = ?, name = ?, role = ?, email = ?, master_pin = ? WHERE id = ?";
                Database::execute($sql, [$username, $name, $role, $email, $master_pin, $id]);
            } else {
                $sql = "UPDATE users SET username = ?, name = ?, role = ?, email = ? WHERE id = ?";
                Database::execute($sql, [$username, $name, $role, $email, $id]);
            }
        }

        // Log de auditoria
        if (isset($_SESSION['godmode_user_id'])) {
            logAudit($_SESSION['godmode_user_id'], 'update_user', 'user', $id, json_encode($oldUser), json_encode(['username' => $username, 'name' => $name, 'role' => $role]));
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

/**
 * Verifica se o usuário tem uma role específica
 *
 * @param string $role Role a verificar (superadmin, admin, viewer)
 * @return bool
 */
function hasRole($role) {
    if (!isset($_SESSION['godmode_user_role'])) {
        return false;
    }
    return $_SESSION['godmode_user_role'] === $role;
}

/**
 * Verifica se o usuário é superadmin
 *
 * @return bool
 */
function isSuperAdmin() {
    return hasRole('superadmin');
}

/**
 * Verifica se o usuário é admin ou superadmin
 *
 * @return bool
 */
function isAdmin() {
    return hasRole('admin') || hasRole('superadmin');
}

/**
 * Verifica se o usuário pode editar dados
 * (apenas admins e superadmins)
 *
 * @return bool
 */
function canEdit() {
    return isAdmin();
}

/**
 * Verifica se o usuário pode gerenciar outros usuários
 * (apenas superadmins)
 *
 * @return bool
 */
function canManageUsers() {
    return isSuperAdmin();
}

/**
 * Verifica se o usuário pode visualizar logs de auditoria
 * (apenas superadmins)
 *
 * @return bool
 */
function canViewAuditLogs() {
    return isSuperAdmin();
}

/**
 * Retorna o nome amigável da role
 *
 * @param string $role
 * @return string
 */
function getRoleName($role) {
    $roles = [
        'superadmin' => 'Super Administrador',
        'admin' => 'Administrador',
        'viewer' => 'Visualizador'
    ];
    return $roles[$role] ?? 'Desconhecido';
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

// ===== COMPROVANTES DE PAGAMENTO =====

/**
 * Salva comprovante como arquivo no servidor
 *
 * @param array $file $_FILES['receipt']
 * @param string $promoter Nome do promotor
 * @param string $month Mês de referência (YYYY-MM)
 * @return array ['success' => bool, 'file_path' => string, 'filename' => string, 'mime_type' => string, 'error' => string]
 */
function saveReceiptFile($file, $promoter, $month) {
    $result = ['success' => false, 'file_path' => null, 'filename' => null, 'mime_type' => null, 'error' => ''];

    try {
        // Valida se o arquivo foi enviado
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            $result['error'] = 'Nenhum arquivo foi enviado';
            return $result;
        }

        // Valida erros de upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $result['error'] = 'Erro no upload: ' . $file['error'];
            return $result;
        }

        // Valida tipo de arquivo (apenas imagens e PDFs)
        $allowedMimeTypes = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf'
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            $result['error'] = 'Tipo de arquivo não permitido. Apenas imagens (JPG, PNG, GIF, WEBP) e PDF são aceitos.';
            return $result;
        }

        // Valida tamanho (máximo 10MB)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) {
            $result['error'] = 'Arquivo muito grande. Tamanho máximo: 10MB';
            return $result;
        }

        // Define extensão baseada no MIME type
        $extension = match($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin'
        };

        // Cria estrutura de diretórios: /uploads/receipts/YYYY-MM/
        $baseDir = __DIR__ . '/uploads/receipts';
        $monthDir = $baseDir . '/' . $month;

        if (!is_dir($monthDir)) {
            mkdir($monthDir, 0755, true);
        }

        // Sanitiza nome do promotor para usar como nome de arquivo
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $promoter);
        $filename = $safeName . '_' . $month . '_' . time() . '.' . $extension;
        $filePath = $monthDir . '/' . $filename;

        // Move arquivo para o diretório
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            $result['error'] = 'Erro ao salvar arquivo no servidor';
            return $result;
        }

        // Define permissões adequadas
        chmod($filePath, 0644);

        // Retorna sucesso
        $result['success'] = true;
        $result['file_path'] = 'uploads/receipts/' . $month . '/' . $filename; // Caminho relativo
        $result['filename'] = $file['name']; // Nome original
        $result['mime_type'] = $mimeType;

    } catch (Exception $e) {
        $result['error'] = 'Erro ao salvar arquivo: ' . $e->getMessage();
        error_log("Erro em saveReceiptFile: " . $e->getMessage());
    }

    return $result;
}

/**
 * Salva comprovante como base64 no banco de dados
 *
 * @param array $file $_FILES['receipt']
 * @return array ['success' => bool, 'base64' => string, 'filename' => string, 'mime_type' => string, 'error' => string]
 */
function saveReceiptBase64($file) {
    $result = ['success' => false, 'base64' => null, 'filename' => null, 'mime_type' => null, 'error' => ''];

    try {
        // Valida se o arquivo foi enviado
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            $result['error'] = 'Nenhum arquivo foi enviado';
            return $result;
        }

        // Valida erros de upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $result['error'] = 'Erro no upload: ' . $file['error'];
            return $result;
        }

        // Valida tipo de arquivo
        $allowedMimeTypes = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf'
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            $result['error'] = 'Tipo de arquivo não permitido. Apenas imagens (JPG, PNG, GIF, WEBP) e PDF são aceitos.';
            return $result;
        }

        // Valida tamanho (máximo 10MB para base64 também)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) {
            $result['error'] = 'Arquivo muito grande. Tamanho máximo: 10MB';
            return $result;
        }

        // Lê conteúdo do arquivo e converte para base64
        $fileContent = file_get_contents($file['tmp_name']);
        $base64 = base64_encode($fileContent);

        // Retorna sucesso
        $result['success'] = true;
        $result['base64'] = $base64;
        $result['filename'] = $file['name']; // Nome original
        $result['mime_type'] = $mimeType;

    } catch (Exception $e) {
        $result['error'] = 'Erro ao processar arquivo: ' . $e->getMessage();
        error_log("Erro em saveReceiptBase64: " . $e->getMessage());
    }

    return $result;
}

/**
 * Busca dados do comprovante de pagamento
 *
 * @param string $promoter Nome do promotor
 * @param string $month Mês de referência (YYYY-MM)
 * @return array|null
 */
function getReceiptData($promoter, $month) {
    try {
        $sql = "SELECT receipt_storage_type, receipt_file_path, receipt_base64, receipt_filename, receipt_mime_type
                FROM payments
                WHERE promoter = ? AND month = ?";
        return Database::fetchOne($sql, [$promoter, $month]);
    } catch (Exception $e) {
        error_log("Erro ao buscar dados do comprovante: " . $e->getMessage());
        return null;
    }
}

/**
 * Deleta comprovante de pagamento
 *
 * @param string $promoter Nome do promotor
 * @param string $month Mês de referência (YYYY-MM)
 * @return bool
 */
function deleteReceipt($promoter, $month) {
    try {
        // Busca dados atuais
        $receipt = getReceiptData($promoter, $month);

        if (!$receipt) {
            return true; // Não há comprovante, considera sucesso
        }

        // Se for arquivo, deleta do filesystem
        if ($receipt['receipt_storage_type'] === 'file' && !empty($receipt['receipt_file_path'])) {
            $filePath = __DIR__ . '/' . $receipt['receipt_file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Limpa campos do banco de dados
        $sql = "UPDATE payments
                SET receipt_storage_type = 'none',
                    receipt_file_path = NULL,
                    receipt_base64 = NULL,
                    receipt_filename = NULL,
                    receipt_mime_type = NULL
                WHERE promoter = ? AND month = ?";

        Database::execute($sql, [$promoter, $month]);

        return true;

    } catch (Exception $e) {
        error_log("Erro ao deletar comprovante: " . $e->getMessage());
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
            // NÃO calcula comissão aqui - será calculado depois com a configuração correta

            $godmode_data[$promoter]['total_vouchers']++;
            $godmode_data[$promoter]['total_value'] += $data['value'];
            // NÃO calcula comissão aqui - será calculado depois com a configuração correta
        }
    }

    // Calcula comissões com configuração correta + marca pagamentos
    foreach ($godmode_data as $promoter => &$data) {
        $data['total_commission'] = 0; // Reseta para recalcular corretamente

        foreach ($data['months'] as $month => &$month_data) {
            // Busca configuração de comissão ESPECÍFICA deste promotor+mês
            $commission_config = getPromoterCommissionForMonth($promoter, $month);

            // Calcula comissão baseado no tipo
            if ($commission_config['type'] === 'fixed') {
                // Valor fixo POR VENDA (vouchers * valor_fixo)
                $month_data['commission'] = $month_data['vouchers'] * $commission_config['value'];
            } else {
                // Percentual sobre o valor total
                $month_data['commission'] = $month_data['value'] * ($commission_config['value'] / 100);
            }

            // Armazena info do tipo de comissão
            $month_data['commission_type'] = $commission_config['type'];
            $month_data['commission_value'] = $commission_config['value'];

            // Atualiza total
            $data['total_commission'] += $month_data['commission'];

            // Marca status de pagamento
            $month_data['paid'] = isCommissionPaid($promoter, $month);

            // Adiciona dados do comprovante de pagamento
            $receipt = getReceiptData($promoter, $month);
            $month_data['receipt'] = [
                'exists' => $receipt && $receipt['receipt_storage_type'] !== 'none',
                'type' => $receipt['receipt_storage_type'] ?? 'none',
                'filename' => $receipt['receipt_filename'] ?? null
            ];

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

        // Conta ocorrências de delimitadores
        $tab_count = substr_count($first_line, "\t");
        $comma_count = substr_count($first_line, ',');
        $semicolon_count = substr_count($first_line, ';');

        // Escolhe o delimitador mais comum
        if ($tab_count > $comma_count && $tab_count > $semicolon_count) {
            $delimiter = "\t";
        } elseif ($semicolon_count > $comma_count) {
            $delimiter = ';';
        } else {
            $delimiter = ',';
        }

        error_log("CSV Import Debug: Delimitador detectado: " . ($delimiter === "\t" ? 'TAB' : $delimiter));

        // Lê headers
        $headers = fgetcsv($handle, 10000, $delimiter);

        // Debug: Valida headers
        if (empty($headers) || !is_array($headers)) {
            error_log("CSV Import Error: Headers não puderam ser lidos!");
            fclose($handle);
            return ['success' => false, 'message' => 'Erro ao ler cabeçalhos do CSV. Verifique o formato do arquivo.'];
        }

        // Remove espaços e BOM dos headers
        $headers = array_map(function($header) {
            return trim(str_replace("\xEF\xBB\xBF", '', $header));
        }, $headers);

        error_log("CSV Import Debug: Headers encontrados: " . implode(', ', array_slice($headers, 0, 5)) . "... (" . count($headers) . " colunas)");

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

        // Prepara SQL para inserção com proteção contra duplicatas
        // Se sale_item_id + voucher_code já existem, atualiza os dados
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
        ) ON DUPLICATE KEY UPDATE
            voucher_status = VALUES(voucher_status),
            origin_place = VALUES(origin_place),
            campaign_name = VALUES(campaign_name),
            package_name = VALUES(package_name),
            product_name = VALUES(product_name),
            product_value = VALUES(product_value),
            sale_weekday = VALUES(sale_weekday),
            sale_datetime = VALUES(sale_datetime),
            visit_weekday = VALUES(visit_weekday),
            visit_date = VALUES(visit_date),
            manager = VALUES(manager),
            promoter = VALUES(promoter),
            visitor_name = VALUES(visitor_name),
            visitor_document = VALUES(visitor_document),
            visitor_email = VALUES(visitor_email),
            visitor_birthdate = VALUES(visitor_birthdate),
            visitor_sex = VALUES(visitor_sex),
            visitor_address_street = VALUES(visitor_address_street),
            visitor_address_number = VALUES(visitor_address_number),
            visitor_address_burgh = VALUES(visitor_address_burgh),
            visitor_mobile_phone = VALUES(visitor_mobile_phone),
            visitor_address_city = VALUES(visitor_address_city),
            visitor_address_state = VALUES(visitor_address_state),
            visitor_address_postal_code = VALUES(visitor_address_postal_code),
            visitor_address_country = VALUES(visitor_address_country),
            dependencies_last_update_date = VALUES(dependencies_last_update_date),
            last_update_date = VALUES(last_update_date)";

        $stmt = $db->prepare($sql);

        $imported = 0;
        $skipped = 0;
        $errors = 0;

        // Inicia transação para melhor performance
        $db->beginTransaction();

        $lineNumber = 1; // Contador de linhas

        while (($row = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
            try {
                $lineNumber++;

                // Debug: Log da primeira linha de dados
                if ($lineNumber == 2) {
                    error_log("CSV Import Debug: Primeira linha - Headers: " . count($headers) . " | Row: " . count($row));
                    error_log("CSV Import Debug: Primeiros valores: " . implode(' | ', array_slice($row, 0, 5)));
                }

                if (count($headers) !== count($row)) {
                    error_log("CSV Import Warning: Linha $lineNumber - Headers: " . count($headers) . " | Colunas: " . count($row));
                    $skipped++;
                    continue;
                }

                $data = array_combine($headers, $row);

                // Valida se array_combine funcionou
                if ($data === false) {
                    error_log("CSV Import Error: array_combine falhou na linha $lineNumber");
                    $skipped++;
                    continue;
                }

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

        // Log final
        error_log("CSV Import Finalizado: Importados: $imported | Pulados: $skipped | Erros: $errors");

        $message = "Importação concluída! Registros importados: {$imported}";
        if ($skipped > 0) $message .= ", pulados: {$skipped}";
        if ($errors > 0) $message .= ", erros: {$errors}";

        // Se nenhum registro foi importado, algo está errado
        if ($imported == 0 && $errors == 0) {
            error_log("CSV Import Warning: Nenhum registro foi importado!");
            $message .= " ATENÇÃO: Nenhum registro foi importado. Verifique o formato do arquivo CSV.";
        }

        return [
            'success' => true,
            'message' => $message,
            'stats' => [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'replace' => $replace,
                'total_lines' => $lineNumber
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
        // Busca dados separados por tipo de campanha
        $sql = "SELECT
                    month_reference,
                    -- Vendas de Promotores (sem SITE)
                    COUNT(CASE WHEN campaign_name NOT LIKE '%SITE%' THEN 1 END) as promoter_records,
                    COUNT(DISTINCT CASE WHEN campaign_name NOT LIKE '%SITE%' THEN promoter END) as promoter_count,
                    COUNT(DISTINCT CASE WHEN campaign_name NOT LIKE '%SITE%' THEN voucher_code END) as promoter_vouchers,
                    SUM(CASE WHEN campaign_name NOT LIKE '%SITE%' THEN product_value ELSE 0 END) as promoter_value,
                    -- Vendas do Site (com SITE)
                    COUNT(CASE WHEN campaign_name LIKE '%SITE%' THEN 1 END) as site_records,
                    COUNT(DISTINCT CASE WHEN campaign_name LIKE '%SITE%' THEN voucher_code END) as site_vouchers,
                    SUM(CASE WHEN campaign_name LIKE '%SITE%' THEN product_value ELSE 0 END) as site_value,
                    -- Totais gerais
                    COUNT(*) as total_records,
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

/**
 * Remove duplicatas da tabela sales
 * Mantém apenas o registro mais recente de cada sale_item_id
 *
 * @param int $userId ID do usuário executando a ação
 * @return array
 */
function removeDuplicateSales($userId) {
    try {
        $db = Database::getConnection();

        // Primeiro, identifica sale_item_id duplicados
        $sql = "SELECT sale_item_id, COUNT(*) as count
                FROM sales
                GROUP BY sale_item_id
                HAVING count > 1";

        $duplicates = Database::fetchAll($sql);

        if (empty($duplicates)) {
            return [
                'success' => true,
                'message' => 'Nenhuma duplicata encontrada!',
                'removed' => 0
            ];
        }

        $db->beginTransaction();

        $total_removed = 0;

        foreach ($duplicates as $dup) {
            // Para cada sale_item_id duplicado, mantém apenas o registro mais recente (maior id)
            $sql = "DELETE FROM sales
                    WHERE sale_item_id = ?
                    AND id NOT IN (
                        SELECT * FROM (
                            SELECT MAX(id)
                            FROM sales
                            WHERE sale_item_id = ?
                        ) AS temp
                    )";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $dup['sale_item_id'],
                $dup['sale_item_id']
            ]);

            $total_removed += $stmt->rowCount();
        }

        $db->commit();

        // Log de auditoria
        logAudit($userId, 'remove_duplicate_sales', 'sales', null, null, json_encode([
            'duplicates_found' => count($duplicates),
            'records_removed' => $total_removed
        ]));

        return [
            'success' => true,
            'message' => "Removidas $total_removed duplicatas! " . count($duplicates) . " Sale Item IDs duplicados foram limpos.",
            'removed' => $total_removed
        ];

    } catch (Exception $e) {
        error_log("Erro ao remover duplicatas: " . $e->getMessage());

        try {
            $db->rollBack();
        } catch (Exception $rollbackError) {
            // Ignora
        }

        return [
            'success' => false,
            'message' => 'Erro ao remover duplicatas: ' . $e->getMessage()
        ];
    }
}

/**
 * Deleta todos os dados de vendas do banco
 *
 * @param int $userId ID do usuário executando a ação
 * @return array
 */
function deleteAllSalesData($userId) {
    try {
        $db = Database::getConnection();

        // Conta quantos registros serão deletados
        $sql = "SELECT COUNT(*) as count FROM sales";
        $result = Database::fetchOne($sql);
        $count = $result['count'];

        if ($count == 0) {
            return [
                'success' => true,
                'message' => 'Nenhum dado para deletar.',
                'deleted' => 0
            ];
        }

        // Deleta todos os registros
        $sql = "DELETE FROM sales";
        Database::execute($sql);

        // Log de auditoria
        logAudit($userId, 'delete_all_sales', 'sales', null, null, json_encode([
            'records_deleted' => $count
        ]));

        return [
            'success' => true,
            'message' => "Todos os dados foram deletados! ($count registros removidos)",
            'deleted' => $count
        ];

    } catch (Exception $e) {
        error_log("Erro ao deletar dados: " . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Erro ao deletar dados: ' . $e->getMessage()
        ];
    }
}

/**
 * Deleta dados de vendas de um mês específico
 *
 * @param string $monthReference Mês no formato YYYY-MM
 * @param int $userId ID do usuário executando a ação
 * @return array
 */
function deleteSalesDataByMonth($monthReference, $userId) {
    try {
        // Conta quantos registros serão deletados
        $sql = "SELECT COUNT(*) as count FROM sales WHERE month_reference = ?";
        $result = Database::fetchOne($sql, [$monthReference]);
        $count = $result['count'];

        if ($count == 0) {
            return [
                'success' => true,
                'message' => 'Nenhum dado encontrado para este mês.',
                'deleted' => 0
            ];
        }

        // Deleta registros do mês
        $sql = "DELETE FROM sales WHERE month_reference = ?";
        Database::execute($sql, [$monthReference]);

        // Log de auditoria
        logAudit($userId, 'delete_sales_month', 'sales', null, null, json_encode([
            'month' => $monthReference,
            'records_deleted' => $count
        ]));

        return [
            'success' => true,
            'message' => "Dados de $monthReference deletados! ($count registros removidos)",
            'deleted' => $count
        ];

    } catch (Exception $e) {
        error_log("Erro ao deletar dados do mês: " . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Erro ao deletar dados: ' . $e->getMessage()
        ];
    }
}

// ===== GESTÃO DE PROMOTORES =====

/**
 * Importa CSV de promotores para o banco de dados
 *
 * @param string $csvFilePath Caminho do arquivo CSV
 * @param int $userId ID do usuário que está importando
 * @param bool $replace Se true, substitui todos os dados existentes
 * @return array
 */
function importPromotersCSV($csvFilePath, $userId, $replace = false) {
    try {
        if (!file_exists($csvFilePath)) {
            return ['success' => false, 'message' => 'Arquivo CSV não encontrado!'];
        }

        $db = Database::getConnection();

        // Se replace = true, deleta todos os promotores existentes
        if ($replace) {
            $sql = "DELETE FROM promoters";
            $deleted = Database::execute($sql);

            // Log de auditoria
            logAudit($userId, 'delete_all_promoters', 'promoters', null, null, json_encode(['deleted_rows' => $deleted]));
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

        // Conta ocorrências de delimitadores
        $tab_count = substr_count($first_line, "\t");
        $comma_count = substr_count($first_line, ',');
        $semicolon_count = substr_count($first_line, ';');

        // Escolhe o delimitador mais comum
        if ($tab_count > $comma_count && $tab_count > $semicolon_count) {
            $delimiter = "\t";
        } elseif ($semicolon_count > $comma_count) {
            $delimiter = ';';
        } else {
            $delimiter = ',';
        }

        error_log("Promoters CSV Import Debug: Delimitador detectado: " . ($delimiter === "\t" ? 'TAB' : $delimiter));

        // Lê headers
        $headers = fgetcsv($handle, 10000, $delimiter);

        // Debug: Valida headers
        if (empty($headers) || !is_array($headers)) {
            error_log("Promoters CSV Import Error: Headers não puderam ser lidos!");
            fclose($handle);
            return ['success' => false, 'message' => 'Erro ao ler cabeçalhos do CSV. Verifique o formato do arquivo.'];
        }

        // Remove espaços e BOM dos headers
        $headers = array_map(function($header) {
            return trim(str_replace("\xEF\xBB\xBF", '', $header));
        }, $headers);

        error_log("Promoters CSV Import Debug: Headers encontrados: " . implode(', ', $headers));

        // Mapeia headers para campos do banco
        // Esperado: Nome_Promotor, Titulo, Comissão, Status, Documento, Rg, Rua, Numero, Compl, Bairro, Cidade, UF, PostalCode, Celular
        $headerMap = [
            'Nome_Promotor' => 'name',
            'Titulo' => 'title',
            'Comissão' => 'commission_percentage',
            'Status' => 'status',
            'Documento' => 'document',
            'Rg' => 'rg',
            'Rua' => 'street',
            'Numero' => 'number',
            'Compl' => 'complement',
            'Bairro' => 'neighborhood',
            'Cidade' => 'city',
            'UF' => 'state',
            'PostalCode' => 'postal_code',
            'Celular' => 'mobile_phone'
        ];

        // Prepara SQL para inserção com ON DUPLICATE KEY UPDATE para nomes duplicados
        $sql = "INSERT INTO promoters (
            name, title, commission_percentage, status, document,
            rg, street, number, complement, neighborhood,
            city, state, postal_code, mobile_phone
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        ) ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            commission_percentage = VALUES(commission_percentage),
            status = VALUES(status),
            document = VALUES(document),
            rg = VALUES(rg),
            street = VALUES(street),
            number = VALUES(number),
            complement = VALUES(complement),
            neighborhood = VALUES(neighborhood),
            city = VALUES(city),
            state = VALUES(state),
            postal_code = VALUES(postal_code),
            mobile_phone = VALUES(mobile_phone)";

        $stmt = $db->prepare($sql);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        // Inicia transação
        $db->beginTransaction();

        $lineNumber = 1; // Contador de linhas

        while (($row = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
            try {
                $lineNumber++;

                // Debug: Log da primeira linha de dados
                if ($lineNumber == 2) {
                    error_log("Promoters CSV Import Debug: Primeira linha - Headers: " . count($headers) . " | Row: " . count($row));
                    error_log("Promoters CSV Import Debug: Primeiros valores: " . implode(' | ', array_slice($row, 0, 5)));
                }

                if (count($headers) !== count($row)) {
                    error_log("Promoters CSV Import Warning: Linha $lineNumber - Headers: " . count($headers) . " | Colunas: " . count($row));
                    $skipped++;
                    continue;
                }

                $data = array_combine($headers, $row);

                // Valida se array_combine funcionou
                if ($data === false) {
                    error_log("Promoters CSV Import Error: array_combine falhou na linha $lineNumber");
                    $skipped++;
                    continue;
                }

                // Extrai dados mapeados
                $name = trim($data['Nome_Promotor'] ?? '');

                // Pula linhas vazias
                if (empty($name)) {
                    $skipped++;
                    continue;
                }

                $title = trim($data['Titulo'] ?? '');
                $commission_percentage = floatval(str_replace(',', '.', trim($data['Comissão'] ?? '25.00')));
                $status = trim($data['Status'] ?? 'Ativo');
                $document = trim($data['Documento'] ?? '');
                $rg = trim($data['Rg'] ?? '');
                $street = trim($data['Rua'] ?? '');
                $number = trim($data['Numero'] ?? '');
                $complement = trim($data['Compl'] ?? '');
                $neighborhood = trim($data['Bairro'] ?? '');
                $city = trim($data['Cidade'] ?? '');
                $state = trim($data['UF'] ?? '');
                $postal_code = trim($data['PostalCode'] ?? '');
                $mobile_phone = trim($data['Celular'] ?? '');

                // Normaliza status
                if ($status !== 'Ativo' && $status !== 'Desativado') {
                    $status = 'Ativo';
                }

                // Executa inserção
                $stmt->execute([
                    $name,
                    $title,
                    $commission_percentage,
                    $status,
                    $document,
                    $rg,
                    $street,
                    $number,
                    $complement,
                    $neighborhood,
                    $city,
                    $state,
                    $postal_code,
                    $mobile_phone
                ]);

                $imported++;

            } catch (Exception $e) {
                error_log("Promoters CSV Import Error linha $lineNumber: " . $e->getMessage());
                $errors++;

                // Se muitos erros, aborta
                if ($errors > 10) {
                    $db->rollBack();
                    fclose($handle);
                    return [
                        'success' => false,
                        'message' => 'Muitos erros durante a importação. Verifique o formato do arquivo.'
                    ];
                }
            }
        }

        fclose($handle);

        // Commit da transação
        $db->commit();

        // Log de auditoria
        logAudit($userId, 'import_promoters_csv', 'promoters', null, null, json_encode([
            'file' => basename($csvFilePath),
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'replace_mode' => $replace
        ]));

        $message = "Importação concluída! ";
        $message .= "Importados: $imported | ";
        $message .= "Ignorados: $skipped";

        if ($errors > 0) {
            $message .= " | Erros: $errors";
        }

        return [
            'success' => true,
            'message' => $message,
            'stats' => [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors
            ]
        ];

    } catch (Exception $e) {
        error_log("Promoters CSV Import Exception: " . $e->getMessage());

        // Rollback em caso de erro
        try {
            $db->rollBack();
        } catch (Exception $rollbackError) {
            // Ignora erro de rollback se transação não estiver ativa
        }

        return [
            'success' => false,
            'message' => 'Erro ao importar CSV: ' . $e->getMessage()
        ];
    }
}

/**
 * Lista todos os promotores do banco
 *
 * @param bool $activeOnly Se true, retorna apenas promotores ativos
 * @return array
 */
function getAllPromoters($activeOnly = false) {
    try {
        $sql = "SELECT * FROM promoters";

        if ($activeOnly) {
            $sql .= " WHERE status = 'Ativo'";
        }

        $sql .= " ORDER BY name ASC";

        return Database::fetchAll($sql);
    } catch (Exception $e) {
        error_log("Erro ao listar promotores: " . $e->getMessage());
        return [];
    }
}

/**
 * Busca promotor por nome
 *
 * @param string $name
 * @return array|false
 */
function getPromoterByName($name) {
    try {
        $sql = "SELECT * FROM promoters WHERE name = ?";
        return Database::fetchOne($sql, [$name]);
    } catch (Exception $e) {
        error_log("Erro ao buscar promotor: " . $e->getMessage());
        return false;
    }
}

/**
 * Atualiza PIN do promotor
 *
 * @param int $promoterId
 * @param string $pin PIN em texto plano (será hasheado)
 * @return bool
 */
function updatePromoterPIN($promoterId, $pin) {
    try {
        $hashedPin = password_hash($pin, PASSWORD_BCRYPT);
        $sql = "UPDATE promoters SET pin = ?, pin_attempts = 0, pin_last_reset = NULL WHERE id = ?";
        Database::execute($sql, [$hashedPin, $promoterId]);

        return true;
    } catch (Exception $e) {
        error_log("Erro ao atualizar PIN: " . $e->getMessage());
        return false;
    }
}

/**
 * Verifica PIN do promotor
 *
 * @param int $promoterId
 * @param string $pin
 * @return bool
 */
function verifyPromoterPIN($promoterId, $pin) {
    try {
        $sql = "SELECT pin, pin_attempts FROM promoters WHERE id = ?";
        $promoter = Database::fetchOne($sql, [$promoterId]);

        if (!$promoter || empty($promoter['pin'])) {
            return false;
        }

        // Verifica se ultrapassou 3 tentativas
        if ($promoter['pin_attempts'] >= 3) {
            // Reseta PIN
            resetPromoterPIN($promoterId);
            return false;
        }

        // Verifica PIN
        if (password_verify($pin, $promoter['pin'])) {
            // PIN correto - reseta tentativas
            $sql = "UPDATE promoters SET pin_attempts = 0 WHERE id = ?";
            Database::execute($sql, [$promoterId]);
            return true;
        } else {
            // PIN incorreto - incrementa tentativas
            $sql = "UPDATE promoters SET pin_attempts = pin_attempts + 1 WHERE id = ?";
            Database::execute($sql, [$promoterId]);
            return false;
        }

    } catch (Exception $e) {
        error_log("Erro ao verificar PIN: " . $e->getMessage());
        return false;
    }
}

/**
 * Reseta PIN do promotor (após 3 tentativas erradas)
 *
 * @param int $promoterId
 * @return bool
 */
function resetPromoterPIN($promoterId) {
    try {
        $sql = "UPDATE promoters SET pin = NULL, pin_attempts = 0, pin_last_reset = NOW() WHERE id = ?";
        Database::execute($sql, [$promoterId]);

        return true;
    } catch (Exception $e) {
        error_log("Erro ao resetar PIN: " . $e->getMessage());
        return false;
    }
}

/**
 * Mascara os nomes do meio para segurança
 * Exemplo: "JOÃO PEDRO SILVA SANTOS" vira "JOÃO *** SANTOS"
 *
 * @param string $fullName
 * @return string
 */
function maskMiddleNames($fullName) {
    $parts = explode(' ', trim($fullName));
    $count = count($parts);

    if ($count <= 2) {
        // Se tem 1 ou 2 nomes, retorna sem mascarar
        return $fullName;
    }

    // Pega o primeiro e o último nome
    $firstName = $parts[0];
    $lastName = $parts[$count - 1];

    // Mascara os nomes do meio
    return $firstName . ' *** ' . $lastName;
}

/**
 * Busca saldo acumulado de um promotor nos meses anteriores
 * Consulta o banco de dados e verifica status de pagamento
 *
 * @param string $promoterName Nome do promotor
 * @param string $currentMonth Mês atual (formato YYYY-MM)
 * @return array
 */
function getPromoterAccumulatedBalance($promoterName, $currentMonth) {
    try {
        $sql = "SELECT
                    s.month_reference,
                    COUNT(DISTINCT s.voucher_code) as quantity,
                    SUM(s.product_value) as total,
                    p.paid,
                    p.paid_at,
                    p.paid_by
                FROM sales s
                LEFT JOIN payments p ON (s.promoter = p.promoter AND s.month_reference = p.month)
                WHERE s.promoter = ?
                    AND s.month_reference <= ?
                    AND s.campaign_name NOT LIKE '%SITE%'
                GROUP BY s.month_reference, p.paid, p.paid_at, p.paid_by
                ORDER BY s.month_reference DESC";

        $months = Database::fetchAll($sql, [$promoterName, $currentMonth]);

        $result = [
            'total_quantity' => 0,
            'total_value' => 0,
            'total_commission' => 0,
            'months' => []
        ];

        foreach ($months as $month) {
            // IMPORTANTE: Busca a comissão ESPECÍFICA daquele mês
            // Isso permite que cada mês tenha sua própria comissão (% ou R$ fixo)
            $commission_config = getPromoterCommissionForMonth($promoterName, $month['month_reference']);

            // Calcula comissão baseado no tipo
            if ($commission_config['type'] === 'fixed') {
                // Valor fixo POR VENDA (quantidade * valor_fixo)
                $commission = (int)$month['quantity'] * $commission_config['value'];
            } else {
                // Percentual sobre o valor total
                $commission = $month['total'] * ($commission_config['value'] / 100);
            }

            $result['months'][] = [
                'month' => $month['month_reference'],
                'quantity' => (int)$month['quantity'],
                'total' => (float)$month['total'],
                'commission' => $commission,
                'commission_type' => $commission_config['type'],
                'commission_value' => $commission_config['value'],
                'commission_percentage' => $commission_config['value'], // Mantém compatibilidade
                'paid' => (bool)$month['paid'],
                'paid_at' => $month['paid_at'],
                'paid_by' => $month['paid_by']
            ];

            // Só soma no acumulado se NÃO foi pago
            if (!$month['paid']) {
                $result['total_quantity'] += (int)$month['quantity'];
                $result['total_value'] += (float)$month['total'];
                $result['total_commission'] += $commission;
            }
        }

        return $result;

    } catch (Exception $e) {
        error_log("Erro ao buscar saldo acumulado: " . $e->getMessage());
        return [
            'total_quantity' => 0,
            'total_value' => 0,
            'total_commission' => 0,
            'months' => []
        ];
    }
}

/**
 * Busca estatísticas do mês atual de um promotor
 *
 * @param string $promoterName Nome do promotor
 * @param string $month Mês (formato YYYY-MM)
 * @return array
 */
function getPromoterMonthStats($promoterName, $month) {
    try {
        $sql = "SELECT
                    COUNT(DISTINCT voucher_code) as quantity,
                    SUM(product_value) as total
                FROM sales
                WHERE promoter = ?
                    AND month_reference = ?
                    AND campaign_name NOT LIKE '%SITE%'";

        $stats = Database::fetchOne($sql, [$promoterName, $month]);

        if (!$stats) {
            return [
                'quantity' => 0,
                'total' => 0,
                'commission' => 0,
                'commission_type' => 'percentage',
                'commission_value' => 25.00,
                'commission_percentage' => 25.00
            ];
        }

        // Busca comissão ESPECÍFICA deste mês
        $commission_config = getPromoterCommissionForMonth($promoterName, $month);

        // Calcula comissão baseado no tipo
        if ($commission_config['type'] === 'fixed') {
            // Valor fixo POR VENDA (quantidade * valor_fixo)
            $commission = (int)$stats['quantity'] * $commission_config['value'];
        } else {
            // Percentual sobre o valor total
            $commission = (float)$stats['total'] * ($commission_config['value'] / 100);
        }

        return [
            'quantity' => (int)$stats['quantity'],
            'total' => (float)$stats['total'],
            'commission' => $commission,
            'commission_type' => $commission_config['type'],
            'commission_value' => $commission_config['value'],
            'commission_percentage' => $commission_config['value'] // Mantém compatibilidade
        ];

    } catch (Exception $e) {
        error_log("Erro ao buscar stats do mês: " . $e->getMessage());
        return [
            'quantity' => 0,
            'total' => 0,
            'commission' => 0
        ];
    }
}

/**
 * Debug: Compara dados do CSV com dados do banco
 *
 * @param string $month Mês no formato YYYY-MM
 * @return array
 */
function debugMonthData($month) {
    try {
        // Dados do banco (filtra "Dayuse SITE")
        $sql = "SELECT
                    COUNT(*) as total_records,
                    COUNT(DISTINCT voucher_code) as unique_vouchers,
                    COUNT(DISTINCT promoter) as unique_promoters,
                    COUNT(DISTINCT sale_item_id) as unique_sale_items,
                    SUM(product_value) as total_value
                FROM sales
                WHERE month_reference = ?
                    AND campaign_name NOT LIKE '%SITE%'";

        $db_data = Database::fetchOne($sql, [$month]);

        // Busca sale_item_id duplicados (PROBLEMA REAL)
        $sql = "SELECT
                    sale_item_id,
                    COUNT(*) as count,
                    COUNT(DISTINCT voucher_code) as different_vouchers,
                    GROUP_CONCAT(DISTINCT voucher_code SEPARATOR ', ') as vouchers,
                    SUM(product_value) as total_value
                FROM sales
                WHERE month_reference = ?
                    AND campaign_name NOT LIKE '%SITE%'
                GROUP BY sale_item_id
                HAVING count > 1
                ORDER BY count DESC
                LIMIT 20";

        $duplicated_sale_items = Database::fetchAll($sql, [$month]);

        // Busca vouchers duplicados (mesmo voucher importado múltiplas vezes)
        $sql = "SELECT
                    voucher_code,
                    COUNT(*) as total_occurrences,
                    COUNT(DISTINCT sale_item_id) as different_sale_items,
                    GROUP_CONCAT(DISTINCT sale_item_id ORDER BY sale_item_id SEPARATOR ', ') as sale_items,
                    SUM(product_value) as total_value
                FROM sales
                WHERE month_reference = ?
                    AND campaign_name NOT LIKE '%SITE%'
                GROUP BY voucher_code
                HAVING COUNT(DISTINCT sale_item_id) < total_occurrences
                ORDER BY total_occurrences DESC
                LIMIT 20";

        $duplicated_vouchers = Database::fetchAll($sql, [$month]);

        // Verifica se há registros com voucher_code vazio ou null
        $sql = "SELECT COUNT(*) as count FROM sales WHERE month_reference = ? AND campaign_name NOT LIKE '%SITE%' AND (voucher_code IS NULL OR voucher_code = '')";
        $empty_vouchers = Database::fetchOne($sql, [$month]);

        // Conta registros por data de importação
        $sql = "SELECT
                    DATE(imported_at) as import_date,
                    COUNT(*) as records_imported,
                    COUNT(DISTINCT voucher_code) as unique_vouchers
                FROM sales
                WHERE month_reference = ?
                    AND campaign_name NOT LIKE '%SITE%'
                GROUP BY DATE(imported_at)
                ORDER BY import_date DESC";

        $import_history = Database::fetchAll($sql, [$month]);

        return [
            'month' => $month,
            'database' => [
                'total_records' => (int)$db_data['total_records'],
                'unique_vouchers' => (int)$db_data['unique_vouchers'],
                'unique_sale_items' => (int)$db_data['unique_sale_items'],
                'unique_promoters' => (int)$db_data['unique_promoters'],
                'total_value' => (float)$db_data['total_value']
            ],
            'issues' => [
                'duplicated_sale_items' => $duplicated_sale_items,
                'duplicated_vouchers' => $duplicated_vouchers,
                'empty_vouchers' => (int)$empty_vouchers['count']
            ],
            'import_history' => $import_history
        ];

    } catch (Exception $e) {
        error_log("Erro no debug: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * Armazena percentual de comissão histórico
 * IMPORTANTE: Chamar esta função sempre que importar um CSV
 *
 * @param string $promoterName Nome do promotor
 * @param string $month Mês (YYYY-MM)
 * @param float $commissionPercentage Percentual (ex: 25.00 para 25%)
 * @return bool
 */
function savePromoterCommissionHistory($promoterName, $month, $commissionPercentage) {
    try {
        // Cria tabela de histórico se não existir
        $db = Database::getConnection();
        
        $sql = "CREATE TABLE IF NOT EXISTS `promoter_commission_history` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `promoter_name` VARCHAR(255) NOT NULL,
            `month_reference` VARCHAR(7) NOT NULL,
            `commission_percentage` DECIMAL(5, 2) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_promoter_month` (`promoter_name`, `month_reference`),
            INDEX `idx_promoter` (`promoter_name`),
            INDEX `idx_month` (`month_reference`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $db->exec($sql);

        // Insere ou atualiza
        $sql = "INSERT INTO promoter_commission_history (promoter_name, month_reference, commission_percentage)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE commission_percentage = VALUES(commission_percentage)";
        
        Database::execute($sql, [$promoterName, $month, $commissionPercentage]);

        return true;

    } catch (Exception $e) {
        error_log("Erro ao salvar histórico de comissão: " . $e->getMessage());
        return false;
    }
}

/**
 * Busca percentual de comissão de um promotor em um mês específico
 *
 * @param string $promoterName Nome do promotor
 * @param string $month Mês (YYYY-MM)
 * @return float Percentual (ex: 25.00)
 */
function getPromoterCommissionForMonth($promoterName, $month) {
    try {
        // PRIORIDADE 1: Busca comissão ESPECÍFICA do promotor+mês
        $sql = "SELECT commission_type, commission_value, commission_percentage
                FROM promoter_commission_history
                WHERE promoter_name = ? AND month_reference = ?";

        $history = Database::fetchOne($sql, [$promoterName, $month]);

        if ($history) {
            // Se tem novo formato (commission_type/commission_value)
            if (isset($history['commission_type'])) {
                return [
                    'type' => $history['commission_type'],
                    'value' => (float)$history['commission_value']
                ];
            }
            // Formato antigo (apenas percentage)
            if (isset($history['commission_percentage'])) {
                return [
                    'type' => 'percentage',
                    'value' => (float)$history['commission_percentage']
                ];
            }
        }

        // PRIORIDADE 2: Busca comissão GERAL do mês (promoter_name IS NULL)
        $sql = "SELECT commission_type, commission_value
                FROM promoter_commission_history
                WHERE promoter_name IS NULL AND month_reference = ?";

        $monthDefault = Database::fetchOne($sql, [$month]);

        if ($monthDefault && isset($monthDefault['commission_type'])) {
            return [
                'type' => $monthDefault['commission_type'],
                'value' => (float)$monthDefault['commission_value']
            ];
        }

        // PRIORIDADE 3: Busca do cadastro do promotor
        $promoter = getPromoterByName($promoterName);
        if ($promoter && !empty($promoter['commission_percentage'])) {
            return [
                'type' => 'percentage',
                'value' => (float)$promoter['commission_percentage']
            ];
        }

        // PRIORIDADE 4: Padrão global: 25%
        return [
            'type' => 'percentage',
            'value' => 25.00
        ];

    } catch (Exception $e) {
        error_log("Erro ao buscar comissão do mês: " . $e->getMessage());
        return [
            'type' => 'percentage',
            'value' => 25.00
        ];
    }
}
