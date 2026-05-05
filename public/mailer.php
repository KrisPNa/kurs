<?php
/**
 * Отправка писем: PHPMailer (SMTP), если доступен, иначе PHP mail().
 */

function mailer_load_config(): array {
    $path = __DIR__ . '/mail_config.php';
    if (!is_file($path)) {
        return [
            'from_email' => 'noreply@localhost',
            'from_name' => 'Kriter',
            'use_phpmailer' => false,
            'smtp' => [],
        ];
    }
    $cfg = require $path;
    return is_array($cfg) ? $cfg : [];
}

/**
 * Вложения: [['filename' => 'chek.txt', 'content' => string, 'mime' => 'text/plain; charset=UTF-8']]
 *
 * @param array<int, array{filename: string, content: string, mime?: string}> $attachments
 */
function send_email(string $to, string $subject, string $bodyHtml, array $attachments = []): bool {
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $cfg = mailer_load_config();
    $fromEmail = $cfg['from_email'] ?? 'noreply@localhost';
    $fromName = $cfg['from_name'] ?? 'Kriter';

    $phpmailerPath = __DIR__ . '/PHPMailer/src/PHPMailer.php';
    if (!empty($cfg['use_phpmailer']) && is_file($phpmailerPath)) {
        return send_email_phpmailer($to, $subject, $bodyHtml, $cfg, $attachments);
    }

    if ($attachments !== []) {
        error_log('send_email: вложения поддерживаются только через PHPMailer (use_phpmailer=true). Вложения не отправлены.');
    }

    return send_email_mail_function($to, $subject, $bodyHtml, $fromEmail, $fromName);
}

function send_email_mail_function(string $to, string $subject, string $bodyHtml, string $fromEmail, string $fromName): bool {
    $fromNameEncoded = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'From: ' . $fromNameEncoded . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;

    $headerStr = implode("\r\n", $headers);

    $ok = @mail($to, $subjectEncoded, $bodyHtml, $headerStr);
    if (!$ok) {
        error_log('send_email: mail() failed for ' . $to);
    }
    return $ok;
}

function send_email_phpmailer(string $to, string $subject, string $bodyHtml, array $cfg, array $attachments = []): bool {
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';

    $smtp = $cfg['smtp'] ?? [];
    $fromEmail = $cfg['from_email'] ?? '';
    $fromName = $cfg['from_name'] ?? '';

    $mail = null;
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp['host'] ?? 'localhost';
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'] ?? '';
        $mail->Password = $smtp['password'] ?? '';
        $secure = strtolower((string)($smtp['secure'] ?? 'tls'));
        if ($secure === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($secure === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->Port = (int)($smtp['port'] ?? 587);
        $mail->Timeout = 30;

        if (!empty($cfg['smtp_debug'])) {
            $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function (string $str, int $level): void {
                error_log('[SMTP] ' . trim($str));
            };
        }

        if (!empty($cfg['smtp_ssl_relaxed'])) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $bodyHtml;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml)));

        foreach ($attachments as $att) {
            $fname = $att['filename'] ?? 'attachment.txt';
            $content = $att['content'] ?? '';
            $mime = $att['mime'] ?? 'application/octet-stream';
            $mail->addStringAttachment(
                $content,
                $fname,
                \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
                $mime
            );
        }

        $ok = $mail->send();
        if (!$ok) {
            error_log('PHPMailer send=false ErrorInfo: ' . $mail->ErrorInfo);
        }
        return $ok;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('PHPMailer Exception: ' . $e->getMessage());
        if ($mail !== null) {
            error_log('PHPMailer ErrorInfo: ' . $mail->ErrorInfo);
        }
        return false;
    } catch (\Throwable $e) {
        error_log('PHPMailer Throwable: ' . $e->getMessage());
        if ($mail !== null) {
            error_log('PHPMailer ErrorInfo: ' . $mail->ErrorInfo);
        }
        return false;
    }
}

/**
 * HTML-чек заказа для письма.
 *
 * @param array<int, array{product_id:int|string,name:string,price:float|string,quantity:int|string}> $items
 */
