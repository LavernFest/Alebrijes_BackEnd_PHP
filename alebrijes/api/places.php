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

$result = $conn->query("
    SELECT 
        id_place,
        name,
        latitude,
        longitude,
        store_hours,
        video_url,
        audio_url,
        image_url,
        state_code
    FROM places
    ORDER BY name ASC
");

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $conn->error]);
    exit();
}

$places = $result->fetch_all(MYSQLI_ASSOC);

$formatted = array_map(function($place) {
    return [
        'id'         => $place['id_place'],
        'name'       => $place['name'],
        'lat'        => (float) $place['latitude'],
        'lng'        => (float) $place['longitude'],
        'storeHours' => $place['store_hours'],
        'videoUrl'   => $place['video_url'],
        'audioUrl'   => $place['audio_url'],
        'imageUrl'   => $place['image_url'],
        'stateCode'  => $place['state_code'],
        'media'      => $place['video_url'] ? 'video' : 'image',
        'mediaSrc'   => $place['video_url'] ?? $place['image_url'],
    ];
}, $places);

echo json_encode(['success' => true, 'data' => $formatted]);

$conn->close();