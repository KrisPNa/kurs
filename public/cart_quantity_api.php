<?php
session_start();
require 'db.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit();
}

$userId = (int)$_SESSION['user_id'];
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

if ($productId <= 0 || !in_array($action, ['inc', 'dec'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT quantity FROM cart WHERE user_id = ? AND product_id = ? FOR UPDATE');
    $stmt->execute([$userId, $productId]);
    $cartRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentQty = $cartRow ? (int)$cartRow['quantity'] : 0;

    if ($action === 'inc') {
        // Проверяем, что товар есть в меню и остаток > 0
        $stmt = $pdo->prepare('SELECT quantity FROM menu WHERE product_id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $menuRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$menuRow) {
            throw new Exception('Товар не найден');
        }
        if ((int)$menuRow['quantity'] <= 0) {
            throw new Exception('Товара нет в наличии');
        }

        if ($currentQty > 0) {
            $stmt = $pdo->prepare('UPDATE cart SET quantity = quantity + 1 WHERE user_id = ? AND product_id = ?');
            $stmt->execute([$userId, $productId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1)');
            $stmt->execute([$userId, $productId]);
        }

        $stmt = $pdo->prepare('UPDATE menu SET quantity = quantity - 1 WHERE product_id = ?');
        $stmt->execute([$productId]);
        $newQty = $currentQty + 1;
    } else {
        // dec
        if ($currentQty <= 0) {
            $pdo->rollBack();
            echo json_encode(['ok' => true, 'quantity' => 0], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if ($currentQty === 1) {
            $stmt = $pdo->prepare('DELETE FROM cart WHERE user_id = ? AND product_id = ?');
            $stmt->execute([$userId, $productId]);
            $newQty = 0;
        } else {
            $stmt = $pdo->prepare('UPDATE cart SET quantity = quantity - 1 WHERE user_id = ? AND product_id = ?');
            $stmt->execute([$userId, $productId]);
            $newQty = $currentQty - 1;
        }

        $stmt = $pdo->prepare('UPDATE menu SET quantity = quantity + 1 WHERE product_id = ?');
        $stmt->execute([$productId]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'quantity' => $newQty], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

