<?php
// delete_pdf.php
// this handles deleting a PDF that belongs to the user that's logged in

require 'db.php';

// check user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$loggedInUserId = (int) $_SESSION['user_id'];
$message = "";

// only allow POST requests
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // check PDF id exists
    if (isset($_POST["id"]) && ctype_digit($_POST["id"])) {

        $pdfId = (int) $_POST["id"];

        // delete only if the PDF belongs to the user
        $stmt = $mysqli->prepare("
            DELETE FROM pdf_files
            WHERE id = ? AND user_id = ?
        ");

        if ($stmt) {
            $stmt->bind_param("ii", $pdfId, $loggedInUserId);

            if ($stmt->execute()) {
                $message = "PDF deleted.";
            } else {
                $message = "Error deleting PDF.";
            }

            $stmt->close();
        } else {
            $message = "Database error.";
        }

    } else {
        $message = "Invalid PDF id.";
    }
} else {
    $message = "Invalid request.";
}

// send message back to main page
header("Location: AiPDFsearchPage.php?msg=" . urlencode($message));
exit;
