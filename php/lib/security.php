<?php
declare(strict_types=1);

function computeHmac(array $postFields, string $fileContents, string $secret): string {
    return hash_hmac('sha512', $fileContents, $secret);
}

function assertHmac(array $postFields, string $fileContents, string $clientHmac, string $secret): void {
    $serverHmac = computeHmac($postFields, $fileContents, $secret);

    if (!hash_equals($serverHmac, $clientHmac)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'HMAC verification failed'
        ]);
        exit;
    }
}
