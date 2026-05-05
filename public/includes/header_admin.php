<?php
/**
 * Хедер админки (как в admin_orders.php).
 * Требуется: session_start() до подключения.
 * ььььььььььььььььььььььььььььььььььььььььь
 */
?>
<header class="admin-header">
    <div class="logo">
        <a href="index_admin.php"><img src="img/logo.png" alt="логотип" width="140" height="140" /></a>
    </div>
    <div class="sidebar">
        <a class="sidebar-1" href="admin_dashboard.php">Админ Меню</a>
        <a class="sidebar-1" href="menu_admin.php">Меню Клиента</a>
        <a class="sidebar-1" href="ingredients_admin.php">Ингредиенты (Склад)</a>
        <a class="sidebar-1" href="manage_users.php">Управление Пользователями</a>
        <a class="sidebar-1" href="admin_orders.php">Управление Заказами</a>
    </div>
    <div class="nav">
        <?php if (isset($_SESSION['admin_user'])): ?>
            <a href="admin_account.php">Личный кабинет</a>
        <?php else: ?>
            <a href="register.php">Авторизация</a>
        <?php endif; ?>
    </div>
</header>
