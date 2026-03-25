<?php
header("Content-Type: application/json");

require_once "db.php";

$body     = json_decode(file_get_contents("php://input"), true);
$email    = $body["email"]    ?? "";
$password = $body["password"] ?? "";
$stmt = $conn->prepare("SELECT id_user, name, email, role, password FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(401);
    echo json_encode(["error" => "Correo o contraseña incorrectos"]);
    exit();
}

$user = $result->fetch_assoc();

if (!password_verify($password, $user["password"])) {
    http_response_code(401);
    echo json_encode(["error" => "Correo o contraseña incorrectos"]);
    exit();
}

unset($user["password"]);

echo json_encode(["user" => $user]);
$conn->close();