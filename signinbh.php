<?php

$allowedOrigins = ['http://localhost:5173', 'https://jwtbookhub.netlify.app'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Credentials: true"); // <-- ADD jwt cookie
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");



if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        "success" => false,
        "error" => "Invalid request method"
    ]);
    exit();
}

// Database config - .env (local) ya Render ke environment variables se load
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue; // comments/blank skip
        list($key, $value) = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

require_once __DIR__ . '/jwt_helper.php'; // jwt k liye

$servername = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: '';
$port = (int)(getenv('DB_PORT') ?: 3306);

// Connection
$conn = new mysqli($servername, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed"
    ]);
    exit();
}

$conn->set_charset("utf8mb4");

// Read JSON body
$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    echo json_encode([
        "success" => false,
        "error" => "Invalid JSON data"
    ]);
    $conn->close();
    exit();
}

$name = trim($input["name"] ?? "");
$email = trim($input["email"] ?? "");
$userPassword = trim($input["password"] ?? "");

// Common validation
if (empty($email) || empty($userPassword)) {
    echo json_encode([
        "success" => false,
        "error" => "Email and password are required"
    ]);
    $conn->close();
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        "success" => false,
        "error" => "Invalid email format"
    ]);
    $conn->close();
    exit();
}

// ==========================
// SIGNUP
// name sent = signup
// ==========================
if (!empty($name)) {
    if (strlen($name) < 2) {
        echo json_encode([
            "success" => false,
            "error" => "Name must be at least 2 characters"
        ]);
        $conn->close();
        exit();
    }

    if (strlen($userPassword) < 6) {
        echo json_encode([
            "success" => false,
            "error" => "Password must be at least 6 characters"
        ]);
        $conn->close();
        exit();
    }

    $checkQuery = "SELECT id FROM users WHERE email = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result->num_rows > 0) {
        echo json_encode([
            "success" => false,
            "error" => "Email already registered"
        ]);
        $checkStmt->close();
        $conn->close();
        exit();
    }

    $hashedPassword = password_hash($userPassword, PASSWORD_DEFAULT);

    $insertQuery = "INSERT INTO users (name, email, password, created_at) VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("sss", $name, $email, $hashedPassword);

    if ($stmt->execute()) {
        $token = generateToken($conn->insert_id, $email, $name);   // <-- ADD for jwt
        echo json_encode([
            "success" => true,
            "message" => "Account created successfully",
            "name" => $name,
            "email" => $email,
            "token" => $token  // added token in response(jwt)
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Registration failed"
        ]);
    }

    $stmt->close();
    $checkStmt->close();
    $conn->close();
    exit();
}


// ==========================
// SIGNIN
// name empty = signin
// ==========================
if (empty($userPassword)) {
    echo json_encode([
        "success" => false,
        "error" => "Email and password are required"
    ]);
    $conn->close();
    exit();
}

$query = "SELECT id, name, email, password FROM users WHERE email = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        "success" => false,
        "error" => "Invalid email or password"
    ]);
    $stmt->close();
    $conn->close();
    exit();
}

$user = $result->fetch_assoc();

if (password_verify($userPassword, $user["password"])) {
    $token = generateToken($user["id"], $user["email"], $user["name"]);  // <-- ADD jwt token generation
     $isProd = ($_SERVER['HTTP_HOST'] ?? '') !== 'localhost';   // <-- ADD jwt cookie secure flag

    setcookie("token", $token, [                                 // <-- ADD jwt cookie
        'expires' => time() + 86400,
        'path' => '/',
        'secure' => $isProd,
        'httponly' => true,
        'samesite' => $isProd ? 'None' : 'Lax'
    ]);
} else {
    echo json_encode([
        "success" => false,
        "error" => "Invalid email or password"
    ]);
}

$stmt->close();
$conn->close();
?>