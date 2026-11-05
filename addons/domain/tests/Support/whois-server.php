<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
if ($server === false) {
    throw new RuntimeException($message, $code);
}
$address = stream_socket_get_name($server, false);
if ($address === false) {
    throw new RuntimeException('Cannot determine fixture address.');
}
echo $address."\n";
flush();
$client = stream_socket_accept($server, 15);
if ($client === false) {
    throw new RuntimeException('No fixture client connected.');
}
try {
    $query = trim((string) fgets($client));
    if (($argv[1] ?? null) === 'timeout') {
        sleep(15);
    }
    $raw = file_get_contents(__DIR__.'/../Fixtures/registration.whois');
    if ($raw === false) {
        throw new RuntimeException('Cannot read fixture response.');
    }
    if (($argv[1] ?? null) === 'oversized') {
        $raw .= str_repeat('x', 1048576);
    }
    $slowDrip = ($argv[1] ?? null) === 'slow-drip';
    if ($slowDrip) {
        $raw = str_repeat('x', 15);
    }
    $sent = 0;
    // Early close is expected when the client rejects an oversized response.
    set_error_handler(static fn (int $severity): bool => $severity === E_NOTICE || $severity === E_WARNING);
    try {
        foreach (str_split(str_replace('EXAMPLE.AT', strtoupper($query), $raw), $slowDrip ? 1 : 4096) as $chunk) {
            if (fwrite($client, $chunk) === false) {
                break;
            }
            $sent += strlen($chunk);
            usleep($slowDrip ? 200000 : 1000);
        }
    } finally {
        restore_error_handler();
    }
    echo 'query='.$query."\nsent=".$sent."\n";
} finally {
    fclose($client);
    fclose($server);
}
