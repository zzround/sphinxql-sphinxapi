<?php

require_once __DIR__ . '/sphinxapi.php';

$cl = new SphinxClient();
$cl->SetServer('127.0.0.1', 9306);
$cl->SetConnectTimeout(3);

echo "=== Ping ===\n";
if ($cl->Ping()) {
    echo "OK: server is alive\n";
} else {
    echo "FAIL: " . $cl->GetLastError() . "\n";
}

echo "\n=== Query: test ===\n";

$cl->SetArrayResult(true);
$cl->SetLimits(200, 20);
$cl->AddQuery('*', 'index');

$result = $cl->RunQueries();

print_r($result[0]);

if ($result === false) {
    echo "FAIL: " . $cl->GetLastError() . "\n";
    if ($cl->IsConnectError()) {
        echo "(connection error)\n";
    }
}
