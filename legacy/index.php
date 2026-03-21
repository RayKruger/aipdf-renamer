<?php

///////////////////////////////////////////////////////////////////////////////////////////////////////
//PHP vairbale setup section
////////////////////////////////////////////////////////////////////////////////////////////////////
session_start();

//Reset session
if (isset($_GET['reset'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), "", time() - 3600, "/");
    header("Location: index.php?v=" . time());
    exit;
}


// PhP helper funcctions that gets called later
function log_append($msg) { $_SESSION['raw_log'][] = $msg; }
if (!isset($_SESSION['raw_log'])) $_SESSION['raw_log'] = [];

//Strisp ans sanatises text so safe to be a filename
function normalize_token($s, $max=180) {
    $s = trim((string)$s);
    $s = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    $s = preg_replace('/\s+/', '_', $s);
    $s = preg_replace('/[^A-Za-z0-9._-]/','_',$s);
    $s = preg_replace('/_+$/','',$s);
    return substr($s,0,$max);
}

// Build new filename (core) from metadata 
function build_filename_stem($f){
    $p=array_filter([
        normalize_token($f['year']??''),
        normalize_token($f['lead_author_surname']??''),
        normalize_token($f['title_short']??'',60),
        normalize_token($f['journal']??'')
    ]);
    $b=implode('_',$p);
    if($b==='')$b='renamed';
    return $b;
}


// Final filename builder; can use override (pdf_filename) 
function build_filename($f, $override_name = null){
    if ($override_name !== null && trim($override_name) !== '') {
        $name = trim($override_name);
    } else {
        $name = build_filename_stem($f);
    }
    // Strip any trailing .pdf / .PDF if user typed it
    $name = preg_replace('/\.pdf$/i','',$name);
    // Always force .pdf
    return $name . '.pdf';
}


//takes a user-provided API base URL and ensures it ends with /chat/completions
function resolve_completions_url($b){
    $b=rtrim($b??'','/');
    if($b===''||stripos($b,'/chat/completions')!==false)return $b;
    return $b.'/chat/completions';
}


// Load session state api values
$api_base=$_SESSION['api_base']??'';
$api_key =$_SESSION['api_key'] ??'';
$model   =$_SESSION['model']   ??'';
$raw_output = '';


//Hold latest extracted fields (persist across requests) 
$extracted = $_SESSION['extracted'] ?? [
    'year' => '',
    'lead_author_surname' => '',
    'title_short' => '',
    'journal' => '',
    'title_full' => '',
    'pdf_filename' => '',
];

