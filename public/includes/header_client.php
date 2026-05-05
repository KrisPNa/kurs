<?php
/**
 * Та же разметка хедера, что в admin_orders.php, но с клиентскими ссылками.
 * Требуется: session_start() до подключения.
 */
?>
<header class="client-header">
    <div class="logo">
        <a href="index.php"><img src="img/logo.png" alt="логотип" width="140" height="140" /></a>
    </div>
    <div class="sidebar">
        <a class="sidebar-1" href="about.php">О нас</a>
        <a class="sidebar-1" href="menu.php">Меню</a>
        <a class="sidebar-1" href="cart.php">Корзина</a>
        <a class="sidebar-1" href="cont.php">Контакты</a>
        <a class="sidebar-1" href="order.php">Заказы</a>
    </div>
    <div class="nav">
        <?php if (isset($_SESSION['user'])): ?>
            <a href="account.php">Личный кабинет</a>
        <?php else: ?>
            <a href="register.php">Авторизация</a>
        <?php endif; ?>
    </div>
</header>
