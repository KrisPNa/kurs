<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: login_admin.php');
    exit();
}

$db = getDbConnection();
$errorMessage = '';

const ALLOWED_UNITS = ['кг', 'г', 'л', 'мл', 'шт'];

function normalizeUnit(string $unit): string {
    $unit = trim($unit);
    if ($unit === '') return 'кг';
    $unit = mb_substr($unit, 0, 32, 'UTF-8');
    return in_array($unit, ALLOWED_UNITS, true) ? $unit : 'кг';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_ingredient'])) {
            $name = trim($_POST['name'] ?? '');
            $unit = normalizeUnit($_POST['unit'] ?? 'кг');
            $stockQty = (float)($_POST['stock_qty'] ?? 0);

            if ($name === '') {
                $errorMessage = 'Название ингредиента обязательно.';
            } else {
                $stmt = $db->prepare("INSERT INTO ingredients (name, unit, stock_qty) VALUES (?, ?, ?)");
                $stmt->execute([$name, $unit, $stockQty]);
                header('Location: ingredients_admin.php?success=1');
                exit();
            }
        }

        if (isset($_POST['update_ingredient'])) {
            $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $unit = normalizeUnit($_POST['unit'] ?? 'кг');
            $stockQty = (float)($_POST['stock_qty'] ?? 0);

            if ($ingredientId <= 0) {
                $errorMessage = 'Некорректный ингредиент.';
            } elseif ($name === '') {
                $errorMessage = 'Название ингредиента обязательно.';
            } else {
                $stmt = $db->prepare("UPDATE ingredients SET name = ?, unit = ?, stock_qty = ? WHERE ingredient_id = ?");
                $stmt->execute([$name, $unit, $stockQty, $ingredientId]);
                header('Location: ingredients_admin.php?success=1');
                exit();
            }
        }

        if (isset($_POST['delete_ingredient'])) {
            $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
            if ($ingredientId > 0) {
                $stmt = $db->prepare("DELETE FROM ingredients WHERE ingredient_id = ?");
                $stmt->execute([$ingredientId]);
                header('Location: ingredients_admin.php?success=1');
                exit();
            }
        }
    } catch (PDOException $e) {
        $errorMessage = "Ошибка БД: " . $e->getMessage();
    }
}

$stmt = $db->query("SELECT ingredient_id, name, unit, stock_qty FROM ingredients ORDER BY name ASC");
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ингредиенты (Склад)</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .grid { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 10px; align-items: end; }
        .field input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; }
        .actions button { padding: 8px 12px; border: none; border-radius: 6px; cursor: pointer; }
        .btn-add { background-color: #28a745; color: #fff; }
        .btn-del { background-color: rgba(244,67,54,.10); color: #7a1f1a; border: 1px solid rgba(244,67,54,.25); }
        .btn-save { background-color: rgba(224,202,184,.35); border: 1px solid rgba(48,33,38,.15); }
        .msg { margin: 12px 0; }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/header_admin.php'; ?>

<div class="content">
    <h2>Ингредиенты (Склад)</h2>

    <?php if (isset($_GET['success'])): ?>
        <div class="msg" style="color: green;">Готово.</div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="msg" style="color: red;"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <form method="post" class="grid" style="margin-bottom: 12px;">
        <div class="field">
            <label>Название</label>
            <input type="text" name="name" placeholder="Напр.: Мука" required>
        </div>
        <div class="field">
            <label>Ед.</label>
            <select name="unit" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                <?php foreach (ALLOWED_UNITS as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= $u === 'кг' ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Остаток</label>
            <input type="number" step="0.001" name="stock_qty" value="0">
        </div>
        <div class="actions">
            <button type="submit" name="add_ingredient" class="btn-add">Добавить</button>
        </div>
    </form>

    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Название</th>
            <th>Ед.</th>
            <th>Остаток</th>
            <th>Действия</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($ingredients as $ing): ?>
            <tr>
                <form method="post">
                    <td>
                        <?= (int)$ing['ingredient_id'] ?>
                        <input type="hidden" name="ingredient_id" value="<?= (int)$ing['ingredient_id'] ?>">
                    </td>
                    <td><input type="text" name="name" value="<?= htmlspecialchars($ing['name']) ?>" required></td>
                    <td>
                        <select name="unit" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                            <?php foreach (ALLOWED_UNITS as $u): ?>
                                <option value="<?= htmlspecialchars($u) ?>" <?= $u === ($ing['unit'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" step="0.001" name="stock_qty" value="<?= htmlspecialchars($ing['stock_qty']) ?>"></td>
                    <td style="white-space: nowrap;">
                        <button type="submit" name="update_ingredient" class="btn-save">Сохранить</button>
                        <button type="submit" name="delete_ingredient" class="btn-del" onclick="return confirm('Удалить ингредиент?');">Удалить</button>
                    </td>
                </form>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>

