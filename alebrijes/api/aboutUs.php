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

// GET: Devuelve el único registro
if ($method === 'GET') {

    $result = $conn->query(
        "SELECT id, description, mission, vision, image_url FROM about_us WHERE id = 1"
    );

    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al consultar la base de datos']);
        exit;
    }

    $row = $result->fetch_assoc();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No se encontró el registro de Nosotros']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $row]);
    exit;
}

// PUT: Actualiza description, mission, vision, image_url
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
                $imgTmp  = tempnam(sys_get_temp_dir(), 'abt_');
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
    $mission     = trim($fields['mission']     ?? '');
    $vision      = trim($fields['vision']      ?? '');

    if ($description === '' || $mission === '' || $vision === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'description, mission y vision son obligatorios']);
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
        $prev = $conn->query("SELECT image_url FROM about_us WHERE id = 1")->fetch_assoc();
        if (!empty($prev['image_url'])) {
            // __DIR__ es .../alebrijes/api  →  /.. sube a .../alebrijes/
            $oldPath = __DIR__ . '/../' . $prev['image_url'];
            if (file_exists($oldPath)) unlink($oldPath);
        }

        // __DIR__ es .../alebrijes/api  →  /../uploads/pages/nosotros/
        $uploadDir = __DIR__ . '/../uploads/pages/nosotros/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext      = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
        $fileName = 'nosotros_' . time() . '.' . $ext;
        $destPath = $uploadDir . $fileName;

        copy($imgTmp, $destPath);

        $imageUrl = 'uploads/pages/nosotros/' . $fileName;
    }

    $setClauses = ['description = ?', 'mission = ?', 'vision = ?'];
    $types      = 'sss';
    $values     = [$description, $mission, $vision];

    if ($imageUrl !== null) {
        $setClauses[] = 'image_url = ?';
        $types       .= 's';
        $values[]     = $imageUrl;
    }

    $types   .= 'i';
    $values[] = 1;

    $sql  = 'UPDATE about_us SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al actualizar: ' . $stmt->error]);
        exit;
    }

    $result  = $conn->query(
        "SELECT id, description, mission, vision, image_url FROM about_us WHERE id = 1"
    );
    $updated = $result->fetch_assoc();

    echo json_encode(['success' => true, 'data' => $updated]);
    $conn->close();
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método no permitido']);