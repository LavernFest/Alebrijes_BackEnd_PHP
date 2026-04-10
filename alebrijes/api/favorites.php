<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "db.php";

$method = $_SERVER['REQUEST_METHOD'];

// GET — obtener favoritos de un usuario
if ($method === 'GET') {
    $id_user = isset($_GET['id_user']) ? intval($_GET['id_user']) : 0;

    if (!$id_user) {
        echo json_encode(['success' => false, 'error' => 'id_user requerido']);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT f.id_favorite, f.id_place, f.alias,
               p.name, p.latitude, p.longitude,
               p.store_hours, p.video_url, p.audio_url,
               p.image_url, p.state_code
        FROM favorites f
        JOIN places p ON f.id_place = p.id_place
        WHERE f.id_user = ?
    ");
    $stmt->bind_param("i", $id_user);
    $stmt->execute();
    $result = $stmt->get_result();
    $favorites = $result->fetch_all(MYSQLI_ASSOC);

    $formatted = array_map(function($f) {
        return [
            'id_favorite' => $f['id_favorite'],
            'id'          => $f['id_place'],
            'alias'       => $f['alias'],
            'name'        => $f['name'],
            'lat'         => (float) $f['latitude'],
            'lng'         => (float) $f['longitude'],
            'storeHours'  => $f['store_hours'],
            'videoUrl'    => $f['video_url'],
            'audioUrl'    => $f['audio_url'],
            'imageUrl'    => $f['image_url'],
            'stateCode'   => $f['state_code'],
            'media'       => $f['video_url'] ? 'video' : 'image',
            'mediaSrc'    => $f['video_url'] ?? $f['image_url'],
        ];
    }, $favorites);

    echo json_encode(['success' => true, 'data' => $formatted]);
    $stmt->close();
    exit();
}

// POST — agregar favorito
if ($method === 'POST') {
    $body = json_decode(file_get_contents("php://input"), true);
    $id_user  = isset($body['id_user'])  ? intval($body['id_user'])  : 0;
    $id_place = isset($body['id_place']) ? intval($body['id_place']) : 0;
    $alias    = isset($body['alias'])    ? trim($body['alias'])       : '';

    if (!$id_user || !$id_place) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'id_user e id_place requeridos']);
        exit();
    }

    // Verificar si ya existe
    $check = $conn->prepare("SELECT id_favorite FROM favorites WHERE id_user = ? AND id_place = ?");
    $check->bind_param("ii", $id_user, $id_place);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'Ya es favorito']);
        $check->close();
        exit();
    }
    $check->close();

    $stmt = $conn->prepare("INSERT INTO favorites (id_user, id_place, alias) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $id_user, $id_place, $alias);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id_favorite' => $conn->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }

    $stmt->close();
    exit();
}

// DELETE — quitar favorito
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents("php://input"), true);
    $id_user  = isset($body['id_user'])  ? intval($body['id_user'])  : 0;
    $id_place = isset($body['id_place']) ? intval($body['id_place']) : 0;

    if (!$id_user || !$id_place) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'id_user e id_place requeridos']);
        exit();
    }

    $stmt = $conn->prepare("DELETE FROM favorites WHERE id_user = ? AND id_place = ?");
    $stmt->bind_param("ii", $id_user, $id_place);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }

    $stmt->close();
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método no permitido']);
$conn->close();