function build_order_receipt_html(
    int $orderId,
    string $customerName,
    string $email,
    string $street,
    string $house,
    string $apartment,
    string $deliveryDate,
    string $deliveryTime,
    array $items,
    float $total
): string {
    $rows = '';
    foreach ($items as $row) {
        $name = htmlspecialchars((string)$row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $qty = (int)$row['quantity'];
        $price = number_format((float)$row['price'], 2, ',', ' ');
        $line = number_format((float)$row['price'] * $qty, 2, ',', ' ');
        $rows .= '<tr><td>' . $name . '</td><td style="text-align:center">' . $qty . '</td><td style="text-align:right">' . $price . '</td><td style="text-align:right">' . $line . '</td></tr>';
    }

    $totalStr = number_format($total, 2, ',', ' ');

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;line-height:1.5">'
        . '<h2>Чек по заказу №' . (int)$orderId . '</h2>'
        . '<p><strong>Покупатель:</strong> ' . htmlspecialchars($customerName, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>'
        . '<strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
        . '<p><strong>Адрес:</strong> ' . htmlspecialchars($street, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ', д. ' . htmlspecialchars($house, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        . ', кв. ' . htmlspecialchars($apartment, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
        . '<p><strong>Дата доставки:</strong> ' . htmlspecialchars($deliveryDate, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        . '<br><strong>Время:</strong> ' . htmlspecialchars($deliveryTime, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
        . '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:560px">'
        . '<thead><tr><th>Товар</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '<tfoot><tr><td colspan="3" style="text-align:right"><strong>Итого</strong></td><td style="text-align:right"><strong>' . $totalStr . ' руб.</strong></td></tr></tfoot>'
        . '</table>'
        . '<p style="color:#666;font-size:14px">Оплата при получении. Спасибо за заказ!</p>'
        . '</body></html>';
}

/**
 * Текстовый чек для вложения (.txt), UTF-8.
 *
 * @param array<int, array{product_id:int|string,name:string,price:float|string,quantity:int|string}> $items
 */
function build_order_receipt_text(
    int $orderId,
    string $customerName,
    string $email,
    string $street,
    string $house,
    string $apartment,
    string $deliveryDate,
    string $deliveryTime,
    array $items,
    float $total
): string {
    $lines = [];
    $lines[] = 'Кондитерская Kriter — чек по заказу №' . $orderId;
    $lines[] = str_repeat('=', 48);
    $lines[] = 'Покупатель: ' . $customerName;
    $lines[] = 'Email: ' . $email;
    $lines[] = 'Адрес: ' . $street . ', д. ' . $house . ', кв. ' . $apartment;
    $lines[] = 'Дата доставки: ' . $deliveryDate;
    $lines[] = 'Время: ' . $deliveryTime;
    $lines[] = '';
    $lines[] = 'Товар                    Кол-во  Цена     Сумма';
    $lines[] = str_repeat('-', 48);

    foreach ($items as $row) {
        $name = (string)$row['name'];
        $qty = (int)$row['quantity'];
        $price = (float)$row['price'];
        $lineSum = $price * $qty;
        $lines[] = sprintf(
            "%s\n  %d шт. × %s руб. = %s руб.",
            $name,
            $qty,
            number_format($price, 2, ',', ' '),
            number_format($lineSum, 2, ',', ' ')
        );
    }

    $lines[] = str_repeat('-', 48);
    $lines[] = 'ИТОГО: ' . number_format($total, 2, ',', ' ') . ' руб.';
    $lines[] = '';
    $lines[] = 'Оплата при получении.';
    $lines[] = 'Спасибо за заказ!';

    $text = implode("\n", $lines);
    return "\xEF\xBB\xBF" . $text;
}
/**
 * Подключает TCPDF: composer `vendor/tecnickcom/tcpdf` или `public/tcpdf/tcpdf.php`.
 */
function mailer_require_tcpdf(): bool {
    if (class_exists('TCPDF', false)) {
        return true;
    }
    $public = __DIR__;
    $root = dirname($public);
    $paths = [
        $root . '/vendor/tecnickcom/tcpdf/tcpdf.php',
        $public . '/tcpdf/tcpdf.php',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            return class_exists('TCPDF', false);
        }
    }
    return false;
}

/**
 * PDF-чек (бинарная строка) или null, если TCPDF не установлен / ошибка.
 * Установка: в корне проекта выполните `composer install` (есть composer.json)
 * или распакуйте TCPDF в папку `public/tcpdf/` (файл `tcpdf.php`).
 *
 * @param array<int, array{product_id:int|string,name:string,price:float|string,quantity:int|string}> $items
 */
function build_order_receipt_pdf(
    int $orderId,
    string $customerName,
    string $email,
    string $street,
    string $house,
    string $apartment,
    string $deliveryDate,
    string $deliveryTime,
    array $items,
    float $total
): ?string {
    if (!mailer_require_tcpdf()) {
        error_log('build_order_receipt_pdf: TCPDF не найден (composer install или public/tcpdf/).');
        return null;
    }

    try {
        $html = build_order_receipt_html(
            $orderId,
            $customerName,
            $email,
            $street,
            $house,
            $apartment,
            $deliveryDate,
            $deliveryTime,
            $items,
            $total
        );

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Kriter');
        $pdf->SetAuthor('Kriter');
        $pdf->SetTitle('Чек заказа №' . $orderId);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->writeHTML($html, true, false, true, false, '');
        $bin = $pdf->Output('', 'S');
        return is_string($bin) && $bin !== '' ? $bin : null;
    } catch (\Throwable $e) {
        error_log('build_order_receipt_pdf: ' . $e->getMessage());
        return null;
    }
}

