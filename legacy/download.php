<?php
// download.php
// handles downloading a PDF to the user if it belongs to them.

require 'db.php';

// check user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// check for a valid PDF id was provided
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    die("Invalid file ID.");
}

$fileId = (int) $_GET['id'];
$userId = (int) $_SESSION['user_id'];

// look up the PDF and make sure it belongs to this user
$stmt = $mysqli->prepare("
    SELECT filename, mime_type, file_data
    FROM pdf_files
    WHERE id = ? AND user_id = ?
");

if (!$stmt) {
    die("Database error.");
}

$stmt->bind_param("ii", $fileId, $userId);
$stmt->execute();
$stmt->bind_result($filename, $mimeType, $fileData);

// if the PDF exists, send it to the browser
if ($stmt->fetch()) {
    $stmt->close();

    header("Content-Type: " . $mimeType);
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header("Content-Length: " . strlen($fileData));

    echo $fileData;
    exit;
}

// if nothing was found, fail
$stmt->close();
die("File not found or access denied.");
