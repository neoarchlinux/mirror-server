<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function ensureDir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        jsonError("Failed to create directory: $dir", 500);
    }
}

function move(string $from, string $to): void {
    if (!rename($from, $to)) {
        exec(sprintf('mv %s %s', escapeshellarg($from), escapeshellarg($to)), $mvOutput, $mvCode);
        if ($mvCode !== 0) jsonError("Failed to move $from to $to", 500);
    }
}

function getIpAddress(): string {
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    foreach ($keys as $key) {
        if (!isset($_SERVER[$key])) continue;

        $value = $_SERVER[$key];

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $value);
            $value = trim($parts[0]);
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return "0.0.0.0";
}
