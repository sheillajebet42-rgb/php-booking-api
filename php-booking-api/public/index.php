<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\BookingService;
use App\Database;
use App\Router;

$dsn = getenv('DB_DSN') ?: 'sqlite:' . __DIR__ . '/../booking.sqlite';
$pdo = Database::connect($dsn, getenv('DB_USER') ?: null, getenv('DB_PASS') ?: null);
Database::migrate($pdo);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = file_get_contents('php://input') ?: '';
$body = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid JSON body']);
    exit;
}

[$status, $payload] = (new Router(new BookingService($pdo)))->handle($_SERVER['REQUEST_METHOD'], $path, $body);
http_response_code($status);
if ($payload !== null) {
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
}
