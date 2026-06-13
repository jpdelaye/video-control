<?php
// =====================================================
// YouTube Remote Controller - API Backend
// Handles playlist and queue persistence via MySQL
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Database Configuration ──────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'u659317277_bruno');
define('DB_PASS', '$Bruno123456$');
define('DB_NAME', 'u659317277_bruno');

// ── Connect ─────────────────────────────────────────
function getDB(): mysqli {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_error) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $db->connect_error]);
        exit;
    }
    $db->set_charset('utf8mb4');
    return $db;
}

// ── Auto-create tables if they don't exist ──────────
function ensureTables(mysqli $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS playlists (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(120) NOT NULL UNIQUE,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS playlist_videos (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            playlist_id  INT UNSIGNED NOT NULL,
            video_id     VARCHAR(20)  NOT NULL,
            title        VARCHAR(300) NOT NULL,
            channel      VARCHAR(200) DEFAULT '',
            thumbnail    VARCHAR(400) DEFAULT '',
            position     SMALLINT UNSIGNED DEFAULT 0,
            added_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Single-row state table for multi-device player sync
    $db->query("
        CREATE TABLE IF NOT EXISTS player_state (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            command     VARCHAR(30)  NOT NULL DEFAULT 'IDLE',
            video_id    VARCHAR(20)  NOT NULL DEFAULT '',
            title       VARCHAR(300) NOT NULL DEFAULT '',
            thumbnail   VARCHAR(400) NOT NULL DEFAULT '',
            seek_pct    FLOAT        NOT NULL DEFAULT 0,
            volume      TINYINT      NOT NULL DEFAULT 50,
            command_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status_json TEXT         NULL,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Ensure column exists for existing DBs safely
    $colCheck = $db->query("SHOW COLUMNS FROM player_state LIKE 'status_json'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $db->query("ALTER TABLE player_state ADD COLUMN status_json TEXT NULL");
    }

    // Ensure row id=1 exists so that player_status UPDATE always works
    $db->query("INSERT IGNORE INTO player_state (id, command) VALUES (1, 'IDLE')");
}

// ── Router ───────────────────────────────────────────
$action      = $_GET['action'] ?? $_POST['action'] ?? '';
$requestBody = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $requestBody = json_decode($raw, true) ?? [];
    }
    if (!$action) {
        $action = $requestBody['action'] ?? '';
    }
}

$db = getDB();
ensureTables($db);

switch ($action) {

    // ── GET: list all playlists with their videos ────
    case 'get_playlists':
        $playlists = [];
        $res = $db->query("SELECT id, name FROM playlists ORDER BY created_at ASC");
        while ($row = $res->fetch_assoc()) {
            $playlists[$row['name']] = [];
            $vRes = $db->query("SELECT video_id, title, channel, thumbnail
                                FROM playlist_videos
                                WHERE playlist_id = {$row['id']}
                                ORDER BY position ASC, added_at ASC");
            while ($v = $vRes->fetch_assoc()) {
                $playlists[$row['name']][] = [
                    'id'        => $v['video_id'],
                    'title'     => $v['title'],
                    'channel'   => $v['channel'],
                    'thumbnail' => $v['thumbnail'],
                ];
            }
        }
        echo json_encode(['success' => true, 'playlists' => $playlists]);
        break;

    // ── POST: create a new empty playlist ────────────
    case 'create_playlist':
        $name = trim($requestBody['name'] ?? '');
        if (!$name) { echo json_encode(['error' => 'Name is required']); break; }

        $stmt = $db->prepare("INSERT IGNORE INTO playlists (name) VALUES (?)");
        $stmt->bind_param('s', $name);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            echo json_encode(['error' => 'Playlist already exists']);
        } else {
            echo json_encode(['success' => true, 'message' => "Playlist '$name' created"]);
        }
        break;

    // ── POST: delete a playlist (and all its videos) ─
    case 'delete_playlist':
        $name = trim($requestBody['name'] ?? '');
        if (!$name) { echo json_encode(['error' => 'Name is required']); break; }

        $stmt = $db->prepare("DELETE FROM playlists WHERE name = ?");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        echo json_encode(['success' => true]);
        break;

    // ── POST: add a video to a playlist ─────────────
    case 'add_video':
        $playlistName = trim($requestBody['playlist']  ?? '');
        $videoId      = trim($requestBody['video_id']  ?? '');
        $title        = trim($requestBody['title']     ?? '');
        $channel      = trim($requestBody['channel']   ?? '');
        $thumbnail    = trim($requestBody['thumbnail'] ?? '');

        if (!$playlistName || !$videoId) {
            echo json_encode(['error' => 'playlist and video_id are required']);
            break;
        }

        // Get or create playlist
        $stmt = $db->prepare("INSERT IGNORE INTO playlists (name) VALUES (?)");
        $stmt->bind_param('s', $playlistName);
        $stmt->execute();

        $stmt = $db->prepare("SELECT id FROM playlists WHERE name = ?");
        $stmt->bind_param('s', $playlistName);
        $stmt->execute();
        $res = $stmt->get_result();
        $playlist = $res->fetch_assoc();
        $playlistId = $playlist['id'];

        // Check duplicate
        $stmt = $db->prepare("SELECT id FROM playlist_videos WHERE playlist_id = ? AND video_id = ?");
        $stmt->bind_param('is', $playlistId, $videoId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['error' => 'Video already in playlist']);
            break;
        }

        // Get next position
        $pos = $db->query("SELECT COALESCE(MAX(position)+1,0) AS pos FROM playlist_videos WHERE playlist_id=$playlistId")->fetch_assoc()['pos'];

        $stmt = $db->prepare("INSERT INTO playlist_videos (playlist_id, video_id, title, channel, thumbnail, position) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('issssi', $playlistId, $videoId, $title, $channel, $thumbnail, $pos);
        $stmt->execute();

        echo json_encode(['success' => true]);
        break;

    // ── POST: remove a video from a playlist ─────────
    case 'remove_video':
        $playlistName = trim($requestBody['playlist'] ?? '');
        $videoId      = trim($requestBody['video_id'] ?? '');

        if (!$playlistName || !$videoId) {
            echo json_encode(['error' => 'playlist and video_id are required']);
            break;
        }

        $stmt = $db->prepare("
            DELETE pv FROM playlist_videos pv
            JOIN playlists p ON p.id = pv.playlist_id
            WHERE p.name = ? AND pv.video_id = ?
        ");
        $stmt->bind_param('ss', $playlistName, $videoId);
        $stmt->execute();
        echo json_encode(['success' => true]);
        break;

    // ── POST: set current player state (called by controller) ──
    case 'set_state':
        $command   = trim($requestBody['command']    ?? 'IDLE');
        $videoId   = trim($requestBody['video_id']   ?? '');
        $title     = trim($requestBody['title']      ?? '');
        $thumbnail = trim($requestBody['thumbnail']  ?? '');
        $seekPct   = floatval($requestBody['seek_pct']   ?? 0);
        $volume    = intval($requestBody['volume']    ?? 50);
        $commandId = intval($requestBody['command_id'] ?? 0);

        $stmt = $db->prepare("
            INSERT INTO player_state
                (id, command, video_id, title, thumbnail, seek_pct, volume, command_id)
            VALUES
                (1, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                command    = VALUES(command),
                video_id   = VALUES(video_id),
                title      = VALUES(title),
                thumbnail  = VALUES(thumbnail),
                seek_pct   = VALUES(seek_pct),
                volume     = VALUES(volume),
                command_id = VALUES(command_id)
        ");
        $stmt->bind_param('ssssdii', $command, $videoId, $title, $thumbnail, $seekPct, $volume, $commandId);
        $stmt->execute();
        echo json_encode(['success' => true]);
        break;

    // ── POST: player reports its playback status ──
    case 'player_status':
        $statusJson = json_encode($requestBody['status'] ?? []);
        $stmt = $db->prepare("
            INSERT INTO player_state (id, command, status_json, updated_at) 
            VALUES (1, 'IDLE', ?, NOW())
            ON DUPLICATE KEY UPDATE 
            status_json = VALUES(status_json), updated_at = NOW()
        ");
        $stmt->bind_param('s', $statusJson);
        $stmt->execute();
        echo json_encode(['success' => true]);
        break;

    case 'debug_db':
        $res = $db->query("SELECT * FROM player_state");
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── GET: get current player state (polled by remote players) ─
    case 'get_state':
        $res = $db->query("SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) as ping_age FROM player_state WHERE id = 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            echo json_encode(['success' => true, 'state' => null]);
        } else {
            echo json_encode(['success' => true, 'state' => [
                'command'    => $row['command'],
                'video_id'   => $row['video_id'],
                'title'      => $row['title'],
                'thumbnail'  => $row['thumbnail'],
                'seek_pct'   => (float)$row['seek_pct'],
                'volume'     => (int)$row['volume'],
                'command_id' => (int)$row['command_id'],
                'status_json'=> $row['status_json'],
                'ping_age'   => (int)$row['ping_age']
            ]]);
        }
        break;

    // ── GET/POST: Server-side YouTube Search Proxy ──
    case 'search_youtube':
        $query = urlencode($requestBody['query'] ?? $_GET['query'] ?? '');
        if (!$query) {
            echo json_encode(['success' => false, 'error' => 'Empty query']);
            break;
        }

        // Fetch YouTube search results HTML
        $context = stream_context_create([
            'http' => [
                'header' => "Accept-Language: es-ES,es;q=0.9\r\n" .
                            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36\r\n"
            ]
        ]);
        $html = @file_get_contents("https://www.youtube.com/results?search_query=$query", false, $context);

        if (!$html) {
            echo json_encode(['success' => false, 'error' => 'No se pudo conectar a YouTube']);
            break;
        }

        // Extract the ytInitialData JSON object from the page
        if (preg_match('/var ytInitialData = ({.*?});<\/script>/', $html, $matches)) {
            $data = json_decode($matches[1], true);
            $videos = [];
            
            $contents = $data['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'] ?? [];
            foreach ($contents as $section) {
                $items = $section['itemSectionRenderer']['contents'] ?? [];
                foreach ($items as $item) {
                    if (isset($item['videoRenderer'])) {
                        $v = $item['videoRenderer'];
                        $videos[] = [
                            'id' => $v['videoId'] ?? '',
                            'title' => $v['title']['runs'][0]['text'] ?? '',
                            'channel' => $v['ownerText']['runs'][0]['text'] ?? '',
                            'thumbnail' => "https://img.youtube.com/vi/" . ($v['videoId'] ?? '') . "/hqdefault.jpg"
                        ];
                        if (count($videos) >= 15) break 2; // Limit to 15 results
                    }
                }
            }
            echo json_encode(['success' => true, 'data' => $videos]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No se pudo leer la respuesta de YouTube']);
        }
        break;
    // ── GET/POST: Related Videos for a given video ID ──
    case 'related_videos':
        $videoId = trim($requestBody['video_id'] ?? $_GET['video_id'] ?? '');
        $title = trim($requestBody['title'] ?? $_GET['title'] ?? '');
        
        if (!$videoId && !$title) {
            echo json_encode(['success' => false, 'error' => 'video_id or title is required']);
            break;
        }

        // Buscar en YouTube usando el título del video (o el ID si no hay título)
        $query = urlencode($title ? $title : $videoId);
        $context = stream_context_create([
            'http' => [
                'header' => "Accept-Language: es-ES,es;q=0.9\r\n" .
                            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36\r\n",
                'timeout' => 8
            ]
        ]);
        
        $html = @file_get_contents("https://www.youtube.com/results?search_query=$query", false, $context);

        if (!$html) {
            echo json_encode(['success' => false, 'error' => 'No se pudo conectar a YouTube']);
            break;
        }

        if (preg_match('/var ytInitialData = ({.*?});<\/script>/', $html, $matches)) {
            $data = json_decode($matches[1], true);
            $videos = [];
            
            $contents = $data['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'] ?? [];
            foreach ($contents as $section) {
                $items = $section['itemSectionRenderer']['contents'] ?? [];
                foreach ($items as $item) {
                    if (isset($item['videoRenderer'])) {
                        $v = $item['videoRenderer'];
                        $vid = $v['videoId'] ?? '';
                        // No incluir el video que ya se está reproduciendo
                        if (!$vid || $vid === $videoId) continue;
                        
                        $videos[] = [
                            'id' => $vid,
                            'title' => $v['title']['runs'][0]['text'] ?? '',
                            'channel' => $v['ownerText']['runs'][0]['text'] ?? '',
                            'thumbnail' => "https://img.youtube.com/vi/" . $vid . "/hqdefault.jpg"
                        ];
                        if (count($videos) >= 12) break 2;
                    }
                }
            }
            echo json_encode(['success' => true, 'data' => $videos]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No se pudo leer la respuesta de búsqueda de YouTube']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Unknown action: '$action'"]);
        break;
}

$db->close();
