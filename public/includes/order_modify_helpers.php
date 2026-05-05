<?php
/**
 * Правила изменения заказа: не позднее чем за 3 часа до доставки.
 * Время доставки с клиента не меняется (только через БД при необходимости).
 */

/**
 * @param array{delivery_date: string, delivery_time: string} $orderRow
 */
function order_delivery_datetime(array $orderRow): DateTimeImmutable {
    return new DateTimeImmutable($orderRow['delivery_date'] . ' ' . $orderRow['delivery_time']);
}

/**
 * Можно ли ещё менять/отменять заказ (строго больше 3 часов до момента доставки).
 */
function order_can_modify_before_delivery(array $orderRow, ?DateTimeInterface $now = null): bool {
    $now = $now ?? new DateTimeImmutable('now');
    $delivery = order_delivery_datetime($orderRow);
    if ($delivery <= $now) {
        return false;
    }
    $secondsLeft = $delivery->getTimestamp() - $now->getTimestamp();
    return $secondsLeft > 3 * 3600;
}

/**
 * Списание/возврат ингредиентов при изменении количества товара в заказе.
 * $qtyDelta > 0 — продали больше единиц; < 0 — вернули на склад.
 *
 * @throws Exception при нехватке ингредиентов (только при qtyDelta > 0)
 */
function order_ingredients_apply_product_qty_delta(PDO $pdo, int $productId, int $qtyDelta): void {
    if ($qtyDelta === 0) {
        return;
    }
    $stmt = $pdo->prepare('SELECT ingredient_id, qty_per_product FROM product_ingredients WHERE product_id = ?');
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return;
    }

    foreach ($rows as $r) {
        $need = (float)$r['qty_per_product'] * $qtyDelta;
        $ingId = (int)$r['ingredient_id'];
        if ($need > 0.0000001) {
            $st = $pdo->prepare('SELECT stock_qty FROM ingredients WHERE ingredient_id = ? FOR UPDATE');
            $st->execute([$ingId]);
            $stock = (float)$st->fetchColumn();
            if ($stock + 0.0000001 < $need) {
                $nm = $pdo->prepare('SELECT name, unit FROM ingredients WHERE ingredient_id = ?');
                $nm->execute([$ingId]);
                $info = $nm->fetch(PDO::FETCH_ASSOC);
                $label = $info ? $info['name'] : ('#' . $ingId);
                throw new Exception('Недостаточно ингредиента на складе: ' . $label);
            }
        }
    }

    foreach ($rows as $r) {
        $need = (float)$r['qty_per_product'] * $qtyDelta;
        $upd = $pdo->prepare('UPDATE ingredients SET stock_qty = stock_qty - ? WHERE ingredient_id = ?');
        $upd->execute([$need, (int)$r['ingredient_id']]);
    }
}
