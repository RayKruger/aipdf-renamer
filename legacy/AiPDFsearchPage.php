<?php
// AiPDFsearchPage.php
// maing page to handle most action logic: uploading, delete, etc.

//import databse helper for creating database
require 'db.php'; 

//Embedding vector helper
require 'embeddings_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// check user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

// store user info for session
$userId   = (int) $_SESSION["user_id"];
$username = isset($_SESSION["username"]) ? $_SESSION["username"] : "";

$message = "";

if (isset($_GET["msg"]) && $_GET["msg"] !== "") {
    $message = $_GET["msg"];
}

// values for search and results
$searchTerm = "";
$searchRows = [];
$allRows    = [];
$topK       = 10; // default for vector search

// vairable forsearch mode: "string" or "vector"
$searchMode = "string";

// prefer POST (search form), but keep GET as fallback
if (isset($_POST["search_mode"]) && in_array($_POST["search_mode"], ["string", "vector"], true)) {
    $searchMode = $_POST["search_mode"];
    $_SESSION["search_mode"] = $searchMode;
} elseif (isset($_GET["search_mode"]) && in_array($_GET["search_mode"], ["string", "vector"], true)) {
    $searchMode = $_GET["search_mode"];
    $_SESSION["search_mode"] = $searchMode;
} elseif (isset($_SESSION["search_mode"])) {
    $searchMode = $_SESSION["search_mode"];
}

// read search term (prefer POST, fallback GET)
if (isset($_POST["search_term"])) {
    $searchTerm = trim($_POST["search_term"]);
} elseif (isset($_GET["search_term"])) {
    $searchTerm = trim($_GET["search_term"]);
}

// read Top-K (prefer POST, fallback GET)
if (isset($_POST["top_k"])) {
    if ($_POST["top_k"] === "custom" && !empty($_POST["custom_top_k"])) {
        $topK = max(1, (int)$_POST["custom_top_k"]);
    } else {
        $topK = max(1, (int)$_POST["top_k"]);
    }
} elseif (isset($_GET["top_k"])) {
    if ($_GET["top_k"] === "custom" && !empty($_GET["custom_top_k"])) {
        $topK = max(1, (int)$_GET["custom_top_k"]);
    } else {
        $topK = max(1, (int)$_GET["top_k"]);
    }
}

// handle license upload (from the API config box form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['license'])) {
    if ($_FILES['license']['error'] === UPLOAD_ERR_OK) {
        $contents = file_get_contents($_FILES['license']['tmp_name']);

        // simple .env-style parsing
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if ($key === 'API_BASE_URL') {
                $_SESSION['api_base'] = $value;
            } elseif ($key === 'API_KEY') {
                $_SESSION['api_key'] = $value;
            } elseif ($key === 'MODEL') {
                $_SESSION['model'] = $value;
            }
        }
        $message = $message ? $message . ' | License loaded.' : 'License loaded.';
    } else {
        $message = $message ? $message . ' | Failed to load license.' : 'Failed to load license.';
    }
}

