<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET
if ($method === 'GET') {

    $result = $conn->query(
        "SELECT id, description, phone, instagram, facebook, email, image_url
         FROM contact
         WHERE id = 1"
    );

    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al consultar la base de datos']);
        exit;
    }

    $row = $result->fetch_assoc();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No se encontró el registro de Contáctanos']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $row]);
    exit;
}

// PUT
if ($method === 'PUT') {

    $raw         = file_get_contents('php://input');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $boundary    = '';

    if (preg_match('/boundary=(.*)$/', $contentType, $m)) {
        $boundary = $m[1];
    }

    $fields  = [];
    $imgTmp  = null;
    $imgName = null;

    if ($boundary) {
        $parts = array_slice(explode('--' . $boundary, $raw), 1);

        foreach ($parts as $part) {
            if ($part === "--\r\n" || trim($part) === '--') continue;

            [$rawHeaders, $body] = explode("\r\n\r\n", $part, 2);
            $body = rtrim($body, "\r\n");

            preg_match('/name="([^"]+)"/', $rawHeaders, $nameMatch);
            $fieldName = $nameMatch[1] ?? '';

            if (preg_match('/filename="([^"]+)"/', $rawHeaders, $fileMatch)) {
                $imgName = $fileMatch[1];
                $imgTmp  = tempnam(sys_get_temp_dir(), 'cnt_');
                file_put_contents($imgTmp, $body);
                preg_match('/Content-Type: ([^\r\n]+)/', $rawHeaders, $ctMatch);
                $fields['img_mime'] = trim($ctMatch[1] ?? '');
            } else {
                $fields[$fieldName] = $body;
            }
        }
    } else {
        $fields = json_decode($raw, true) ?? [];
    }

    $description = trim($fields['description'] ?? '');
    $phone       = trim($fields['phone']       ?? '');
    $instagram   = trim($fields['instagram']   ?? '');
    $facebook    = trim($fields['facebook']    ?? '');
    $email       = trim($fields['email']       ?? '');

    if ($description === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La descripción es obligatoria']);
        exit;
    }

    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El teléfono debe tener exactamente 10 dígitos']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El correo no tiene un formato válido']);
        exit;
    }

    if ($instagram !== '' && !filter_var($instagram, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La URL de Instagram no es válida']);
        exit;
    }

    if ($facebook !== '' && !filter_var($facebook, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La URL de Facebook no es válida']);
        exit;
    }

    $imageUrl = null;

    if ($imgTmp && $imgName) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $mime    = $fields['img_mime'] ?? '';

        if (!in_array($mime, $allowed)) {
            http_response_code(415);
            echo json_encode(['success' => false, 'error' => 'Solo se permiten imágenes JPG, PNG o WEBP']);
            exit;
        }

        if (filesize($imgTmp) > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'La imagen no puede superar 5 MB']);
            exit;
        }

        // Borrar imagen anterior si existía
        // __DIR__ es .../alebrijes/api  →  /.. sube a .../alebrijes/
        $prev = $conn->query("SELECT image_url FROM contact WHERE id = 1")->fetch_assoc();
        if (!empty($prev['image_url'])) {
            $oldPath = __DIR__ . '/../' . $prev['image_url'];
            if (file_exists($oldPath)) unlink($oldPath);
        }

        // __DIR__ es .../alebrijes/api  →  /../uploads/pages/contactanos/
        $uploadDir = __DIR__ . '/../uploads/pages/contactanos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext      = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
        $fileName = 'contactanos_' . time() . '.' . $ext;
        $destPath = $uploadDir . $fileName;

        copy($imgTmp, $destPath);

        $imageUrl = 'uploads/pages/contactanos/' . $fileName;
    }

    $setClauses = [
        'description = ?',
        'phone = ?',
        'instagram = ?',
        'facebook = ?',
        'email = ?'
    ];
    $types  = 'sssss';
    $values = [$description, $phone, $instagram, $facebook, $email];

    if ($imageUrl !== null) {
        $setClauses[] = 'image_url = ?';
        $types       .= 's';
        $values[]     = $imageUrl;
    }

    $types   .= 'i';
    $values[] = 1;

    $sql  = 'UPDATE contact SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al actualizar: ' . $stmt->error]);
        exit;
    }

    $result = $conn->query(
        "SELECT id, description, phone, instagram, facebook, email, image_url
         FROM contact
         WHERE id = 1"
    );
    $updated = $result->fetch_assoc();

    echo json_encode(['success' => true, 'data' => $updated]);
    $conn->close();
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método no permitido']);