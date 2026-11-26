<?php
declare(strict_types=1);

/** SMTP 配置（密码加密存储） */
function smtp_settings(): array {
    return [
        'enabled'   => setting('notify_enabled') === '1',
        'host'      => trim(setting('smtp_host')),
        'port'      => (int)(setting('smtp_port', '465') ?: 465),
        'user'      => trim(setting('smtp_user')),
        'pass'      => secret_decrypt(setting('smtp_pass')),
        'from'      => trim(setting('smtp_from') ?: setting('smtp_user')),
        'from_name' => setting('smtp_from_name', cfg('site_name', '云商城')),
        'secure'    => in_array(setting('smtp_secure', 'ssl'), ['ssl', 'tls', 'none'], true) ? setting('smtp_secure', 'ssl') : 'ssl',
    ];
}
function smtp_configured(): bool {
    $c = smtp_settings();
    return $c['host'] !== '' && $c['from'] !== '';
}

/**
 * 发送 HTML 邮件（内置极简 SMTP 客户端，支持 SSL / STARTTLS / AUTH LOGIN）
 * @return array{ok:bool,msg:string}
 */
function smtp_send(string $to, string $subject, string $html): array {
    $c = smtp_settings();
    if ($c['host'] === '' || $c['from'] === '') return ['ok' => false, 'msg' => '未配置 SMTP 服务器'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'msg' => '收件邮箱无效'];

    $remote = ($c['secure'] === 'ssl' ? 'ssl://' : '') . $c['host'] . ':' . $c['port'];
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) return ['ok' => false, 'msg' => "连接失败：{$errstr} ({$errno})"];
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $code = (int)substr(trim($out), 0, 3);
        return [$code, trim($out)];
    };
    $cmd = function (string $line) use ($fp, $read): array {
        fwrite($fp, $line . "\r\n");
        return $read();
    };
    $fail = function (string $m) use ($fp): array { @fclose($fp); return ['ok' => false, 'msg' => $m]; };

    [$code, $msg] = $read();
    if ($code !== 220) return $fail("服务器未就绪：{$msg}");

    $host = gethostname() ?: 'localhost';
    [$code, $msg] = $cmd('EHLO ' . $host);
    if ($code !== 250) return $fail("EHLO 失败：{$msg}");

    if ($c['secure'] === 'tls') {
        [$code, $msg] = $cmd('STARTTLS');
        if ($code !== 220) return $fail("STARTTLS 失败：{$msg}");
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $fail('TLS 握手失败');
        [$code, $msg] = $cmd('EHLO ' . $host);
        if ($code !== 250) return $fail("EHLO(TLS) 失败：{$msg}");
    }

    if ($c['user'] !== '') {
        [$code, $msg] = $cmd('AUTH LOGIN');
        if ($code !== 334) return $fail("AUTH 失败：{$msg}");
        [$code, $msg] = $cmd(base64_encode($c['user']));
        if ($code !== 334) return $fail("用户名被拒绝：{$msg}");
        [$code, $msg] = $cmd(base64_encode($c['pass']));
        if ($code !== 235) return $fail("密码被拒绝：{$msg}");
    }

    [$code, $msg] = $cmd('MAIL FROM:<' . $c['from'] . '>');
    if ($code !== 250) return $fail("MAIL FROM 失败：{$msg}");
    [$code, $msg] = $cmd('RCPT TO:<' . $to . '>');
    if (!in_array($code, [250, 251], true)) return $fail("收件人被拒绝：{$msg}");
    [$code, $msg] = $cmd('DATA');
    if ($code !== 354) return $fail("DATA 失败：{$msg}");

    $enc = function (string $s): string { return '=?UTF-8?B?' . base64_encode($s) . '?='; };
    $body = 'From: ' . $enc($c['from_name']) . ' <' . $c['from'] . ">\r\n"
          . 'To: <' . $to . ">\r\n"
          . 'Subject: ' . $enc($subject) . "\r\n"
          . 'Date: ' . date('r') . "\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: base64\r\n"
          . "\r\n"
          . chunk_split(base64_encode($html), 76, "\r\n") . "\r\n.\r\n";

    fwrite($fp, $body);
    [$code, $msg] = $read();
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    if ($code !== 250) return ['ok' => false, 'msg' => "发送失败：{$msg}"];
    return ['ok' => true, 'msg' => '已发送'];
}

/** 渲染提醒邮件模板 */
function notify_mail_html(array $d): string {
    $site = e(cfg('site_name', '云商城'));
    $accent = '#2f6b45';
    $rows = '';
    foreach ($d['rows'] as $k => $v) {
        $rows .= '<tr><td style="padding:8px 0;color:#6b7280;font-size:13px">' . e($k) . '</td>'
               . '<td style="padding:8px 0;color:#111827;font-size:13px;text-align:right;font-weight:600">' . e($v) . '</td></tr>';
    }
    $btn = $d['url'] !== '' ? '<a href="' . e($d['url']) . '" style="display:inline-block;margin-top:18px;padding:12px 26px;background:' . $accent . ';color:#fff;border-radius:999px;text-decoration:none;font-size:14px">查看我的订阅</a>' : '';
    return '<div style="background:#f3f5f4;padding:28px 12px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif">'
        . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;padding:30px 26px">'
        . '<div style="font-size:13px;color:' . $accent . ';font-weight:700;letter-spacing:1px">' . $site . '</div>'
        . '<h2 style="margin:10px 0 6px;font-size:20px;color:#111827">' . e($d['title']) . '</h2>'
        . '<p style="margin:0 0 16px;color:#4b5563;font-size:14px;line-height:1.6">' . $d['intro'] . '</p>'
        . '<table style="width:100%;border-collapse:collapse;border-top:1px solid #e5e7eb">' . $rows . '</table>'
        . $btn
        . '<p style="margin:22px 0 0;color:#9ca3af;font-size:12px;line-height:1.6">' . e($d['foot'] ?: '如已续费请忽略本邮件。') . '</p>'
        . '</div></div>';
}
