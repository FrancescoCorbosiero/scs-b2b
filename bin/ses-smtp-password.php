#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Converte un AWS Secret Access Key nella password SMTP di Amazon SES
 * (algoritmo ufficiale AWS, derivazione stile SigV4 legata alla regione).
 *
 * Lo username SMTP è l'Access Key ID così com'è; serve un utente IAM con
 * permesso ses:SendRawEmail.
 *
 * Uso: php bin/ses-smtp-password.php <regione> <secret-access-key>
 * Es:  php bin/ses-smtp-password.php eu-south-1 "wJalrXUtnFEMI/K7MDENG/..."
 */

if ($argc < 3 || $argv[1] === '' || $argv[2] === '') {
    fwrite(STDERR, "Uso: php bin/ses-smtp-password.php <regione> <secret-access-key>\n");
    exit(1);
}

[$region, $secret] = [$argv[1], $argv[2]];

$signature = hash_hmac('sha256', '11111111', 'AWS4' . $secret, true);
foreach ([$region, 'ses', 'aws4_request', 'SendRawEmail'] as $step) {
    $signature = hash_hmac('sha256', $step, $signature, true);
}

echo base64_encode(chr(4) . $signature), PHP_EOL;
