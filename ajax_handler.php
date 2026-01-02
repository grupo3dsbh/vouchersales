<?php
/**
 * Handler AJAX para operações de pagamento
 */
session_start();
header('Content-Type: application/json');

define('DATA_DIR', __DIR__ . '/data');
require_once 'functions.php';

// Verifica autenticação GODMODE
if (!isset($_SESSION['godmode_authenticated']) || $_SESSION['godmode_authenticated'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$action = $_POST['action'] ?? '';
$promoter = $_POST['promoter'] ?? '';
$month = $_POST['month'] ?? '';
$paid = isset($_POST['paid']) ? (bool)$_POST['paid'] : false;
$user = $_SESSION['godmode_user'] ?? 'Administrador';

if ($action === 'toggle_payment' && !empty($promoter) && !empty($month)) {
    $result = togglePayment($promoter, $month, $paid, $user);
    
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
