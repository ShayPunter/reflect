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
    private $logFile;

    public function __construct() {
        $this->setupLogging();
        $this->log('API Request started', ['method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI']]);

        $this->connectDB();
        $this->createTables();
    }

    private function setupLogging() {
        $logDir = __DIR__ . '/storage/logs';

        // Create directories if they don't exist
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                error_log('Failed to create log directory: ' . $logDir);
                $this->logFile = __DIR__ . '/reflection_logs.txt'; // Fallback
                return;
            }
        }

        // Create daily log files
        $today = date('Y-m-d');
        $this->logFile = $logDir . '/reflections-' . $today . '.log';

        // Ensure log file is writable
        if (!file_exists($this->logFile)) {
            touch($this->logFile);
            chmod($this->logFile, 0644);
        }
    }

    private function log($message, $data = null) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message";

        if ($data) {
            $logEntry .= ' | Data: ' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $logEntry .= PHP_EOL;

        // Write to log file with error handling
        if (!file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX)) {
            error_log('Failed to write to log file: ' . $this->logFile);
        }
    }

    private function connectDB() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $this->db = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $this->log('Database connected successfully');
        } catch (PDOException $e) {
            $this->log('Database connection failed', ['error' => $e->getMessage()]);
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
            $this->log('Tables created/verified successfully');
        } catch (PDOException $e) {
            $this->log('Table creation failed', ['error' => $e->getMessage()]);
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

        $this->log('Handling request', ['method' => $method, 'user_id' => $userId]);

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
                $this->log('Method not allowed', ['method' => $method]);
                $this->sendError('Method not allowed', 405);
        }
    }

    private function getReflections($userId) {
        try {
            $this->log('Getting reflections for user', ['user_id' => $userId]);

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

            $this->log('Reflections retrieved successfully', ['count' => count($reflections)]);

            $this->sendResponse([
                'reflections' => $reflections,
                'lastCompleted' => $lastCompleted
            ]);

        } catch (PDOException $e) {
            $this->log('Failed to fetch reflections', ['error' => $e->getMessage(), 'user_id' => $userId]);
            $this->sendError('Failed to fetch reflections', 500);
        }
    }

    private function saveReflection($userId) {
        $input = json_decode(file_get_contents('php://input'), true);

        $this->log('Saving reflection', ['user_id' => $userId, 'input' => $input]);

        if (!$input) {
            $this->log('Invalid JSON input', ['raw_input' => file_get_contents('php://input')]);
            $this->sendError('Invalid JSON input', 400);
            return;
        }

        $required = ['date', 'dateString'];
        foreach ($required as $field) {
            if (!isset($input[$field])) {
                $this->log('Missing required field', ['field' => $field, 'input' => $input]);
                $this->sendError("Missing field: $field", 400);
                return;
            }
        }

        if (isset($_GET['id'])) {
            $this->updateReflection($userId, $_GET['id'], $input);
            return;
        }

        try {
            // Check for existing reflection on same date
            $stmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM reflections 
                WHERE user_id = ? AND date_string = ?
            ");
            $stmt->execute([$userId, $input['dateString']]);

            if ($stmt->fetchColumn() > 0) {
                $this->log('Reflection already exists for date', ['user_id' => $userId, 'date_string' => $input['dateString']]);
                $this->sendError('Reflection already exists for this date', 409);
                return;
            }

            $stmt = $this->db->prepare("
                INSERT INTO reflections (user_id, date, date_string, went_well, didnt_go_well, surprises, next_time)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([
                $userId,
                $input['date'],
                $input['dateString'],
                $input['wentWell'] ?? null,
                $input['didntGoWell'] ?? null,
                $input['surprises'] ?? null,
                $input['nextTime'] ?? null
            ]);

            if ($result) {
                $insertId = $this->db->lastInsertId();
                $this->log('Reflection saved successfully', ['id' => $insertId, 'user_id' => $userId]);

                $this->sendResponse([
                    'id' => $insertId,
                    'message' => 'Reflection saved successfully'
                ]);
            } else {
                $this->log('Failed to insert reflection', ['user_id' => $userId]);
                $this->sendError('Failed to save reflection', 500);
            }

        } catch (PDOException $e) {
            $this->log('Database error during save', ['error' => $e->getMessage(), 'user_id' => $userId]);
            $this->sendError('Failed to save reflection: ' . $e->getMessage(), 500);
        }
    }

    private function updateReflection($userId, $id, $input) {
        try {
            $this->log('Updating reflection', ['id' => $id, 'user_id' => $userId]);

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
                $this->log('Reflection not found for update', ['id' => $id, 'user_id' => $userId]);
                $this->sendError('Reflection not found or access denied', 404);
                return;
            }

            $this->log('Reflection updated successfully', ['id' => $id]);
            $this->sendResponse(['message' => 'Reflection updated successfully']);

        } catch (PDOException $e) {
            $this->log('Failed to update reflection', ['error' => $e->getMessage(), 'id' => $id]);
            $this->sendError('Failed to update reflection', 500);
        }
    }

    private function deleteReflection($userId) {
        if (isset($_GET['id'])) {
            try {
                $this->log('Deleting single reflection', ['id' => $_GET['id'], 'user_id' => $userId]);

                $stmt = $this->db->prepare("DELETE FROM reflections WHERE id = ? AND user_id = ?");
                $stmt->execute([$_GET['id'], $userId]);

                if ($stmt->rowCount() === 0) {
                    $this->log('Reflection not found for deletion', ['id' => $_GET['id'], 'user_id' => $userId]);
                    $this->sendError('Reflection not found or access denied', 404);
                    return;
                }

                $this->log('Reflection deleted successfully', ['id' => $_GET['id']]);
                $this->sendResponse(['message' => 'Reflection deleted successfully']);

            } catch (PDOException $e) {
                $this->log('Failed to delete reflection', ['error' => $e->getMessage(), 'id' => $_GET['id']]);
                $this->sendError('Failed to delete reflection', 500);
            }
        } else {
            try {
                $this->log('Deleting all reflections', ['user_id' => $userId]);

                $stmt = $this->db->prepare("DELETE FROM reflections WHERE user_id = ?");
                $stmt->execute([$userId]);

                $this->log('All reflections deleted', ['count' => $stmt->rowCount()]);
                $this->sendResponse(['message' => 'All reflections deleted successfully']);

            } catch (PDOException $e) {
                $this->log('Failed to delete all reflections', ['error' => $e->getMessage(), 'user_id' => $userId]);
                $this->sendError('Failed to delete reflections', 500);
            }
        }
    }

    private function sendResponse($data, $code = 200) {
        $this->log('Sending response', ['code' => $code, 'data' => $data]);
        http_response_code($code);
        echo json_encode($data);
        exit();
    }

    private function sendError($message, $code = 400) {
        $this->log('Sending error', ['code' => $code, 'message' => $message]);
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit();
    }
}

$api = new ReflectionAPI();
$api->handleRequest();
?>