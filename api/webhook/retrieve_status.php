<?php
date_default_timezone_set('UTC');

$logFile = __DIR__ . '/logs/cron_execution.log';

file_put_contents(
    $logFile,
    "[" . date('Y-m-d H:i:s') . "] Cron executed\n",
    FILE_APPEND
);


$config = require __DIR__ . '/config.php';

$apiKey      = $config['SEMAPHORE_API_KEY'];
$firebaseUrl = $config['FIREBASE_DB_URL'];

function get_firebase_data($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);

    if ($response === false) {
        return null;
    }

    return json_decode($response, true);
}

function update_firebase_status($firebaseUrl, $senderKey, $messageId, $status)
{
    $url = $firebaseUrl . "sms_logs/$senderKey/$messageId/status.json";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($status));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
}

$smsLogs = get_firebase_data($firebaseUrl . "sms_logs.json");

if (!$smsLogs) {
    exit("No logs.");
}

foreach ($smsLogs as $senderKey => $messages) {

    foreach ($messages as $messageId => $data) {

        if (in_array($data['status'], ['Queued', 'Pending'])) {

            $url = "https://api.semaphore.co/api/v4/messages/$messageId?apikey=$apiKey";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);

            if ($response !== false) {
                $decoded = json_decode($response, true);

                if (isset($decoded[0]['status'])) {
                    $newStatus = $decoded[0]['status'];

                    update_firebase_status(
                        $firebaseUrl,
                        $senderKey,
                        $messageId,
                        $newStatus
                    );
                }
            }
        }
    }
}

echo "Status update complete.";