<?php
/**
 * Handler AJAX para operações de pagamento
 * Versão 2.0 - Com Banco de Dados e Segurança
 */
session_start();
header('Content-Type: application/json');

define('DATA_DIR', __DIR__ . '/data');
require_once 'functions.php';

// Verifica timeout de sessão
if (!checkSessionTimeout()) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

// Verifica autenticação GODMODE
if (!isset($_SESSION['godmode_authenticated']) || $_SESSION['godmode_authenticated'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

// Valida token CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
    exit;
}

// Sanitiza inputs
$action = sanitizeInput($_POST['action'] ?? '');
$promoter = sanitizeInput($_POST['promoter'] ?? '');
$month = sanitizeInput($_POST['month'] ?? '');
$paid = isset($_POST['paid']) ? (bool)$_POST['paid'] : false;
$userId = $_SESSION['godmode_user_id'] ?? 0;

// Valida dados obrigatórios
if ($action === 'toggle_payment' && !empty($promoter) && !empty($month)) {
    // Busca dados do promotor para pegar valores
    $amount = floatval($_POST['amount'] ?? 0);
    $vouchers = intval($_POST['vouchers'] ?? 0);

    $result = togglePayment($promoter, $month, $paid, $userId, $amount, $vouchers);

    if ($result) {
        $payment_data = getPaymentData($promoter, $month);
        echo json_encode([
            'success' => true,
            'message' => $paid ? 'Pagamento marcado com sucesso!' : 'Pagamento desmarcado com sucesso!',
            'data' => $payment_data
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao salvar dados'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Dados inválidos'
    ]);
}
