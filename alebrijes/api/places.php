<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "db.php";

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Leer el parámetro "action" para saber qué operación ejecutar
// Equivale a los diferentes métodos de un controller en C#
$action = $_GET['action'] ?? 'all';

switch ($action) {

    // GET /places.php?action=all  →  todos los campos (el que ya tenías)
    case 'all':
        $result = $conn->query("
            SELECT id_place, name, latitude, longitude, store_hours,
                   video_url, audio_url, image_url, state_code
            FROM places ORDER BY name ASC
        ");
        if (!$result) { http_response_code(500); echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }
        $places = $result->fetch_all(MYSQLI_ASSOC);
        $formatted = array_map(function($p) {
            return [
                'id'         => $p['id_place'],
                'name'       => $p['name'],
                'lat'        => (float) $p['latitude'],
                'lng'        => (float) $p['longitude'],
                'storeHours' => $p['store_hours'],
                'videoUrl'   => $p['video_url'],
                'audioUrl'   => $p['audio_url'],
                'imageUrl'   => $p['image_url'],
                'stateCode'  => $p['state_code'],
                'media'      => $p['video_url'] ? 'video' : 'image',
                'mediaSrc'   => $p['video_url'] ?? $p['image_url'],
            ];
        }, $places);
        echo json_encode(['success' => true, 'data' => $formatted]);
        break;

    // GET /places.php?action=list  →  solo id, nombre y estado
    case 'list':
        $result = $conn->query("SELECT id_place, name, state_code FROM places ORDER BY name ASC");
        if (!$result) { http_response_code(500); echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }
        $places = $result->fetch_all(MYSQLI_ASSOC);
        $formatted = array_map(function($p) {
            return ['id' => $p['id_place'], 'name' => $p['name'], 'stateCode' => $p['state_code']];
        }, $places);
        echo json_encode(['success' => true, 'data' => $formatted]);
        break;

    // GET /places.php?action=detail&id=5  →  un solo lugar completo
    case 'detail':
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Se requiere un id válido.']);
            exit();
        }
        $id   = (int) $_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM places WHERE id_place = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Lugar no encontrado.']); exit(); }
        $p = $result->fetch_assoc();
        echo json_encode(['success' => true, 'data' => [
            'id'         => $p['id_place'],
            'name'       => $p['name'],
            'lat'        => (float) $p['latitude'],
            'lng'        => (float) $p['longitude'],
            'storeHours' => $p['store_hours'],
            'videoUrl'   => $p['video_url'],
            'audioUrl'   => $p['audio_url'],
            'imageUrl'   => $p['image_url'],
            'stateCode'  => $p['state_code'],
            'media'      => $p['video_url'] ? 'video' : 'image',
            'mediaSrc'   => $p['video_url'] ?? $p['image_url'],
        ]]);
        $stmt->close();
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acción no reconocida.']);
}

$conn->close();