<?php
/**
 * Funções Auxiliares do Sistema
 */

// Carrega configurações
function loadConfig() {
    $config_file = __DIR__ . '/config.json';
    if (file_exists($config_file)) {
        return json_decode(file_get_contents($config_file), true);
    }
    return [
        'admin_password' => 'admin@2025',
        'godmode_password' => 'godmode@2025',
        'commission_percentage' => 0.25,
        'app_name' => 'Sistema de Vendas',
        'timezone' => 'America/Sao_Paulo'
    ];
}

// Carrega dados de comissões
function loadCommissions() {
    $commissions_file = DATA_DIR . '/comissoes.json';
    if (file_exists($commissions_file)) {
        return json_decode(file_get_contents($commissions_file), true);
    }
    return ['payments' => []];
}

// Salva dados de comissões
function saveCommissions($data) {
    $commissions_file = DATA_DIR . '/comissoes.json';
    return file_put_contents($commissions_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Verifica se comissão foi paga
function isCommissionPaid($promoter, $month) {
    $commissions = loadCommissions();
    $key = "{$promoter}|{$month}";
    return isset($commissions['payments'][$key]) && $commissions['payments'][$key]['paid'] === true;
}

// Retorna dados do pagamento
function getPaymentData($promoter, $month) {
    $commissions = loadCommissions();
    $key = "{$promoter}|{$month}";
    return $commissions['payments'][$key] ?? null;
}

// Marca/desmarca pagamento
function togglePayment($promoter, $month, $paid, $user) {
    $commissions = loadCommissions();
    $key = "{$promoter}|{$month}";
    
    if ($paid) {
        $commissions['payments'][$key] = [
            'paid' => true,
            'date' => date('Y-m-d H:i:s'),
            'user' => $user
        ];
    } else {
        if (isset($commissions['payments'][$key])) {
            $commissions['payments'][$key] = [
                'paid' => false,
                'date' => date('Y-m-d H:i:s'),
                'user' => $user,
                'unmarked_by' => $user
            ];
        }
    }
    
    return saveCommissions($commissions);
}

// Lista todos os meses disponíveis
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

// Gera nome do arquivo baseado no mês/ano
function getDataFile($month_year) {
    if (empty($month_year)) {
        return null;
    }
    list($year, $month) = explode('-', $month_year);
    $months_pt = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $month_name = $months_pt[(int)$month - 1];
    return DATA_DIR . "/ingressos_{$month_name}_{$year}.csv";
}

// Carrega dados do CSV
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

// Processa vouchers de um arquivo CSV
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

// Calcula estatísticas de todos os meses para GODMODE
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
        }
    }
    
    ksort($godmode_data);
    return $godmode_data;
}

// Formata número para formato brasileiro
function formatBRL($value) {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

// ===== GESTÃO DE USUÁRIOS =====

// Carrega usuários
function loadUsers() {
    $users_file = DATA_DIR . '/users.json';
    if (file_exists($users_file)) {
        return json_decode(file_get_contents($users_file), true);
    }
    return [
        'users' => [
            [
                'id' => 1,
                'username' => 'admin',
                'password' => 'godmode@2025',
                'name' => 'Administrador',
                'created_at' => date('Y-m-d H:i:s'),
                'active' => true
            ]
        ]
    ];
}

// Salva usuários
function saveUsers($data) {
    $users_file = DATA_DIR . '/users.json';
    return file_put_contents($users_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Verifica login de usuário
function authenticateUser($username, $password) {
    $users_data = loadUsers();
    foreach ($users_data['users'] as $user) {
        if ($user['active'] && $user['username'] === $username && $user['password'] === $password) {
            return $user;
        }
    }
    return false;
}

// Adiciona novo usuário
function addUser($username, $password, $name) {
    $users_data = loadUsers();
    
    // Verifica se username já existe
    foreach ($users_data['users'] as $user) {
        if ($user['username'] === $username) {
            return ['success' => false, 'message' => 'Nome de usuário já existe!'];
        }
    }
    
    // Gera novo ID
    $new_id = 1;
    if (!empty($users_data['users'])) {
        $ids = array_column($users_data['users'], 'id');
        $new_id = max($ids) + 1;
    }
    
    $users_data['users'][] = [
        'id' => $new_id,
        'username' => $username,
        'password' => $password,
        'name' => $name,
        'created_at' => date('Y-m-d H:i:s'),
        'active' => true
    ];
    
    if (saveUsers($users_data)) {
        return ['success' => true, 'message' => 'Usuário criado com sucesso!'];
    }
    
    return ['success' => false, 'message' => 'Erro ao salvar usuário!'];
}

// Edita usuário
function editUser($id, $username, $password, $name) {
    $users_data = loadUsers();
    
    // Verifica se username já existe em outro usuário
    foreach ($users_data['users'] as $user) {
        if ($user['username'] === $username && $user['id'] != $id) {
            return ['success' => false, 'message' => 'Nome de usuário já existe!'];
        }
    }
    
    foreach ($users_data['users'] as &$user) {
        if ($user['id'] == $id) {
            $user['username'] = $username;
            if (!empty($password)) {
                $user['password'] = $password;
            }
            $user['name'] = $name;
            
            if (saveUsers($users_data)) {
                return ['success' => true, 'message' => 'Usuário atualizado com sucesso!'];
            }
            break;
        }
    }
    
    return ['success' => false, 'message' => 'Erro ao atualizar usuário!'];
}

// Desativa usuário
function toggleUserStatus($id, $active) {
    $users_data = loadUsers();
    
    foreach ($users_data['users'] as &$user) {
        if ($user['id'] == $id) {
            $user['active'] = $active;
            
            if (saveUsers($users_data)) {
                return ['success' => true, 'message' => $active ? 'Usuário ativado!' : 'Usuário desativado!'];
            }
            break;
        }
    }
    
    return ['success' => false, 'message' => 'Erro ao alterar status!'];
}

// Deleta usuário
function deleteUser($id) {
    $users_data = loadUsers();
    
    // Não permite deletar o ID 1 (admin principal)
    if ($id == 1) {
        return ['success' => false, 'message' => 'Não é possível deletar o usuário administrador principal!'];
    }
    
    $users_data['users'] = array_filter($users_data['users'], function($user) use ($id) {
        return $user['id'] != $id;
    });
    
    $users_data['users'] = array_values($users_data['users']);
    
    if (saveUsers($users_data)) {
        return ['success' => true, 'message' => 'Usuário deletado com sucesso!'];
    }
    
    return ['success' => false, 'message' => 'Erro ao deletar usuário!'];
}