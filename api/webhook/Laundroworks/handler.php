<?php

header("Content-Type: application/json");

$config = require __DIR__ . '/config/env.php';

$rawLog     = __DIR__ . '/logs/raw_payload.log';
$forwardLog = __DIR__ . '/logs/forward.log';
$idempotentDir = __DIR__ . '/logs/idempotent/';

if (!is_dir($idempotentDir)) {
    mkdir($idempotentDir, 0777, true);
}

function writeLog($file, $data)
{
    $entry = "[" . date("Y-m-d H:i:s") . "] " . $data . PHP_EOL;
    file_put_contents($file, $entry, FILE_APPEND);
}

$rawInput = file_get_contents("php://input");

if (!$rawInput) {
    http_response_code(400);
    exit(json_encode(["error" => "No payload received"]));
}

writeLog($rawLog, $rawInput);

$payloadHash = hash('sha256', $rawInput);
$idFile = $idempotentDir . $payloadHash . '.lock';

if (file_exists($idFile)) {
    writeLog($forwardLog, "Duplicate webhook ignored.");
    http_response_code(200);
    exit(json_encode(["message" => "Already processed"]));
}

file_put_contents($idFile, time());

$data = json_decode($rawInput, true);

if (!$data) {
    http_response_code(400);
    exit(json_encode(["error" => "Invalid JSON"]));
}

$storeCode = $data['location_id'] ?? null;
$fullName  = trim($data['registered_name'] ?? '');
$phone     = trim($data['registered_phone'] ?? '');
$email     = trim($data['registered_email'] ?? '');

if (!$storeCode) {
    http_response_code(400);
    exit(json_encode(["error" => "Missing location_id"]));
}

$nameParts = explode(" ", $fullName, 2);
$firstName = $nameParts[0] ?? '';
$lastName  = $nameParts[1] ?? '';


if (!empty($phone)) {
    $phone = preg_replace('/\D/', '', $phone);

    if (strlen($phone) == 10) {
        $phone = '+1' . $phone;
    } elseif (strlen($phone) == 11 && substr($phone, 0, 1) == '1') {
        $phone = '+' . $phone;
    }
}

$matched = null;

foreach ($config['LOCATIONS'] as $group) {
    if (isset($group[$storeCode])) {
        $matched = $group[$storeCode];
        break;
    }
}

if (!$matched) {
    http_response_code(404);
    exit(json_encode(["error" => "Store not found"]));
}

$pit         = $matched['pit'];
$ghlLocation = $matched['ghl_location_id'];

$baseUrl = rtrim($config['SUDS_MGT_API_URL'], '/');
$version = $config['API_VERSION'];

$testingStatus = ($config['ENVIRONMENT'] === 'production')
    ? 'tested'
    : 'for testing';

$createPayload = [
    "firstName"  => $firstName,
    "lastName"   => $lastName,
    "locationId" => $ghlLocation,
    "tags"       => ["mitech", "new"],
    "customFields" => [
        [
            "key"   => "testing_status",
            "value" => $testingStatus
        ]
    ]
];

if (!empty($phone)) $createPayload["phone"] = $phone;
if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
    $createPayload["email"] = strtolower($email);

function sendRequest($url, $method, $pit, $version, $payload)
{
    $maxRetries = 3;
    $attempt = 0;

    do {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $pit",
                "Content-Type: application/json",
                "Version: $version"
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpCode >= 500) {
            sleep(1);
        } else {
            break;
        }

        $attempt++;

    } while ($attempt < $maxRetries);

    return [$httpCode, $response];
}

list($httpCode, $response) =
    sendRequest($baseUrl, "POST", $pit, $version, $createPayload);

writeLog($forwardLog, "CREATE | Store: $storeCode | HTTP: $httpCode | Response: $response");

$responseData = json_decode($response, true);

if (
    $httpCode == 400 &&
    isset($responseData['meta']['contactId'])
) {

    $duplicateId = $responseData['meta']['contactId'];
    $updatePayload = $createPayload;
    
    unset($updatePayload['locationId']);

    $updatePayload['tags'] = ["mitech", "existing"];
    $updateUrl = $baseUrl . "/" . $duplicateId;

    list($updateHttpCode, $updateResponse) = sendRequest($updateUrl, "PUT", $pit, $version, $updatePayload);
    writeLog($forwardLog, "UPDATE via duplicate | ID: $duplicateId | HTTP: $updateHttpCode | Response: $updateResponse");

    http_response_code($updateHttpCode);
    echo $updateResponse;
    exit;
}

http_response_code($httpCode);
echo $response;