// handle bulk PDF upload
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["pdf_files"])) {

    // if you don't select anything
    if (empty($_FILES["pdf_files"]["name"][0])) {
        $message = "Error: Please choose at least one PDF file.";
    } else {
        $totalFiles     = count($_FILES["pdf_files"]["name"]);
        $uploadedCount  = 0;
        $skippedCount   = 0; // non-PDF files
        $errorCount     = 0; // failed uploads or DB errors

        // loop through the selected files
        for ($i = 0; $i < $totalFiles; $i++) {

            $error    = $_FILES["pdf_files"]["error"][$i];
            $tmpPath  = $_FILES["pdf_files"]["tmp_name"][$i];
            $fileName = $_FILES["pdf_files"]["name"][$i];
            $fileType = $_FILES["pdf_files"]["type"][$i];

            // skip files with error
            if ($error !== UPLOAD_ERR_OK) {
                $errorCount++;
                continue;
            }

            // only allow .pdf extension
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($extension !== "pdf") {
                $skippedCount++;
                continue;
            }

            $fileData = file_get_contents($tmpPath);

            // insert pdf into database
            $stmt = $mysqli->prepare("
                INSERT INTO pdf_files (user_id, filename, mime_type, file_data)
                VALUES (?, ?, ?, ?)
            ");

            if (!$stmt) {
                $errorCount++;
                continue;
            }

            $null = NULL; // placeholder for blob
            $stmt->bind_param("issb", $userId, $fileName, $fileType, $null);
            $stmt->send_long_data(3, $fileData);

            if ($stmt->execute()) {
                $uploadedCount++;
            } else {
                $errorCount++;
            }

            $stmt->close();
        }

        // simple messages to user after uploading
        $messageParts = [];
        $messageParts[] = "Uploaded: $uploadedCount";

        if ($skippedCount > 0) {
            $messageParts[] = "Skipped non-PDFs: $skippedCount";
        }
        if ($errorCount > 0) {
            $messageParts[] = "Errors: $errorCount";
        }

        $message = implode(" | ", $messageParts);
    }
}

// handle searching for pdf (exact match before ".pdf", for this user. vector search adding later)
// grab all the pdfs assigned to user 
$stmt = $mysqli->prepare("
    SELECT id, filename, created_at
    FROM pdf_files
    WHERE user_id = ?
    ORDER BY id DESC
");

// retrieves the result from database, converts to array
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $resultAll = $stmt->get_result();

    if ($resultAll) {
        $allRows = $resultAll->fetch_all(MYSQLI_ASSOC);
    }

    $stmt->close();
}

// handle searching for pdf
if ($searchTerm !== "") {

    if ($searchMode === "string") {
        // exact string match search: name before ".pdf"
        $stmt = $mysqli->prepare("
            SELECT id, filename, created_at
            FROM pdf_files
            WHERE user_id = ?
              AND REPLACE(filename, '.pdf', '') = ?
            ORDER BY id DESC
        ");
        if ($stmt) {
            $stmt->bind_param("is", $userId, $searchTerm);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result) {
                $searchRows = $result->fetch_all(MYSQLI_ASSOC);
            }

            $stmt->close();
        }

    } elseif ($searchMode === "vector") {
        if (!has_embedding_config()) {
            $message = $message
                ? $message . ' | AI vector search not configured. Load a license file first.'
                : 'AI vector search not configured. Load a license file first.';
            $searchRows = [];
        } else {
            $searchRows = semantic_search_filenames($searchTerm, $allRows, $topK);
        }
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your PDFs</title>

    <!-- downlaod tailwind css -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(to bottom, #0f172a, #1e293b, #000);
        }
    </style>
</head>


<body class="min-h-screen text-slate-100">
<div class="max-w-5xl mx-auto px-4 py-10">

    <header class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight">
                <span class="text-lime-400">AiPDF</span> Library&Search
            </h1>
            <p class="text-slate-300 mt-1">
                AI-powered PDF library and deep LLM embedding vector search
            </p>   
        </div>

        <!-- navigate buttons -->
        <div class="flex items-center gap-4">

           
            <a href="logout.php"
               class="text-sm text-slate-300 hover:text-cyan-300 underline">
                Log out
            </a>

            <a href="index.php"
               class="flex items-center gap-2 px-3 py-1.5
                      rounded-md bg-slate-800/80 hover:bg-slate-800/60
                      border border-slate-700/60 hover:border-slate-600
                      transition shadow-sm">

                <span class="flex items-baseline gap-1 text-3xl font-bold tracking-tight">
                    <span class="text-2xl text-gray-300 font-medium">Switch To:&nbsp;</span>
                    <span class="text-lime-400">AiPDF</span>
                    <span class="text-white">Renamer</span>
                </span>

                <span class="text-lime-400 text-lg">→</span>
            </a>
        </div>
    </header>

    <!-- API CONFIGURATION BOX -->
    <div class="bg-slate-900/70 border border-slate-700 rounded-2xl p-6 space-y-4 mb-8">

        <h2 class="text-xl font-semibold text-white">API Configuration</h2>

        <div class="grid md:grid-cols-[1fr_1fr_1fr_auto] gap-4 items-end">
            
            <!-- Base URL -->
            <div>
                <label class="text-xs text-gray-300">Base URL endpoint</label>
                <input type="text"
                    value="<?php echo htmlspecialchars($_SESSION['api_base'] ?? ''); ?>"
                    readonly
                    class="w-full mt-1 px-3 py-2 text-sm rounded bg-gray-900/60 border border-slate-700 text-gray-300">
            </div>

            <!-- API KEY -->
            <div>
                <label class="text-xs text-gray-300">API Key</label>
                <input type="password"
                    value="<?php echo htmlspecialchars($_SESSION['api_key'] ?? ''); ?>"
                    readonly
                    class="w-full mt-1 px-3 py-2 text-sm rounded bg-gray-900/60 border border-slate-700 text-gray-300">
            </div>

            <!-- Model -->
            <div>
                <label class="text-xs text-gray-300">Model (Embedding-capable)</label>
                <input type="text"
                    value="<?php echo htmlspecialchars($_SESSION['model'] ?? ''); ?>"
                    readonly
                    class="w-full mt-1 px-3 py-2 text-sm rounded bg-gray-900/60 border border-slate-700 text-gray-300">
            </div>

            <!-- License upload + tooltip -->
            <div class="flex items-center gap-2 pb-[2px]">

                <span class="text-gray-400 text-xs whitespace-nowrap">or</span>

                <form method="POST" enctype="multipart/form-data">
                    <label for="license"
                        class="cursor-pointer bg-cyan-400 text-gray-900 font-semibold px-4 py-2 rounded-lg shadow hover:bg-cyan-300 transition whitespace-nowrap">
                        Load License File
                    </label>
                    <input id="license" type="file" name="license" accept=".txt" class="hidden"
                        onchange="this.form.submit()">
                </form>

                <!-- tooltip -->
                <div class="relative group flex items-center">
                    <span class="text-gray-400 cursor-pointer text-sm font-bold">?</span>

                    <div class="absolute left-full ml-3 top-1/2 -translate-y-1/2 
                                bg-gray-900/90 text-gray-200 text-xs p-3 rounded-md 
                                border border-gray-700 shadow-lg opacity-0 group-hover:opacity-100 
                                transition pointer-events-none z-40 text-[13px] w-64">
                        
                        <span class="font-semibold text-gray-100 underline">
                            Example license file format:
                        </span><br><br>

                        <code class="text-cyan-300">
                            API_BASE_URL=https://api.openai.com/v1/embeddings<br>
                            API_KEY=sk-xxyourkeyhere<br>
                            MODEL=text-embedding-3-large
                        </code>
                    </div>
                </div>
            </div>

        </div>
    </div>



    <!-- search tab area -->
    <div class="bg-slate-900/80 border border-slate-700 rounded-2xl shadow-xl p-6 sm:p-8 space-y-8 mb-8">

        <!-- flash message -->
        <?php if ($message !== ""): ?>
            <div class="rounded-lg border border-emerald-500/60 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- ==========================
            [POST] Upload PDFs
            ========================== -->
        <section>
            <h2 class="text-xl font-semibold text-slate-100 mb-3">
                Upload Your PDFs,
                <span class="font-bold text-white">
                    <?php echo htmlspecialchars($username); ?>
                </span>
            </h2>

            <!-- Upload form: POST to this same page (AiPDFsearchPage.php) -->
            <form action="AiPDFsearchPage.php"
                method="post"
                enctype="multipart/form-data"
                class="space-y-3 sm:flex sm:items-center sm:space-y-0 sm:space-x-4">

                <div>
                    <label for="pdf_files" class="block text-sm text-slate-300 mb-1">
                        Choose <span class="text-lime-400 font-semibold">one or more</span> files (single or bulk)
                    </label>

                    <input type="file"
                        name="pdf_files[]"
                        id="pdf_files"
                        accept="application/pdf"
                        multiple
                        class="block w-full text-sm text-slate-200
                                file:mr-3 file:py-1.5 file:px-3 file:rounded-md
                                file:border-0 file:text-sm file:font-semibold
                                file:bg-cyan-500 file:text-slate-900
                                hover:file:bg-cyan-400">
                </div>

                <button type="submit"
                        class="inline-flex items-center px-4 py-2.5 rounded-lg bg-cyan-500 text-slate-900 font-semibold
                            shadow hover:bg-cyan-400 transition">
                    Upload PDF(s) to Your Account
                </button>
            </form>
        </section>

        <hr class="border-slate-700/80">

        <!-- ==========================
            [POST] Search PDFs
            ========================== -->
        <section>
            <h2 class="text-xl font-semibold text-slate-100 mb-3">
                Search Your PDFs
            </h2>

            <!-- Search form: POST to this same page (AiPDFsearchPage.php) -->
            <form action="AiPDFsearchPage.php"
                method="post"
                class="space-y-3">

                <!-- Radio buttons + Top-K on same row -->
                <div class="mt-1 flex flex-col sm:flex-row sm:items-center gap-6">

                    <!-- Normal String Search -->
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="radio"
                            name="search_mode"
                            value="string"
                            class="peer hidden"
                            <?php if ($searchMode === "string") echo "checked"; ?>>
                        <div class="w-4 h-4 border border-slate-500 rounded-sm
                                    peer-checked:bg-lime-400 peer-checked:border-lime-400
                                    transition"></div>
                        <span class="text-slate-300 text-sm">Normal String Search</span>
                    </label>

                    <!-- AI Vector Search + Top-K controls -->
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="radio"
                                name="search_mode"
                                value="vector"
                                class="peer hidden"
                                <?php if ($searchMode === "vector") echo "checked"; ?>>
                            <div class="w-4 h-4 border border-slate-500 rounded-sm
                                        peer-checked:bg-lime-400 peer-checked:border-lime-400
                                        transition"></div>

                            <span class="text-sm">
                                <span class="text-lime-400 font-semibold">AI-Boosted</span>
                                <span class="text-slate-300">Embedding Vector Search</span>
                            </span>
                        </label>

                        <!-- Top-K now directly beside AI option -->
                        <div class="flex items-center gap-2 ml-2">
                            <label for="top_k" class="text-sm text-slate-300">Top-K:</label>

                            <select id="top_k" name="top_k"
                                    class="rounded-lg bg-slate-800/70 border border-slate-600 px-2 py-1 text-sm text-slate-300
                                        focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                                <option value="1"<?php if ($topK == 1) echo ' selected'; ?>>1</option>
                                <option value="2"<?php if ($topK == 2) echo ' selected'; ?>>2</option>
                                <option value="3"<?php if ($topK == 3) echo ' selected'; ?>>3</option>
                                <option value="4"<?php if ($topK == 4) echo ' selected'; ?>>4</option>
                                <option value="5"<?php if ($topK == 5) echo ' selected'; ?>>5</option>
                                <option value="custom">Custom</option>
                            </select>

                            <input type="number"
                                id="custom_top_k"
                                name="custom_top_k"
                                min="1" max="100"
                                placeholder="Custom"
                                class="w-20 rounded-lg bg-slate-800/70 border border-slate-600 px-2 py-1 text-sm text-slate-300
                                        focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 hidden" />
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 items-start">
                    <div class="flex-1 w-full">
                        <label for="search_term" class="block text-sm text-slate-300 mt-1">
                            Name (without .pdf)
                        </label>
                        <input type="text"
                            id="search_term"
                            name="search_term"
                            value="<?php echo htmlspecialchars($searchTerm); ?>"
                            class="w-full rounded-lg bg-slate-800/70 border border-slate-600 px-3 py-2 text-sm
                                    focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 mt-2">
                    </div>

                    <div class="flex items-center gap-3">

                        

                        <input type="number"
                            id="custom_top_k"
                            name="custom_top_k"
                            min="1"
                            max="100"
                            placeholder="Custom"
                            class="w-20 rounded-lg bg-slate-800/70 border border-slate-600 px-3 py-2 text-sm text-slate-300 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 hidden" />

                        <button type="submit"
                                class="mt-1 sm:mt-6 inline-flex items-center px-4 py-2.5 rounded-lg bg-blue-500 text-slate-900
                                    font-semibold shadow hover:bg-blue-400 transition">
                            Search
                        </button>
                    </div>
                </div>

                <script>
                    const topKSelect = document.getElementById('top_k');
                    const customTopKInput = document.getElementById('custom_top_k');

                    function syncCustomVisibility() {
                        if (topKSelect.value === 'custom') {
                            customTopKInput.style.display = 'inline-block';
                        } else {
                            customTopKInput.style.display = 'none';
                            customTopKInput.value = '';
                        }
                    }

                    topKSelect.addEventListener('change', syncCustomVisibility);
                    // run once on load
                    syncCustomVisibility();
                </script>
            </form>

            <!-- show results for search -->
            <?php if ($searchTerm !== ""): ?>
                <h3 class="mt-4 text-sm text-slate-300">
                    Search results for:
                    <span class="font-mono text-slate-100">
                        "<?php echo htmlspecialchars($searchTerm); ?>"
                    </span>
                    <?php if ($searchMode === "vector"): ?>
                        <span class="ml-2 text-xs text-lime-400">(AI vector search)</span>
                    <?php else: ?>
                        <span class="ml-2 text-xs text-slate-400">(string match)</span>
                    <?php endif; ?>
                </h3>

                <?php if (count($searchRows) === 0): ?>
                    <p class="mt-2 text-sm text-slate-400">
                        No matching PDFs found in your account.
                    </p>
                <?php else: ?>
                    <div class="mt-3 overflow-x-auto rounded-lg border border-slate-700/80">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-800/80 text-slate-200">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">ID</th>
                                <th class="px-3 py-2 text-left font-semibold">Filename</th>
                                <th class="px-3 py-2 text-left font-semibold">
                                    Uploaded At
                                    <?php if ($searchMode === "vector"): ?>
                                        <span class="text-[10px] text-slate-400 ml-1">(sorted by similarity)</span>
                                    <?php endif; ?>
                                </th>
                                <th class="px-3 py-2 text-center font-semibold">Download</th>
                                <th class="px-3 py-2 text-center font-semibold">Delete</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                            <?php foreach ($searchRows as $row): ?>
                                <tr>
                                    <td class="px-3 py-2 text-slate-300">
                                        <?php echo htmlspecialchars($row["id"]); ?>
                                    </td>
                                    <td class="px-3 py-2 text-slate-100">
                                        <?php echo htmlspecialchars($row["filename"]); ?>
                                        <?php if (isset($row["similarity"])): ?>
                                            <span class="ml-2 text-[11px] text-lime-400">
                                                (sim: <?php echo number_format($row["similarity"], 3); ?>)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-slate-300">
                                        <?php echo htmlspecialchars($row["created_at"]); ?>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <!-- [GET] Download -->
                                        <form method="get" action="download.php">
                                            <input type="hidden" name="id"
                                                value="<?php echo htmlspecialchars($row["id"]); ?>">
                                            <button type="submit"
                                                    class="px-3 py-1.5 rounded-md bg-emerald-500 text-slate-900 text-xs font-semibold
                                                        hover:bg-emerald-400 transition">
                                                Download
                                            </button>
                                        </form>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <!-- [POST] Delete -->
                                        <form method="post" action="delete_pdf.php"
                                            onsubmit="return confirm('Delete this PDF permanently?');">
                                            <input type="hidden" name="id"
                                                value="<?php echo htmlspecialchars($row["id"]); ?>">
                                            <button type="submit"
                                                    class="px-3 py-1.5 rounded-md bg-rose-500 text-slate-900 text-xs font-semibold
                                                        hover:bg-rose-400 transition">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <hr class="border-slate-700/80">

        <!-- ==========================
            All PDFs table (no form here)
            ========================== -->
        <section>
            <h2 class="text-xl font-semibold text-slate-100 mb-3">
                All PDFs in Your Account
            </h2>

            <?php if (count($allRows) === 0): ?>
                <p class="text-sm text-slate-400">
                    You haven't uploaded any PDFs yet.
                </p>
            <?php else: ?>
                <div class="overflow-x-auto rounded-lg border border-slate-700/80">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-800/80 text-slate-200">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">ID</th>
                            <th class="px-3 py-2 text-left font-semibold">Filename</th>
                            <th class="px-3 py-2 text-left font-semibold">Uploaded At</th>
                            <th class="px-3 py-2 text-center font-semibold">Download</th>
                            <th class="px-3 py-2 text-center font-semibold">Delete</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                        <?php foreach ($allRows as $row): ?>
                            <tr>
                                <td class="px-3 py-2 text-slate-300">
                                    <?php echo htmlspecialchars($row["id"]); ?>
                                </td>
                                <td class="px-3 py-2 text-slate-100">
                                    <?php echo htmlspecialchars($row["filename"]); ?>
                                </td>
                                <td class="px-3 py-2 text-slate-300">
                                    <?php echo htmlspecialchars($row["created_at"]); ?>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <!-- [GET] Download -->
                                    <form method="get" action="download.php">
                                        <input type="hidden" name="id"
                                            value="<?php echo htmlspecialchars($row["id"]); ?>">
                                        <button type="submit"
                                                class="px-3 py-1.5 rounded-md bg-emerald-500 text-slate-900 text-xs font-semibold
                                                    hover:bg-emerald-400 transition">
                                            Download
                                        </button>
                                    </form>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <!-- [POST] Delete -->
                                    <form method="post" action="delete_pdf.php"
                                        onsubmit="return confirm('Delete this PDF permanently?');">
                                        <input type="hidden" name="id"
                                            value="<?php echo htmlspecialchars($row["id"]); ?>">
                                        <button type="submit"
                                                class="px-3 py-1.5 rounded-md bg-rose-500 text-slate-900 text-xs font-semibold
                                                    hover:bg-rose-400 transition">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    </div>

</div>
</body>
</html>
