<?php
// jwt_helper.php
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = getenv('JWT_SECRET') ?: 'enter password here'; // .env me JWT_SECRET add karo

function generateToken($user_id, $email, $name) {
    global $secret_key;
    $payload = [
        "iat" => time(),
        "exp" => time() + (60 * 60 * 24), // 24 hours
        "data" => [
            "user_id" => $user_id,
            "email" => $email,
            "name" => $name
        ]
    ];
    return JWT::encode($payload, $secret_key, 'HS256');
}

function verifyToken() {
    global $secret_key;
     $token = $_COOKIE['token'] ?? '';   // jwt with cookie 

    if (empty($token)) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Token missing"]);
        exit();
    }


    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        return $decoded->data;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Invalid or expired token"]);
        exit();
    }
}
?>