<?php
// checkout.php - Save billing and order details to database

// CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration - .env (local) ya Render ke environment variables se load
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

$host = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASS') ?: '';
$database = getenv('DB_NAME') ?: '';

// Database connection
$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed"
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $user_email = $_GET['email'] ?? '';

    if (empty($user_email)) {
        echo json_encode([
            "success" => false,
            "error" => "Email is required"
        ]);
        exit();
    }
    // my order update section
    $query = "SELECT * FROM billing_details WHERE user_email = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = [
            "_id" => $row['id'],
            "orderId" => $row['order_id'],
            "phone" => $row['phone'],
            "address" => $row['address'],
            "city" => $row['city'],
            "pincode" => $row['pincode'],
            "status" => $row['order_status'],
            "totalAmount" => $row['total_amount'],
            "createdAt" => $row['created_at'],
            "items" => json_decode($row['order_items'])
        ];
    }

    echo json_encode([
        "success" => true,
        "orders" => $orders
    ]);

    $stmt->close();
    $conn->close();
    exit();
}

// POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Extract data
    $order_id = $input['order_id'] ?? 0;
    $user_email = $input['user_email'] ?? '';
    $full_name = trim($input['full_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $address = trim($input['address'] ?? '');
    $city = trim($input['city'] ?? '');
    $pincode = trim($input['pincode'] ?? '');
    $payment_method = $input['payment_method'] ?? 'cod';
    $subtotal = floatval($input['subtotal'] ?? 0);
    $tax = floatval($input['tax'] ?? 0);
    $total_amount = floatval($input['total_amount'] ?? 0);
    $order_items = json_encode($input['order_items'] ?? []);
    
    // Validation
    if (empty($order_id) || empty($full_name) || empty($email) || 
        empty($phone) || empty($address) || empty($city) || empty($pincode)) {
        echo json_encode([
            "success" => false,
            "error" => "All fields are required"
        ]);
        exit();
    }
    
    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid email format"
        ]);
        exit();
    }
    
    // Phone validation (10 digits)
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid phone number. Must be 10 digits."
        ]);
        exit();
    }
    
    // Pincode validation (6 digits)
    if (!preg_match('/^[0-9]{6}$/', $pincode)) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid pincode. Must be 6 digits."
        ]);
        exit();
    }
    
    // Check if order_id already exists
    $check_query = "SELECT id FROM billing_details WHERE order_id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode([
            "success" => false,
            "error" => "Order ID already exists"
        ]);
        exit();
    }
    
    // Insert billing details
    $insert_query = "INSERT INTO billing_details 
        (order_id, user_email, full_name, email, phone, address, city, pincode, 
         payment_method, subtotal, tax, total_amount, order_items, order_status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed', NOW())";
    
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param(
        "issssssssddds",
        $order_id,
        $user_email,
        $full_name,
        $email,
        $phone,
        $address,
        $city,
        $pincode,
        $payment_method,
        $subtotal,
        $tax,
        $total_amount,
        $order_items
    );
    
    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "Order placed successfully",
            "order_id" => $order_id,
            "total" => $total_amount
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Failed to save order: " . $stmt->error
        ]);
    }
    
    $stmt->close();
} else {
    echo json_encode([
        "success" => false,
        "error" => "Invalid request method"
    ]);
}

$conn->close();
?>