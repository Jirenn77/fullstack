<?php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = [
    'http://localhost:3000', // frontend dev
];

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}

// Headers
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Start session
session_start();

// Handle Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=localhost;dbname=dbcom", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $input = json_decode(file_get_contents("php://input"), true);
    $action = $_GET['action'] ?? $input['action'] ?? '';
    $userId = $_GET['id'] ?? ($input['id'] ?? null);

    switch ($action) {
        case 'login':
            handleLogin($pdo);
            break;
        case 'add':
            handleAddUser($pdo, $input);
            break;
        case 'branches':
            handleGetBranches($pdo);
            break;
        case 'update':
            handleUpdateUser($pdo, $userId, $input);
            break;
        case 'get':
            handleGetUser($pdo, $userId);
            break;
        case 'users':
        default:
            if ($userId) {
                handleGetUser($pdo, $userId);
            } else {
                $users = getUsersData($pdo);
                echo json_encode($users);
            }
            break;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

// ===== LOGIN FUNCTION =====
function handleLogin($pdo)
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['email']) || empty($data['password'])) {
        echo json_encode(['error' => 'Missing parameters']);
        return;
    }

    $email = trim($data['email']);
    $password = trim($data['password']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $password === $user['password']) {
        $_SESSION['user_id'] = $user['user_id'];

        unset($user['password']);
        echo json_encode([
            'success' => true,
            'branch' => $user['branch'],
            'redirect' => '/home2',
            'role' => $user['role'],
            'user' => $user
        ]);
        return;
    }

    echo json_encode(['error' => 'Invalid email or password']);
}

// Fetch All Users
function getUsersData($pdo)
{
    $query = "SELECT 
                u.user_id AS id,
                u.name,
                u.username,
                u.branch,
                u.email,
                u.created_at,
                b.name AS branchName
              FROM users u
              LEFT JOIN branches b ON u.branch_id = b.id";
    $stmt = $pdo->query($query);

    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['status'] = "Active";
        $result[] = $row;
    }

    return $result;
}

// Get Single User
function handleGetUser($pdo, $userId)
{
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID is required']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT 
            u.user_id as id,
            u.name,
            u.username,
            u.email,
            b.name as branchName,
            u.created_at,
            b.id as branchId
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user['branch'] = $user['branchName'] ?? 'No branch assigned';
        $user['status'] = "Active";
        echo json_encode($user);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
    }
}

// Add New User
function handleAddUser($pdo, $data)
{
    try {
        if (empty($data['name']) || empty($data['username']) || empty($data['email']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Name, username, email, and password are required"
            ]);
            return;
        }

        $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $check->execute([$data['email']]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "Email already exists"
            ]);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (name, username, email, password, branch, branch_id, status, created_at)
            VALUES (:name, :username, :email, :password, :branch, :branch_id, :status, NOW())
        ");

        $stmt->execute([
            ':name' => $data['name'],
            ':username' => $data['username'],
            ':email' => $data['email'],
            ':password' => $data['password'],
            ':branch' => $data['branch'] ?? null,
            ':branch_id' => $data['branch_id'] ?? null,
            ':status' => $data['status'] ?? 'Active',
        ]);

        $newUserId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT 
                u.user_id AS id,
                u.name,
                u.username,
                u.email,
                u.branch,
                u.status,
                u.created_at,
                b.name AS branchName
            FROM users u
            LEFT JOIN branches b ON u.branch_id = b.id
            WHERE u.user_id = ?
        ");
        $stmt->execute([$newUserId]);
        $newUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$newUser) {
            throw new Exception("Failed to fetch newly created user");
        }

        http_response_code(201);
        echo json_encode([
            "success" => true,
            "message" => "User added successfully",
            "user" => $newUser
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Database error: " . $e->getMessage()
        ]);
    }
}

// Fetch all branches
function handleGetBranches($pdo)
{
    try {
        $stmt = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC");
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "branches" => $branches
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch branches: " . $e->getMessage()
        ]);
    }
}

// Update User
function handleUpdateUser($pdo, $id, $data)
{
    try {
        // Collect fields to update
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $params[':name'] = $data['name'];
        }

        if (isset($data['username'])) {
            $fields[] = 'username = :username';
            $params[':username'] = $data['username'];
        }

        if (isset($data['email'])) {
            $fields[] = 'email = :email';
            $params[':email'] = $data['email'];
        }

        if (isset($data['password']) && !empty($data['password'])) {
            // ⚠️ Password is not hashed since you mentioned no hashing needed
            $fields[] = 'password = :password';
            $params[':password'] = $data['password'];
        }

        if (isset($data['branch'])) {
            $fields[] = 'branch = :branch';
            $params[':branch'] = $data['branch'];
        }

        if (isset($data['branch_id'])) {
            $fields[] = 'branch_id = :branch_id';
            $params[':branch_id'] = $data['branch_id'];
        }

        if (isset($data['status'])) {
            $fields[] = 'status = :status';
            $params[':status'] = $data['status'];
        }

        if (isset($data['role'])) {
            $fields[] = 'role = :role';
            $params[':role'] = $data['role'];
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }

        // Build dynamic query
        $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE user_id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() >= 0) {
            echo json_encode(['message' => 'User updated successfully']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
