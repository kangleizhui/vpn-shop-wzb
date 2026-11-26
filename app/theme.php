<?php
declare(strict_types=1);

/**
 * 外观主题：主题色 + 玻璃透明度 + 自定义背景图
 * 通过内联 CSS 变量覆盖 style.css 里的默认值
 */

const THEME_PRESETS = [
    'forest' => ['森林绿', '#2f6b45', '#7fbf95'],
    'ocean'  => ['海洋蓝', '#1f5f7d', '#7fc4e6'],
    'violet' => ['深空紫', '#4a3f8f', '#b3a4f5'],
    'rose'   => ['玫瑰',   '#8a3f52', '#e8a2b4'],
    'amber'  => ['琥珀',   '#7a5a1e', '#e6c07f'],
    'red'    => ['中国红', '#8c2f2f', '#e89a92'],
    'cyan'   => ['青瓷',   '#1f6f6a', '#8fd6cf'],
    'mono'   => ['石墨',   '#3b4550', '#a9b8c6'],
];

function th_rgb(string $hex): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return [47, 107, 69];
    return [(int)hexdec(substr($hex, 0, 2)), (int)hexdec(substr($hex, 2, 2)), (int)hexdec(substr($hex, 4, 2))];
}

function th_hex(int $r, int $g, int $b): string
{
    return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

/** $t=0 返回 $a，$t=1 返回 $b */
function th_mix(string $a, string $b, float $t): string
{
    [$r1, $g1, $b1] = th_rgb($a);
    [$r2, $g2, $b2] = th_rgb($b);
    $t = max(0.0, min(1.0, $t));
    return th_hex(
        (int)round($r1 + ($r2 - $r1) * $t),
        (int)round($g1 + ($g2 - $g1) * $t),
        (int)round($b1 + ($b2 - $b1) * $t)
    );
}

function th_lighten(string $hex, float $t): string
{
    return th_mix($hex, '#ffffff', $t);
}

function th_rgba(string $hex, float $alpha): string
{
    [$r, $g, $b] = th_rgb($hex);
    return sprintf('rgba(%d,%d,%d,%.3f)', $r, $g, $b, max(0, min(1, $alpha)));
}

function th_valid_hex(string $hex): bool
{
    return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', trim($hex));
}

/** 只允许 /uploads/ 下的图片，避免任意路径或外链 */
function th_valid_bg(string $path): bool
{
    return (bool)preg_match('#^/uploads/bg_(pc|mobile)_\d{14}\.(jpg|png|webp)$#', $path);
}

/** 当前主题参数 */
function theme_params(): array
{
    $p = trim((string)setting('theme_primary', '#2f6b45'));
    if (!th_valid_hex($p)) $p = '#2f6b45';
    $a = trim((string)setting('theme_accent', '#7fbf95'));
    if (!th_valid_hex($a)) $a = '#7fbf95';
    $alpha = (int)setting('glass_alpha', '55');
    $alpha = max(20, min(95, $alpha)) / 100;
    return ['primary' => $p, 'accent' => $a, 'alpha' => $alpha];
}

/** 生成内联主题 CSS */
function theme_css(): string
{
    $t = theme_params();
    $cardBase = th_mix($t['primary'], '#0b1410', 0.80);
    $hover    = min(1.0, $t['alpha'] + 0.08);

    $vars = [
        '--primary'      => $t['primary'],
        '--primary-hover'=> th_lighten($t['primary'], 0.10),
        '--accent'       => $t['accent'],
        '--accent-soft'  => th_rgba($t['accent'], 0.18),
        '--accent-06'    => th_rgba($t['accent'], 0.06),
        '--accent-12'    => th_rgba($t['accent'], 0.12),
        '--accent-16'    => th_rgba($t['accent'], 0.16),
        '--accent-20'    => th_rgba($t['accent'], 0.20),
        '--accent-25'    => th_rgba($t['accent'], 0.25),
        '--accent-38'    => th_rgba($t['accent'], 0.38),
        '--accent-42'    => th_rgba($t['accent'], 0.42),
        '--accent-45'    => th_rgba($t['accent'], 0.45),
        '--accent-55'    => th_rgba($t['accent'], 0.55),
        '--accent-ring'  => th_rgba($t['accent'], 0.22),
        '--card'         => th_rgba($cardBase, $t['alpha']),
        '--card-hover'   => th_rgba($cardBase, $hover),
        '--card-border'  => th_rgba($t['accent'], 0.18),
        '--field-border' => th_rgba($t['accent'], 0.24),
        '--line'         => th_rgba($t['accent'], 0.14),
    ];

    $css = ':root{';
    foreach ($vars as $k => $v) $css .= $k . ':' . $v . ';';
    $css .= '}';

    $pc  = (string)setting('bg_pc', '');
    $mob = (string)setting('bg_mobile', '');
    if ($pc !== '' && th_valid_bg($pc)) {
        $css .= 'body::after{background-image:url("' . $pc . '")}';
    }
    if ($mob !== '' && th_valid_bg($mob)) {
        $css .= '@media(max-width:600px){body::after{background-image:url("' . $mob . '")}}';
    }

    return '<style id="theme-css">' . $css . '</style>';
}
