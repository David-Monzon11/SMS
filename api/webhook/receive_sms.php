<?php

$config = require 'config.php';
$firebaseUrl = $config['FIREBASE_DB_URL'];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) exit;

$senderNumber = $data['sender'] ?? '';
$message      = $data['message'] ?? '';
$message_id   = $data['message_id'] ?? uniqid();

$firebaseData = [
    "message_id"   => $message_id,
    "from"         => $senderNumber,
    "message"      => $message,
    "type"         => "inbound",
    "date_received"=> date("Y-m-d H:i:s")
];

$url = $firebaseUrl . "inbound_messages/$message_id.json";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($firebaseData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
curl_close($ch);

echo json_encode(["status"=>"received"]);