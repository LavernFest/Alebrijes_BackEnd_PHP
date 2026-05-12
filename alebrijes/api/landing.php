<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

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
$action = $_GET['action'] ?? '';

// GET
if ($method === 'GET') {

    if ($action === 'hero') {
        $result = $conn->query(
            "SELECT id, header, description, image_url, phrase, map_image_url
             FROM landing_hero WHERE id = 1"
        );

        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Error al consultar landing_hero']);
            exit;
        }

        $row = $result->fetch_assoc();
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }

    if ($action === 'layout') {
        $result = $conn->query(
            "SELECT id, component_key, label, sort_order, is_visible
             FROM landing_layout
             ORDER BY sort_order ASC"
        );

        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Error al consultar landing_layout']);
            exit;
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // Sin action: devuelve ambas tablas en una sola llamada
    $hero = $conn->query(
        "SELECT id, header, description, image_url, phrase, map_image_url
         FROM landing_hero WHERE id = 1"
    )->fetch_assoc();

    $layout = $conn->query(
        "SELECT id, component_key, label, sort_order, is_visible
         FROM landing_layout ORDER BY sort_order ASC"
    )->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'data' => ['hero' => $hero, 'layout' => $layout]]);
    exit;
}

// PUT ?action=hero
if ($method === 'PUT' && $action === 'hero') {

    $raw         = file_get_contents('php://input');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $boundary    = '';

    if (preg_match('/boundary=(.*)$/', $contentType, $m)) {
        $boundary = $m[1];
    }

    $fields      = [];
    $heroImgTmp  = null;
    $heroImgName = null;
    $mapImgTmp   = null;
    $mapImgName  = null;

    if ($boundary) {
        $parts = array_slice(explode('--' . $boundary, $raw), 1);

        foreach ($parts as $part) {
            if ($part === "--\r\n" || trim($part) === '--') continue;

            [$rawHeaders, $body] = explode("\r\n\r\n", $part, 2);
            $body = rtrim($body, "\r\n");

            preg_match('/name="([^"]+)"/', $rawHeaders, $nameMatch);
            $fieldName = $nameMatch[1] ?? '';

            if (preg_match('/filename="([^"]+)"/', $rawHeaders, $fileMatch)) {
                preg_match('/Content-Type: ([^\r\n]+)/', $rawHeaders, $ctMatch);
                $mime = trim($ctMatch[1] ?? '');

                // Distinguimos las dos imágenes por el name del campo
                if ($fieldName === 'image') {
                    $heroImgName = $fileMatch[1];
                    $heroImgTmp  = tempnam(sys_get_temp_dir(), 'lhero_');
                    file_put_contents($heroImgTmp, $body);
                    $fields['hero_mime'] = $mime;
                } elseif ($fieldName === 'map_image') {
                    $mapImgName = $fileMatch[1];
                    $mapImgTmp  = tempnam(sys_get_temp_dir(), 'lmap_');
                    file_put_contents($mapImgTmp, $body);
                    $fields['map_mime'] = $mime;
                }
            } else {
                $fields[$fieldName] = $body;
            }
        }
    } else {
        $fields = json_decode($raw, true) ?? [];
    }

    // Validar campos de texto obligatorios
    $header      = trim($fields['header']      ?? '');
    $description = trim($fields['description'] ?? '');
    $phrase      = trim($fields['phrase']       ?? '');

    if ($header === '' || $description === '' || $phrase === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'header, description y phrase son obligatorios']);
        exit;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];

    // Procesar imagen del hero (image_url)
    $heroImageUrl = null;
    if ($heroImgTmp && $heroImgName) {
        if (!in_array($fields['hero_mime'] ?? '', $allowed)) {
            http_response_code(415);
            echo json_encode(['success' => false, 'error' => 'La imagen del hero debe ser JPG, PNG o WEBP']);
            exit;
        }
        if (filesize($heroImgTmp) > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'La imagen del hero no puede superar 5 MB']);
            exit;
        }

        $prev = $conn->query("SELECT image_url FROM landing_hero WHERE id = 1")->fetch_assoc();
        if (!empty($prev['image_url'])) {
            $oldPath = __DIR__ . '/../' . $prev['image_url'];
            if (file_exists($oldPath)) unlink($oldPath);
        }

        $uploadDir = __DIR__ . '/../uploads/pages/landing/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext          = strtolower(pathinfo($heroImgName, PATHINFO_EXTENSION));
        $fileName     = 'hero_' . time() . '.' . $ext;
        copy($heroImgTmp, $uploadDir . $fileName);
        $heroImageUrl = 'uploads/pages/landing/' . $fileName;
    }

    // Procesar imagen del mapa (map_image_url)
    $mapImageUrl = null;
    if ($mapImgTmp && $mapImgName) {
        if (!in_array($fields['map_mime'] ?? '', $allowed)) {
            http_response_code(415);
            echo json_encode(['success' => false, 'error' => 'La imagen del mapa debe ser JPG, PNG o WEBP']);
            exit;
        }
        if (filesize($mapImgTmp) > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'La imagen del mapa no puede superar 5 MB']);
            exit;
        }

        $prev = $conn->query("SELECT map_image_url FROM landing_hero WHERE id = 1")->fetch_assoc();
        if (!empty($prev['map_image_url'])) {
            $oldPath = __DIR__ . '/../' . $prev['map_image_url'];
            if (file_exists($oldPath)) unlink($oldPath);
        }

        $uploadDir = __DIR__ . '/../uploads/pages/landing/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext         = strtolower(pathinfo($mapImgName, PATHINFO_EXTENSION));
        $fileName    = 'map_' . time() . '.' . $ext;
        copy($mapImgTmp, $uploadDir . $fileName);
        $mapImageUrl = 'uploads/pages/landing/' . $fileName;
    }

    // Construir UPDATE dinámico
    $setClauses = ['header = ?', 'description = ?', 'phrase = ?'];
    $types      = 'sss';
    $values     = [$header, $description, $phrase];

    if ($heroImageUrl !== null) {
        $setClauses[] = 'image_url = ?';
        $types       .= 's';
        $values[]     = $heroImageUrl;
    }
    if ($mapImageUrl !== null) {
        $setClauses[] = 'map_image_url = ?';
        $types       .= 's';
        $values[]     = $mapImageUrl;
    }

    $types   .= 'i';
    $values[] = 1;

    $sql  = 'UPDATE landing_hero SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al actualizar landing_hero: ' . $stmt->error]);
        exit;
    }

    $updated = $conn->query(
        "SELECT id, header, description, image_url, phrase, map_image_url
         FROM landing_hero WHERE id = 1"
    )->fetch_assoc();

    echo json_encode(['success' => true, 'data' => $updated]);
    $conn->close();
    exit;
}

// PUT ?action=layout
// Body JSON: [{ "id": 1, "sort_order": 2, "is_visible": 1 }, ...]
if ($method === 'PUT' && $action === 'layout') {

    $items = json_decode(file_get_contents('php://input'), true);

    if (!is_array($items) || empty($items)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Se esperaba un array de componentes']);
        exit;
    }

    $stmt = $conn->prepare(
        "UPDATE landing_layout SET sort_order = ?, is_visible = ? WHERE id = ?"
    );

    foreach ($items as $item) {
        $id         = intval($item['id']         ?? 0);
        $sort_order = intval($item['sort_order'] ?? 0);
        $is_visible = intval($item['is_visible'] ?? 1) ? 1 : 0;

        if (!$id) continue;

        $stmt->bind_param('iii', $sort_order, $is_visible, $id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Error al actualizar componente id=' . $id]);
            exit;
        }
    }

    // Devolver layout actualizado
    $result = $conn->query(
        "SELECT id, component_key, label, sort_order, is_visible
         FROM landing_layout ORDER BY sort_order ASC"
    );
    $updated = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'data' => $updated]);
    $conn->close();
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método no permitido o action inválida']);