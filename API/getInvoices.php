<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=localhost;dbname=dbcom", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Handle CORS preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    // ✅ Handle invoice creation
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);

        $invoiceNumber = $data['invoice_number'];
        $customerId = $data['customer_id'];
        $serviceId = $data['service_id'];
        $totalPrice = $data['total_price'];
        $status = $data['status'];

        $stmt = $pdo->prepare("
    INSERT INTO invoices (invoice_number, customer_id, service_id, quantity, total_price, status, notes)
    VALUES (:invoice_number, :customer_id, :service_id, :quantity, :total_price, :status, :notes)
");
        $stmt->execute([
            ':invoice_number' => $invoiceNumber,
            ':customer_id' => $customerId,
            ':service_id' => $serviceId,
            ':quantity' => $quantity,
            ':total_price' => $totalPrice,
            ':status' => $status,
            ':notes' => $notes
        ]);


        echo json_encode(['success' => true, 'message' => 'Invoice added successfully']);
        exit;
    }

    // ✅ Handle invoice fetching (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $branch = $_GET['branch'] ?? null;

    $query = "
        SELECT 
            i.invoice_number,
            i.invoice_date,
            i.total_price,
            i.status AS payment_status,
            c.name AS customer_name,
            s.name AS service_name,
            t.total_amount AS final_total,
            t.employee_name
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        JOIN services s ON i.service_id = s.service_id
        JOIN transactions t ON i.invoice_number = t.invoice_number
    ";

    // optional filter by branch (only if your transactions table has one)
    if ($branch && strtolower($branch) !== 'all') {
        $query .= " WHERE t.branch = :branch"; // ✅ Adjust column name if needed
    }

    $query .= " ORDER BY i.invoice_date DESC, i.invoice_id DESC";

    $stmt = $pdo->prepare($query);

    if ($branch && strtolower($branch) !== 'all') {
        $stmt->bindParam(':branch', $branch);
    }

    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $invoices = [];

    foreach ($result as $row) {
        $invNumber = $row['invoice_number'];

        // check if invoice exists already
        $index = array_search($invNumber, array_column($invoices, 'invoiceNumber'));

        if ($index === false) {
            $invoices[] = [
                'invoiceNumber' => $invNumber,
                'name' => $row['customer_name'],
                'dateIssued' => $row['invoice_date'],
                'totalAmount' => isset($row['final_total'])
                    ? "₱" . number_format($row['final_total'], 2)
                    : "₱" . number_format($row['total_price'], 2),
                'paymentStatus' => $row['payment_status'],
                'handledBy' => $row['employee_name'] ?? "Staff",
                'branch' => $branch ?? "Main",
                'services' => []
            ];
            $index = array_key_last($invoices);
        }

        $invoices[$index]['services'][] = [
            'name' => $row['service_name'],
            'price' => "₱" . number_format($row['total_price'], 2)
        ];
    }

    echo json_encode($invoices);
    exit;
}


} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
