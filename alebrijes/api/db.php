<?php
header("Content-Type: application/json");

$host = "localhost";
$db   = "alebrijes";
$user = "root";
$pass = "";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => $conn->connect_error]);
    exit();
}