///////////////////////////////////////////////////////////////////////////////////////////////////////
//PHP processing
////////////////////////////////////////////////////////////////////////////////////////////////////
// Main PHP POST handeler (runs when this page receives ANY POST)
if($_SERVER['REQUEST_METHOD']==='POST'){
    //Now looka t the specfic posts names for routing
    // process post name "license" [will be used process the licance texts]


    //Persist metadata fields even before extraction
    foreach (['year','lead_author_surname','title_short','journal','title_full','pdf_filename'] as $k) {
        if (isset($_POST[$k])) {
            $extracted[$k] = $_POST[$k];
        }
    }
    $_SESSION['extracted'] = $extracted;

    //Persist preview page (base64 image) 
    if (isset($_POST['first_page_b64']) && $_POST['first_page_b64'] !== '') {
        $_SESSION['first_page_b64'] = $_POST['first_page_b64'];
    }

    // Persist uploaded PDF into memory 
    if (isset($_FILES['pdf']) && is_uploaded_file($_FILES['pdf']['tmp_name'])) {
        $_SESSION['pdf_data'] = file_get_contents($_FILES['pdf']['tmp_name']);
    }

    // Load imag preview PDF data from current session
    $preview_b64 = $_SESSION['first_page_b64'] ?? '';
    $pdf_data     = $_SESSION['pdf_data'] ?? '';


    // Load license file and parse key-value pairs int 
    if(isset($_FILES['license'])&&is_uploaded_file($_FILES['license']['tmp_name'])){
        $lines=file($_FILES['license']['tmp_name'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);

        // Loop over each "KEY=VALUE" line
        foreach($lines as $line){
             // Only process lines that contain '='
            if(strpos($line,'=')!==false){
                [$k,$v]=array_map('trim',explode('=',$line,2));
                if($k==='API_BASE_URL'){$api_base=rtrim($v,'/');$_SESSION['api_base']=$api_base;}
                if($k==='API_KEY')     {$api_key =$v;$_SESSION['api_key']=$api_key;}
                if($k==='MODEL')       {$model   =$v;$_SESSION['model']=$model;}
            }
        }
        $masked = $api_key ? substr($api_key,0,4).'•••'.substr($api_key,-4) : '(none)';
        // Add message to session-backed log display at botot page
        log_append("License file loaded\n  API_BASE_URL: $api_base\n  API_KEY: $masked\n  MODEL: $model");
    }

    //HP handler for "action"
    $action=$_POST['action']??'';

    //specific action button names
    //Extract fields data button post
    //If post action is 'extract'
    if($action==='extract'){
        $endpoint=resolve_completions_url($api_base);

        // image from client-side PDF renderer
        $image_b64 = $_POST['first_page_b64'] ?? '';
        if(!$image_b64){
            log_append("[Warning] No image received from client-side PDF renderer.");
        }
        // LLM prompt
        $prompt = "You are a precise bibliographic extractor. From the provided first-page image of an academic PDF, extract these fields and return ONLY valid JSON (no extra text):\n\n".
                  "{\n".
                  "  \"year\": \"\",\n".
                  "  \"lead_author_surname\": \"\",\n".
                  "  \"title_full\": \"\",\n".
                  "  \"title_short\": \"\",\n".
                  "  \"journal\": \"\"\n".
                  "}\n\n".
                  "Rules:\n".
                  "- year: 4-digit publication year if visible; else empty string.\n".
                  "- lead_author_surname: surname of the first (lead) author (ASCII only).\n".
                  "- title_full: full paper title as printed.\n".
                  "- title_short: ≤60 chars, preserve key nouns/meaning, ASCII only; if already short, reuse.\n".
                  "- journal: journal or venue name if visible; else empty.\n".
                  "- Output must be valid JSON object with exactly those keys.";

        // Build llm inpromt correct format          
        if($api_base&&$api_key&&$model){
            $payload = [
                "model"=>$model,
                "messages"=>[
                    [
                        "role"=>"user",
                        "content"=>[
                            ["type"=>"text","text"=>$prompt]
                        ]
                    ]
                ]
            ];
            // payload image
            if($image_b64){
                $payload["messages"][0]["content"][] = [
                    "type" => "image_url",
                    "image_url" => [ "url" => $image_b64 ]
                ];
            }

            $payload_json=json_encode($payload,JSON_UNESCAPED_SLASHES);
            $headers=[
                "Content-Type: application/json",
                "Authorization: Bearer ".$api_key
            ];
            $opts=[
                "http"=>[
                    "method"=>"POST",
                    "header"=>implode("\r\n",$headers),
                    "content"=>$payload_json,
                    "timeout"=>60
                ],
                "ssl"=>[
                    "verify_peer"=>true,
                    "verify_peer_name"=>true
                ]
            ];


            $ctx=stream_context_create($opts);
            $resp=@file_get_contents($endpoint,false,$ctx);
            if($resp!==false){
                 // Decode the raw JSON response from the API
                $d=json_decode($resp,true);
                $content=$d['choices'][0]['message']['content']??'';
                $parsed = null;

                 // Extract the assistant's reply text (string containing JSON)
                if ($content) {
                    $parsed = json_decode($content, true);
                    if (!is_array($parsed)) {
                        if (preg_match('/\{.*\}/s', $content, $m)) {
                            $parsed = json_decode($m[0], true);
                        }
                    }
                }
                // Extract specific fields
                if (is_array($parsed)) {
                    $extracted = [
                        'year' => (string)($parsed['year'] ?? ''),
                        'lead_author_surname' => (string)($parsed['lead_author_surname'] ?? ''),
                        'title_full' => (string)($parsed['title_full'] ?? ''),
                        'title_short' => (string)($parsed['title_short'] ?? ''),
                        'journal' => (string)($parsed['journal'] ?? ''),
                    ];

                    // Auto-generate default PDF filename stem after extraction
                    $extracted['pdf_filename'] = build_filename_stem($extracted);
                    $_SESSION['extracted'] = $extracted;
                    log_append("[Extract JSON]\n".json_encode($extracted, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                } else {
                    log_append("[Extract Parse Error] Could not parse JSON.\nRaw:\n".$content);
                }
            }else{
                $err=error_get_last();
                log_append("[Error] Request failed. ".($err['message']??'(unknown)'));
            }
        }else{
            log_append("[Warning] Missing API info.");
        }
    }

    // If post action is 'download'
    if ($action === 'download') {
        // Make sure PDF exists in session
        if (!empty($_SESSION['pdf_data'])) {

            // Build filename using persistent extracted metadata + editable stem
            $current = $_SESSION['extracted'] ?? [];
            $override_name = $current['pdf_filename'] ?? '';
            $name = build_filename($current, $override_name);

            header("Content-Type: application/pdf");
            header("Content-Disposition: attachment; filename=\"$name\"");
            echo $_SESSION['pdf_data'];
            exit;
        }
        else {
            log_append("[Error] No PDF stored in session. Upload a PDF first.");
        }
    }
    
    //if post action is clear button handeler fucntion
    if ($action === 'clear') {

        // wipe stored data
        unset($_SESSION['first_page_b64']);
        unset($_SESSION['pdf_data']);
        unset($_SESSION['extracted']);
        $_SESSION['raw_log'] = [];

        // Optionally rebuild empty extracted structure
        $_SESSION['extracted'] = [
            'year' => '',
            'lead_author_surname' => '',
            'title_short' => '',
            'journal' => '',
            'title_full' => '',
            'pdf_filename' => '',
        ];

        // Reload page so everything visually resets
        header("Location: index.php?cleared=1&v=" . time());
        exit;
    }
}

$raw_output = implode("\n\n", $_SESSION['raw_log']);


///////////////////////////////////////////////////////////////////////////////////////////////////////
//End of PHP processing
////////////////////////////////////////////////////////////////////////////////////////////////////
?>




<!-- end of php code and  start of html body -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>AiPDF Renamer</title>

<!-- include Tailwind -->
<script src="https://cdn.tailwindcss.com"></script>


<style>
body {
    background: linear-gradient(to bottom, #0f172a, #1e293b, #000);
}
</style>


<!-- Small poeice of javascrpt for PDF.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.14.305/pdf.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc =
  "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.14.305/pdf.worker.min.js";
</script>
</head>

<body class="font-sans text-gray-100 min-h-screen">


<!-- HEADER -->
<header class="w-full pt-10 pb-6">

    <div class="max-w-6xl mx-auto px-4 flex items-center justify-between">

        <!-- LEFT SIDE -->
        <div>
            <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-gray-100 flex items-center gap-3">
                <span class="text-lime-400">AiPDF</span>
                <span class="text-white">Renamer</span>
            </h1>

            <p class="text-gray-300 text-sm mt-1">
                AI-powered PDF renamer and bibliography extraction
            </p>
        </div>

        <!-- RIGHT SIDE NAV LINKS -->
        <div class="flex items-center gap-5">  <!-- ← controls spacing between About + Switch -->
            
            <!-- ABOUT LINK -->
            <a href="about.html"
            class="text-gray-300 text-s hover:text-white transition">
            About
            </a>

            <!-- SWITCH BUTTON -->
            <a href="login.php"
            class="flex items-center gap-2 px-3 py-1.5
                    rounded-md bg-slate-800/80 hover:bg-slate-800/60
                    border border-slate-700/60 hover:border-slate-600
                    transition shadow-sm">

                <span class="flex items-baseline gap-1 text-3xl font-bold tracking-tight">
                    <span class="text-2xl text-gray-300 font-medium">Switch To:&nbsp;</span>
                    <span class="text-lime-400">AiPDF</span>
                    <span class="text-white">Library&Search</span>
                </span>

                <span class="text-lime-400 text-lg">→</span>
            </a>
        </div>
    </div>
</header>
<div class="max-w-6xl mx-auto px-4 space-y-10 pb-20">



<!-- API data- Post submit back to this same php file-->
<form method="POST" enctype="multipart/form-data"
      class="bg-slate-900/60 border border-slate-700 rounded-2xl shadow-xl p-6 sm:p-8 space-y-1">

    <h2 class="text-xl font-bold text-white-300 mb-4">API Configuration</h2>

    <div class="grid md:grid-cols-[1fr_1fr_1fr_auto] gap-4 items-end">
        <div>
            <label class="text-xs text-gray-300">Base URL endpoint</label>
            <input type="text" name="api_base"
                   placeholder="https://api.openai.com/v1/chat/completions"
                   value="<?= htmlspecialchars((string)($api_base ?? '')) ?>"
                   readonly
                   class="w-full mt-1 px-3 py-2 text-sm rounded bg-gray-900/60 border border-gray-700 placeholder-gray-500">
        </div>

        <div>
            <label class="text-xs text-gray-300">API Key</label>
            <input type="password" name="api_key"
                   placeholder="sk-xxxxxxxxxxxxxxxx"
                   value="<?= htmlspecialchars((string)($api_key ?? '')) ?>"
                   readonly
                   class="w-full mt-1 px-3 py-2 text-sm rounded bg-gray-900/60 border border-gray-700 placeholder-gray-500">
        </div>

        <div>
            <label class="text-xs text-gray-300">Model (Must be vision enabled)</label>
            <input type="text" name="model"
                   placeholder="gpt-4o-mini"
                   value="<?= htmlspecialchars((string)($model ?? '')) ?>"
                   readonly
                   class="w-full mt-1 px-3 py-2 text-sm rounded bg-gray-900/60 border border-gray-700 placeholder-gray-500">
        </div>

        <!-- Upload Button + OR + Tooltip -->
        <div class="flex items-center gap-2 pb-[2px]">
            <span class="text-gray-400 text-xs whitespace-nowrap">or</span>

            <label for="license"
                   class="cursor-pointer bg-cyan-400 text-gray-900 font-semibold px-4 py-2 rounded-lg shadow hover:bg-cyan-300 transition whitespace-nowrap">
                Load License File
            </label>

            <!-- Post form submit- [name="license" --used to identify specific post] 
             Browser-side file picker (HTML). 'onchange' triggers immediate form submit
             also if clicked (after file selection) submit this entire big form
             POSTs to index.php -->     
            <input id="license" type="file" name="license" accept=".txt"
                   class="hidden" onchange="this.form.submit()">



            <div class="relative group flex items-center">
                <span class="text-gray-400 cursor-pointer text-sm font-bold">?</span>
                <div class="absolute left-full ml-3 top-1/2 -translate-y-1/2 
                            bg-gray-900/90 text-gray-200 text-xs p-3 rounded-md 
                            border border-gray-700 shadow-lg opacity-0 group-hover:opacity-100 
                            transition pointer-events-none z-40 text-[13px] w-64">
                    <span class="font-semibold text-gray-100">
                        Example license file format:
                    </span><br>
                    Create a <code>.txt</code> file containing the following lines:<br><br>
                    <code class="text-cyan-300">
                        API_BASE_URL=https://api.openai.com/v1/chat/completions<br>
                        API_KEY=sk-xxyourkeyherexx<br>
                        MODEL=gpt-4o-mini
                    </code>
                </div>
            </div>
        </div>
    </div>
</form>


<!-- MAIN form -->
<form method="POST" enctype="multipart/form-data"
      id="main-form"
      class="bg-slate-900/60 border border-slate-700 rounded-2xl shadow-xl p-6 sm:p-8 space-y-2">

    <input type="hidden" name="first_page_b64" id="first_page_b64">

    <!-- PDF DROP ZONE -->
    <label id="drop-zone"
        class="p-10 w-full block text-center rounded-xl cursor-pointer
            bg-gray-900/40 border-2 border-dashed border-[#58b9d9]
            transition-all duration-200
            hover:bg-gray-800/40 hover:border-cyan-300 hover:brightness-125">

        <div class="font-medium text-white mb-1">Drag & Drop your PDF here</div>
        <div class="text-xs text-gray-500 hover:text-gray-300 transition">...or click to choose a file</div>

        <input id="file" type="file" name="pdf" accept="application/pdf" class="hidden" />
    </label>

    <!-- PREVIEW + FIELDS -->
    <div class="grid grid-cols-1 md:grid-cols-[260px,1fr] gap-8 mt-8">
        <div>
            <div class="text-s text-gray-300 mb-2">PDF Preview (First Page)</div>
            <div class="w-[270px] h-[390px] flex items-center justify-center bg-gray-800/60 border border-gray-700 rounded-lg overflow-hidden">
                <canvas id="preview-canvas"></canvas>
                <span id="preview-placeholder" class="text-[10px] text-gray-500">Waiting for PDF…</span>
            </div>
        </div>

        <div>
            <div class="text-s text-gray-300 mb-2">
                 <span class="font-bold text-lime-400">Ai Extracted</span> Metadata (you may edit manually)
            </div>

            <div class="grid grid-cols-1 gap-4 text-xs  mb-4">
                <div>
                    <label class="text-gray-400">Year:</label>
                    <input id="year" name="year" type="text"
                           value="<?= htmlspecialchars((string)($extracted['year'] ?? '')) ?>"
                           class="w-full mt-1 px-3 py-2 rounded bg-gray-900/60 border border-gray-700">
                </div>

                <div>
                    <label class="text-gray-400">Lead Author (Surname):</label>
                    <input id="lead_author_surname" name="lead_author_surname" type="text"
                           value="<?= htmlspecialchars((string)($extracted['lead_author_surname'] ?? '')) ?>"
                           class="w-full mt-1 px-3 py-2 rounded bg-gray-900/60 border border-gray-700">
                </div>

                <div>
                    <label class="text-gray-400">Journal:</label>
                    <input id="journal" name="journal" type="text"
                           value="<?= htmlspecialchars((string)($extracted['journal'] ?? '')) ?>"
                           class="w-full mt-1 px-3 py-2 rounded bg-gray-900/60 border border-gray-700">
                </div>

                <div>
                    <label class="text-gray-400">Title (full):</label>
                    <textarea id="title_full" name="title_full" rows="1"
                              class="w-full mt-1 px-3 py-2 rounded bg-gray-900/60 border border-gray-700"><?= htmlspecialchars((string)($extracted['title_full'] ?? '')) ?></textarea>
                </div>

                <div>
                    <label class="text-gray-400">
                         Title (<span class="font-bold text-lime-400">Ai </span> shortened:)
                    </label>
                    <input id="title_short" name="title_short" type="text"
                           value="<?= htmlspecialchars((string)($extracted['title_short'] ?? '')) ?>"
                           class="w-full mt-1 px-3 py-2 rounded bg-gray-900/60 border border-gray-700">
                </div>

                <div>
                    <label class="text-gray-400">
                         Proposed <span class="font-bold text-lime-400">Ai </span>File Name:
                    </label>
                    <input id="pdf_filename" name="pdf_filename" type="text"
                           value="<?= htmlspecialchars((string)($extracted['pdf_filename'] ?? '')) ?>"
                           class="w-full mt-1 px-3 py-2 rounded bg-gray-900/60 border border-gray-700"
                           placeholder="year_leadauthor_title_journal">
                </div>
            </div>
        </div>
    </div>

    <!-- BUTTONS -->
    <div class="flex flex-wrap gap-4 mt-12">
        <button type="submit" name="action" value="extract"
                class="px-5 py-2 bg-blue-600 text-white rounded-lg font-medium shadow hover:bg-blue-500 transition">
            Extract Fields
        </button>

        <button type="submit" name="action" value="download"
                class="px-5 py-2 bg-green-600 text-white rounded-lg font-medium shadow hover:bg-green-500 transition">
            Download Renamed PDF
        </button>

        <!--post  PHP - CLEAR ALL BUTTON -->
        <button type="submit" name="action" value="clear"
                class="px-5 py-2 bg-red-600 text-white rounded-lg font-medium shadow hover:bg-red-500 transition">
            Clear All
        </button>
    </div>

    <!-- RAW OUTPUT -->
    <div class="mt-8">
        <div class="text-xs text-cyan-300 mb-2 font-semibold">Raw Model Responses</div>
        <textarea rows="6"
                  class="w-full text-[11px] px-3 py-2 rounded bg-gray-900/70 border border-gray-700 font-mono text-gray-300 resize-y"><?= htmlspecialchars((string)($raw_output ?? '')) ?></textarea>
    </div>
</form>
</div>


<!-- end of html body -->







 <!-- Small piece of javascript to deal with pdfs to render the pdf at reduced resssulution -->
<!-- The backend stores two images in the session: 
     (1) a high-resolution base64 PNG for the AI model 
     (2) a lower-resolution version for on-screen preview -->
 <!-- loads images into sesion-->
<?php if (!empty($_SESSION['first_page_b64'])): ?>
<script>
window.addEventListener("DOMContentLoaded", () => {

    // Create an Image object that will load the base64 PNG
    const img = new Image();
    // When the image finishes loading, draw it onto the preview <canvas>
    img.onload = () => {
        const canvas = document.getElementById("preview-canvas");
        const ctx = canvas.getContext("2d");

        // Maximum preview box size (keeps the image tidy on screen)
        const BOX_W = 270;
        const BOX_H = 400;

        // Compute scale factor hat fits the image inside the box
        const scale = Math.min(BOX_W / img.width, BOX_H / img.height);
        canvas.width = img.width * scale;
        canvas.height = img.height * scale;

         // Draw the scaled image onto the canvas area on web page
        ctx.drawImage(
            img,
            0, 0, img.width, img.height,
            0, 0, canvas.width, canvas.height
        );

        document.getElementById("preview-placeholder").style.display = "none";
    };

    // Assign the session-stored base64 PNG (data:image/png;base64,...) to the image source
    img.src = "<?= $_SESSION['first_page_b64'] ?>";
});
</script>
<?php endif; ?>






<!-- Small piece of javascript to deal with pdfs to render -->
<script>
document.getElementById("file").addEventListener("change", async (e) => {
  const file = e.target.files[0];
  if (!file) return;

  // Load PDF + first page
  const pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
  const page = await pdf.getPage(1);

  /* ----- HIGH-RES (AI) ----- */
  const SCALE_HI = 2.5;
  const hiVP = page.getViewport({ scale: SCALE_HI });

  const hiCanvas = document.createElement("canvas");
  hiCanvas.width = hiVP.width;
  hiCanvas.height = hiVP.height;

  await page.render({ canvasContext: hiCanvas.getContext("2d"), viewport: hiVP }).promise;

  // Send high-res to PHP
  document.getElementById("first_page_b64").value = hiCanvas.toDataURL("image/png", 0.92);

  /* ----- PREVIEW (scaled down from high-res) ----- */
  const pCanvas = document.getElementById("preview-canvas");
  const pCtx = pCanvas.getContext("2d");

  const BOX_W = 240, BOX_H = 320;
  const scale = Math.min(BOX_W / hiCanvas.width, BOX_H / hiCanvas.height);

  pCanvas.width = hiCanvas.width * scale;
  pCanvas.height = hiCanvas.height * scale;

  pCtx.drawImage(hiCanvas, 0, 0, pCanvas.width, pCanvas.height);

  document.getElementById("preview-placeholder").style.display = "none";
});
</script>







<!-- Small piece of javascript to enable drag and drop of pdf files -->
<script>
const dropZone = document.getElementById("drop-zone");
const fileInput = document.getElementById("file");
// CLICK = open file selector
dropZone.addEventListener("click", () => fileInput.click());
// HIGHLIGHT when dragging
["dragenter", "dragover"].forEach(ev =>
    dropZone.addEventListener(ev, (e) => {
        e.preventDefault();
        dropZone.classList.add("border-cyan-300", "bg-gray-800/40");
    })
);
// REMOVE highlight
["dragleave", "drop"].forEach(ev =>
    dropZone.addEventListener(ev, (e) => {
        e.preventDefault();
        dropZone.classList.remove("border-cyan-300", "bg-gray-800/40");
    })
);
// HANDLE FILE DROP
dropZone.addEventListener("drop", (e) => {
    e.preventDefault();
    const file = e.dataTransfer.files[0];
    if (!file) return;
    // Pass dropped file to the hidden input
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput.files = dt.files;
    // Trigger your existing change handler
    fileInput.dispatchEvent(new Event("change"));
});
</script>






<!-- html Footer -->
<footer class="px-5 py-2 bg-slate-800/60  border-t border-slate-700  rounded-b-2xl text-gray-300 text-xs">
    <div class="flex flex-col items-center justify-center space-y-1">
        <p>© Designed & Built by Ray Kruger & Grant Nelson</p>
        <p class="pl-4"> HTML • PHP • MySQL • Client-Side JavaScript • Tailwind CSS ⚡⚡</p>
    </div>
</footer>




</body>
</html>
