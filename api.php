<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

class ReflectionAPI {
    private $db;

    public function __construct() {
        $this->connectDB();
        $this->createTables();
    }

    private function connectDB() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $this->db = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            $this->sendError('Database connection failed', 500);
        }
    }

    private function createTables() {
        $sql = "CREATE TABLE IF NOT EXISTS reflections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(255) NOT NULL,
            date DATETIME NOT NULL,
            date_string VARCHAR(255) NOT NULL,
            went_well TEXT,
            didnt_go_well TEXT,
            surprises TEXT,
            next_time TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_date (user_id, date_string)
        )";

        try {
            $this->db->exec($sql);
        } catch (PDOException $e) {
            $this->sendError('Table creation failed', 500);
        }
    }

    private function getUserId() {
        $headers = getallheaders();

        if (isset($headers['Authorization'])) {
            return substr($headers['Authorization'], 7);
        }

        if (isset($_SERVER['HTTP_X_USER_ID'])) {
            return $_SERVER['HTTP_X_USER_ID'];
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        return 'anon_' . hash('sha256', $ip . $userAgent);
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $userId = $this->getUserId();

        switch ($method) {
            case 'GET':
                $this->getReflections($userId);
                break;
            case 'POST':
                $this->saveReflection($userId);
                break;
            case 'DELETE':
                $this->deleteReflection($userId);
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    private function getReflections($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, date, date_string, went_well, didnt_go_well, surprises, next_time 
                FROM reflections 
                WHERE user_id = ? 
                ORDER BY date DESC
            ");
            $stmt->execute([$userId]);
            $reflections = $stmt->fetchAll();

            $lastCompleted = null;
            if (!empty($reflections)) {
                $stmt = $this->db->prepare("
                    SELECT date_string 
                    FROM reflections 
                    WHERE user_id = ? 
                    ORDER BY date DESC 
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $result = $stmt->fetch();
                $lastCompleted = $result ? $result['date_string'] : null;
            }

            $this->sendResponse([
                'reflections' => $reflections,
                'lastCompleted' => $lastCompleted
            ]);

        } catch (PDOException $e) {
            $this->sendError('Failed to fetch reflections', 500);
        }
    }

    private function saveReflection($userId) {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            $this->sendError('Invalid JSON input', 400);
            return;
        }

        $required = ['date', 'dateString'];
        foreach ($required as $field) {
            if (!isset($input[$field])) {
                $this->sendError("Missing field: $field", 400);
                return;
            }
        }

        if (isset($_GET['id'])) {
            $this->updateReflection($userId, $_GET['id'], $input);
            return;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM reflections 
                WHERE user_id = ? AND date_string = ?
            ");
            $stmt->execute([$userId, $input['dateString']]);

            if ($stmt->fetchColumn() > 0) {
                $this->sendError('Reflection already exists for this date', 409);
                return;
            }

            $stmt = $this->db->prepare("
                INSERT INTO reflections (user_id, date, date_string, went_well, didnt_go_well, surprises, next_time)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                $input['date'],
                $input['dateString'],
                $input['wentWell'] ?? null,
                $input['didntGoWell'] ?? null,
                $input['surprises'] ?? null,
                $input['nextTime'] ?? null
            ]);

            $this->sendResponse([
                'id' => $this->db->lastInsertId(),
                'message' => 'Reflection saved successfully'
            ]);

        } catch (PDOException $e) {
            $this->sendError('Failed to save reflection', 500);
        }
    }

    private function updateReflection($userId, $id, $input) {
        try {
            $stmt = $this->db->prepare("
                UPDATE reflections 
                SET went_well = ?, didnt_go_well = ?, surprises = ?, next_time = ?
                WHERE id = ? AND user_id = ?
            ");

            $result = $stmt->execute([
                $input['wentWell'] ?? null,
                $input['didntGoWell'] ?? null,
                $input['surprises'] ?? null,
                $input['nextTime'] ?? null,
                $id,
                $userId
            ]);

            if ($stmt->rowCount() === 0) {
                $this->sendError('Reflection not found or access denied', 404);
                return;
            }

            $this->sendResponse(['message' => 'Reflection updated successfully']);

        } catch (PDOException $e) {
            $this->sendError('Failed to update reflection', 500);
        }
    }

    private function deleteReflection($userId) {
        if (isset($_GET['id'])) {
            try {
                $stmt = $this->db->prepare("DELETE FROM reflections WHERE id = ? AND user_id = ?");
                $stmt->execute([$_GET['id'], $userId]);

                if ($stmt->rowCount() === 0) {
                    $this->sendError('Reflection not found or access denied', 404);
                    return;
                }

                $this->sendResponse(['message' => 'Reflection deleted successfully']);

            } catch (PDOException $e) {
                $this->sendError('Failed to delete reflection', 500);
            }
        } else {
            try {
                $stmt = $this->db->prepare("DELETE FROM reflections WHERE user_id = ?");
                $stmt->execute([$userId]);

                $this->sendResponse(['message' => 'All reflections deleted successfully']);

            } catch (PDOException $e) {
                $this->sendError('Failed to delete reflections', 500);
            }
        }
    }

    private function sendResponse($data, $code = 200) {
        http_response_code($code);
        echo json_encode($data);
        exit();
    }

    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit();
    }
}

$api = new ReflectionAPI();
$api->handleRequest();
