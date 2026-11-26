<?php
declare(strict_types=1);

/** 抓取单个服务器的订阅 */
function fetch_sub(string $url, string $ua, int $timeout = 20): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => ['Accept: */*'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return '';
    return (string)$body;
}

function looks_base64(string $s): bool
{
    $s = trim($s);
    if ($s === '' || str_contains($s, '://')) return false;
    return (bool)preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $s);
}

/** 各服务器 → 合并为一个 v2ray 订阅（base64） */
function merge_v2ray(array $items): string
{
    $lines = [];
    foreach ($items as $it) {
        $raw = fetch_sub($it['sub_url'], 'v2rayN/6.0');
        if ($raw === '') continue;
        $text = looks_base64($raw) ? (string)base64_decode(preg_replace('/\s+/', '', $raw)) : $raw;
        foreach (preg_split('/\r?\n/', $text) as $l) {
            $l = trim($l);
            if ($l !== '' && str_contains($l, '://')) $lines[$l] = true;
        }
    }
    return base64_encode(implode("\n", array_keys($lines)));
}

/** 各服务器 → 合并为一个 sing-box 配置 */
function merge_singbox(array $items): string
{
    $base = null;
    $proxyTags = [];
    $extra = [];
    foreach ($items as $it) {
        $raw = fetch_sub($it['sub_url'], 'SFA/1.13.1');
        $d = json_decode($raw, true);
        if (!is_array($d) || empty($d['outbounds'])) continue;
        if ($base === null) {
            $base = $d;
            foreach ($base['outbounds'] as $o) {
                if (!empty($o['server'])) $proxyTags[] = $o['tag'];
            }
            continue;
        }
        foreach ($d['outbounds'] as $o) {
            if (empty($o['server'])) continue;
            $tag = $o['tag'];
            if (in_array($tag, $proxyTags, true)) {
                $tag = $tag . ' [' . ($it['region'] ?: ('S' . $it['server_id'])) . ']';
                $o['tag'] = $tag;
            }
            $proxyTags[] = $tag;
            $extra[] = $o;
        }
    }
    if ($base === null) return json_encode(['outbounds' => []], JSON_UNESCAPED_UNICODE);
    $base['outbounds'] = array_merge($base['outbounds'], $extra);
    foreach ($base['outbounds'] as &$o) {
        if (in_array($o['type'] ?? '', ['selector', 'urltest'], true)) {
            $o['outbounds'] = array_values(array_unique($proxyTags));
        }
    }
    unset($o);
    return json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** 各服务器 → 合并为一个 Clash 配置 */
function merge_clash(array $items): string
{
    $all = [];
    $dnsBlock = '';
    foreach ($items as $it) {
        $y = fetch_sub($it['sub_url'], 'clash-verge/2.0');
        if ($y === '') continue;
        $y = str_replace("\r\n", "\n", $y);
        if ($dnsBlock === '' && preg_match('/^dns:\s*\n(?:[ \t]+.*\n)*/m', $y, $m)) {
            $dnsBlock = rtrim($m[0]);
        }
        if (!preg_match('/^proxies:\s*\n(.*?)(?=^[A-Za-z][\w-]*:)/ms', $y, $m)) continue;
        $block = $m[1];
        $cur = null; $indent = null;
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^(\s*)-\s/', $line, $mm)) {
                $ind = strlen($mm[1]);
                if ($indent === null) $indent = $ind;
                if ($ind <= $indent) {
                    if ($cur !== null) $all[] = $cur;
                    $cur = ltrim($line);
                    continue;
                }
            }
            if ($cur !== null && trim($line) !== '') $cur .= "\n" . $line;
        }
        if ($cur !== null) $all[] = $cur;
    }

    $seen = [];
    $out = [];
    $names = [];
    foreach ($all as $p) {
        $name = '';
        if (preg_match('/^-\s*name:\s*["\']?(.*?)["\']?\s*$/m', $p, $mm)) $name = trim($mm[1]);
        if ($name !== '') {
            $orig = $name; $i = 2;
            while (isset($seen[$name])) { $name = $orig . ' #' . $i; $i++; }
            $seen[$name] = true;
            $p = preg_replace('/^-\s*name:.*$/m', "- name: '" . str_replace("'", "''", $name) . "'", $p, 1);
            $names[] = $name;
        }
        $out[] = $p;
    }

    $yaml = "mixed-port: 7890\nallow-lan: false\nmode: rule\nlog-level: info\nipv6: true\n";
    if ($dnsBlock !== '') $yaml .= $dnsBlock . "\n";
    $yaml .= "proxies:\n" . implode("\n", $out) . "\n";
    $yaml .= "proxy-groups:\n";
    foreach ([['PROXY', 'select'], ['AUTO', 'url-test']] as $g) {
        $yaml .= "  - name: " . $g[0] . "\n    type: " . $g[1] . "\n";
        if ($g[1] === 'url-test') $yaml .= "    url: http://www.gstatic.com/generate_204\n    interval: 300\n";
        $yaml .= "    proxies:\n";
        foreach ($names as $n) $yaml .= "      - '" . str_replace("'", "''", $n) . "'\n";
    }
    $yaml .= "rules:\n  - GEOIP,CN,DIRECT\n  - MATCH,PROXY\n";
    return $yaml;
}

/** 根据客户端 UA 选择输出格式 */
function merged_subscription(array $items, string $ua): array
{
    $u = strtolower($ua);
    if (str_contains($u, 'clash') || str_contains($u, 'mihomo') || str_contains($u, 'stash')) {
        return ['type' => 'yaml', 'body' => merge_clash($items)];
    }
    if (str_contains($u, 'sing-box') || str_contains($u, 'sfa') || str_contains($u, 'hiddify')) {
        // Hiddify 同时支持 base64 与 json，用 json 保留分组
        $body = merge_singbox($items);
        if (strlen($body) > 200) return ['type' => 'json', 'body' => $body];
    }
    return ['type' => 'base64', 'body' => merge_v2ray($items)];
}
