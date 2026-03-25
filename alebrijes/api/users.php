<?php
require_once "db.php";

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $result = $conn->query("SELECT id_user, name, email, role FROM users");
    $users = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($users);
}

$conn->close();