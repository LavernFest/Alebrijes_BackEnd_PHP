<?php
header("Content-Type: application/json");
require_once "db.php";

$method = $_SERVER["REQUEST_METHOD"];

//  GET, Listar todos los usuarios (admin)
if ($method === "GET") {
    $result = $conn->query("SELECT id_user, name, email, role, pfp FROM users");
    $users  = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($users);
    $conn->close();
    exit();
}

//  POST, Registrar nuevo usuario
if ($method === "POST") {
    $body     = json_decode(file_get_contents("php://input"), true);
    $name     = trim($body["name"]     ?? "");
    $email    = trim($body["email"]    ?? "");
    $password =      $body["password"] ?? "";

    if (!$name || !$email || !$password) {
        http_response_code(400);
        echo json_encode(["error" => "Nombre, correo y contraseña son requeridos"]);
        exit();
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(["error" => "Formato de correo inválido"]);
        exit();
    }
    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(["error" => "La contraseña debe tener al menos 6 caracteres"]);
        exit();
    }

    $check = $conn->prepare("SELECT id_user FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        http_response_code(409);
        echo json_encode(["error" => "Este correo ya está registrado"]);
        exit();
    }
    $check->close();

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $role = "user";

    $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $email, $hash, $role);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(["error" => "No se pudo crear el usuario"]);
        exit();
    }

    $newId = $stmt->insert_id;
    http_response_code(201);
    echo json_encode([
        "message" => "Usuario registrado exitosamente",
        "user"    => ["id_user" => $newId, "name" => $name, "email" => $email, "role" => $role, "pfp" => null]
    ]);
    $conn->close();
    exit();
}


//  PUT , Actualizar usuario
if ($method === "PUT") {

    $raw        = file_get_contents("php://input");
    $boundary   = "";

    // Leer boundary del Content-Type
    $contentType = $_SERVER["CONTENT_TYPE"] ?? "";
    if (preg_match('/boundary=(.*)$/', $contentType, $m)) {
        $boundary = $m[1];
    }

    // Si viene con boundary → multipart (hay foto)
    // Si no → JSON plano (solo texto)
    $fields = [];
    $pfpTmp = null;
    $pfpName = null;

    if ($boundary) {
        $parts = array_slice(explode("--" . $boundary, $raw), 1);
        foreach ($parts as $part) {
            if ($part === "--\r\n" || trim($part) === "--") continue;
            [$rawHeaders, $body] = explode("\r\n\r\n", $part, 2);
            $body = rtrim($body, "\r\n");

            preg_match('/name="([^"]+)"/', $rawHeaders, $nameMatch);
            $fieldName = $nameMatch[1] ?? "";

            if (preg_match('/filename="([^"]+)"/', $rawHeaders, $fileMatch)) {
                // Es un archivo
                $pfpName = $fileMatch[1];
                $pfpTmp  = tempnam(sys_get_temp_dir(), "pfp_");
                file_put_contents($pfpTmp, $body);
                preg_match('/Content-Type: ([^\r\n]+)/', $rawHeaders, $ctMatch);
                $fields["pfp_mime"] = trim($ctMatch[1] ?? "");
            } else {
                $fields[$fieldName] = $body;
            }
        }
    } else {
        // JSON plano
        $fields = json_decode($raw, true) ?? [];
    }
    $id_user = intval($fields["id_user"] ?? 0);
    if (!$id_user) {
        http_response_code(400);
        echo json_encode(["error" => "id_user es requerido"]);
        exit();
    }

    // Verificar que el usuario exista
    $check = $conn->prepare("SELECT id_user, pfp FROM users WHERE id_user = ?");
    $check->bind_param("i", $id_user);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    if (!$row) {
        http_response_code(404);
        echo json_encode(["error" => "Usuario no encontrado"]);
        exit();
    }
    $check->close();
    $name     = isset($fields["name"])     ? trim($fields["name"])     : null;
    $email    = isset($fields["email"])    ? trim($fields["email"])    : null;
    $password = isset($fields["password"]) ? $fields["password"]       : null;

    // Validar email si se envió
    if ($email !== null) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["error" => "Formato de correo inválido"]);
            exit();
        }
        // Verificar que no esté en uso por otro usuario
        $dup = $conn->prepare("SELECT id_user FROM users WHERE email = ? AND id_user != ?");
        $dup->bind_param("si", $email, $id_user);
        $dup->execute();
        $dup->store_result();
        if ($dup->num_rows > 0) {
            http_response_code(409);
            echo json_encode(["error" => "Este correo ya está en uso"]);
            exit();
        }
        $dup->close();
    }

    // Validar contraseña si se envió
    if ($password !== null && strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(["error" => "La contraseña debe tener al menos 6 caracteres"]);
        exit();
    }

    // Manejo de foto de perfil
    $pfp_url = null;

    if ($pfpTmp && $pfpName) {
        $allowed = ["image/jpeg", "image/png", "image/webp", "image/gif"];
        $mime    = $fields["pfp_mime"] ?? "";

        if (!in_array($mime, $allowed)) {
            http_response_code(400);
            echo json_encode(["error" => "Formato de imagen no permitido. Usa JPG, PNG o WEBP"]);
            exit();
        }

        $maxSize = 5 * 1024 * 1024;
        if (filesize($pfpTmp) > $maxSize) {
            http_response_code(400);
            echo json_encode(["error" => "La imagen no puede superar 5 MB"]);
            exit();
        }

        $uploadDir = __DIR__ . "/../uploads/perfiles/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Borrar foto anterior si existía
        if ($row["pfp"]) {
            $old = __DIR__ . "/../../" . $row["pfp"];
            if (file_exists($old)) unlink($old);
        }

        $ext      = pathinfo($pfpName, PATHINFO_EXTENSION);
        $filename = "pfp_" . $id_user . "_" . time() . "." . $ext;
        $dest     = $uploadDir . $filename;

        move_uploaded_file($pfpTmp, $dest) || copy($pfpTmp, $dest);
        $pfp_url = "uploads/perfiles/" . $filename;
    }
    $setClauses = [];
    $types      = "";
    $values     = [];

    if ($name !== null)     { $setClauses[] = "name = ?";     $types .= "s"; $values[] = $name; }
    if ($email !== null)    { $setClauses[] = "email = ?";    $types .= "s"; $values[] = $email; }
    if ($password !== null) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $setClauses[] = "password = ?";
        $types .= "s";
        $values[] = $hash;
    }
    if ($pfp_url !== null)  { $setClauses[] = "pfp = ?";      $types .= "s"; $values[] = $pfp_url; }

    if (empty($setClauses)) {
        http_response_code(400);
        echo json_encode(["error" => "No se enviaron campos para actualizar"]);
        exit();
    }

    $types   .= "i";
    $values[] = $id_user;

    $sql  = "UPDATE users SET " . implode(", ", $setClauses) . " WHERE id_user = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(["error" => "No se pudo actualizar el usuario"]);
        exit();
    }

    // Devolver usuario actualizado (sin password)
    $fetch = $conn->prepare("SELECT id_user, name, email, role, pfp FROM users WHERE id_user = ?");
    $fetch->bind_param("i", $id_user);
    $fetch->execute();
    $updated = $fetch->get_result()->fetch_assoc();

    echo json_encode([
        "message" => "Usuario actualizado correctamente",
        "user"    => $updated
    ]);
    $conn->close();
    exit();
}