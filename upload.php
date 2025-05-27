<?php
/* ---------- CONFIG ---------- */
$DB_HOST = "sql210.infinityfree.com";
$DB_NAME = "if0_39052882_resqdata";
$DB_USER = "if0_39052882";
$DB_PASS = "resqalert2025";

$UPLOAD_DIR = __DIR__ . "/uploads/";
$BASE_URL   = "https://resqalert.great-site.net/uploads/";
$LOG_FILE   = __DIR__ . "/upload_debug.log";

/* ---------- HELPERS ---------- */
function log_msg(string $message): void {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date("[Y-m-d H:i:s] ") . $message . PHP_EOL, FILE_APPEND);
}

function json_exit(array $response, int $code = 200): void {
    http_response_code($code);
    header("Content-Type: application/json");
    echo json_encode($response);
    exit;
}

/* ---------- START ---------- */
log_msg("=== Request BEGIN ===");
log_msg("Method: {$_SERVER['REQUEST_METHOD']}");
log_msg("IP: {$_SERVER['REMOTE_ADDR']}");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_msg("Invalid method");
    json_exit(["success" => false, "message" => "POST required"], 405);
}

/* Validate required fields */
$required = ['accident_type', 'latitude', 'longitude'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        log_msg("Missing field: $field");
        json_exit(["success" => false, "message" => "Missing $field"], 400);
    }
}

/* Validate uploaded photo */
if (
    !isset($_FILES['photo']) ||
    $_FILES['photo']['error'] !== UPLOAD_ERR_OK ||
    !is_uploaded_file($_FILES['photo']['tmp_name'])
) {
    log_msg("Photo upload error");
    json_exit(["success" => false, "message" => "Photo file is missing or invalid"], 400);
}

/* Ensure the uploaded file is an image */
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($_FILES['photo']['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];

if (!array_key_exists($mime, $allowed)) {
    log_msg("Invalid image type: $mime");
    json_exit(["success" => false, "message" => "Only JPG, PNG, and GIF images allowed"], 400);
}

/* Create upload directory if it doesn't exist */
if (!is_dir($UPLOAD_DIR) && !mkdir($UPLOAD_DIR, 0755, true)) {
    log_msg("Failed to create upload directory");
    json_exit(["success" => false, "message" => "Server storage error"], 500);
}

/* Move the uploaded file */
$extension  = $allowed[$mime];
$basename   = time() . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
$targetPath = $UPLOAD_DIR . $basename;

if (!move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
    log_msg("Failed to move uploaded file");
    json_exit(["success" => false, "message" => "Upload failed"], 500);
}

$publicPath = $BASE_URL . $basename;
log_msg("File saved to $targetPath");

/* ---------- DB INSERT ---------- */
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_error) {
    log_msg("DB connect error: " . $mysqli->connect_error);
    json_exit(["success" => false, "message" => "Database connection failed"], 500);
}

$stmt = $mysqli->prepare("
    INSERT INTO accident_reports (accident_type, latitude, longitude, image_path)
    VALUES (?, ?, ?, ?)
");

if (!$stmt) {
    log_msg("DB prepare failed: " . $mysqli->error);
    json_exit(["success" => false, "message" => "Database error"], 500);
}

$accidentType = trim($_POST['accident_type']);
$lat          = floatval($_POST['latitude']);
$lng          = floatval($_POST['longitude']);

$stmt->bind_param("sdds", $accidentType, $lat, $lng, $publicPath);

if ($stmt->execute()) {
    log_msg("DB insert OK, ID = {$stmt->insert_id}");
    json_exit(["success" => true, "message" => "Report stored successfully", "id" => $stmt->insert_id]);
} else {
    log_msg("DB insert error: " . $stmt->error);
    json_exit(["success" => false, "message" => "Failed to store report"], 500);
}

$stmt->close();
$mysqli->close();
?>
