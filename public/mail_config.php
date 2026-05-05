<?php
/**
 * Настройки почты для mailer.php
 *
 * Gmail: host ДОЛЖЕН быть smtp.gmail.com (не smtp.example.com).
 * Обычный пароль от аккаунта НЕ подходит — нужен «Пароль приложения»:
 * Аккаунт Google → Безопасность → Двухэтапная аутентификация → Пароли приложений.
 *
 * Временная отладка: 'smtp_debug' => true — строки SMTP пишутся в error_log PHP.
 */
return [
    'from_email' => 'cristina.2004.petrukhina@gmail.com',
    'from_name'  => 'Кондитерская Kriter',

    'use_phpmailer' => true,

    /** Подробный лог SMTP в error_log (потом выключите) */
    'smtp_debug' => false,

    /**
     * На локальном OSPanel/Windows иногда падает проверка SSL к smtp.gmail.com.
     * true — ослабить проверку сертификата (только для разработки).
     */
    'smtp_ssl_relaxed' => true,

    'smtp' => [
        'host'     => 'smtp.gmail.com',
        'port'     => 587,
        'username' => 'cristina.2004.petrukhina@gmail.com',
        // Вставьте пароль приложения Google (16 символов), не обычный пароль аккаунта
        'password' => 'jtxf dftu pmds awsl',
        'secure'   => 'tls',
    ],
];
