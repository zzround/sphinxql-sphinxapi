<?php

require_once __DIR__ . '/vendor/autoload.php';

use Zround\SphinxClient;

$cl = new SphinxClient();
$cl->SetServer('10.10.10.10', 19314);
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
$cl->AddQuery('阿莫西林', 'yaopincn');

$result = $cl->RunQueries();

print_r($result[0]);

if ($result === false) {
    echo "FAIL: " . $cl->GetLastError() . "\n";
    if ($cl->IsConnectError()) {
        echo "(connection error)\n";
    }
}
