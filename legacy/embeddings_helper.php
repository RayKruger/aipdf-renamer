<?php
// embeddings_helper.php
// Helper functions for embedding-based search (using file_get_contents, no cURL)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

////////////////////////////////////////////////////////////////////////////////////////////////////////
// Check we have API settings in session
function has_embedding_config(): bool
{
    return isset($_SESSION['api_base'], $_SESSION['api_key'], $_SESSION['model'])
        && $_SESSION['api_base'] !== ''
        && $_SESSION['api_key']  !== ''
        && $_SESSION['model']    !== '';
}




///////////////////////////////////////////////////////////////////////////////////////////////////////
 //Low-level helper: POST JSON using file_get_contents.
 //Returns decoded JSON array on success, or null on error.
function http_post_json(string $url, array $headers, array $payload): ?array
{
    $json = json_encode($payload);

    $headerLines = $headers;
    $headerLines[] = 'Content-Type: application/json';
    $headerLines[] = 'Content-Length: ' . strlen($json);

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headerLines) . "\r\n",
            'content' => $json,
            'timeout' => 30,
            'ignore_errors' => true,  // still get body on 4xx/5xx
        ],
    ];

    $context  = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        // Could not connect or allow_url_fopen is disabled
        return null;
    }

    // Try to read HTTP status from $http_response_header
    $statusCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        // First line like: HTTP/1.1 200 OK
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('#HTTP/\d\.\d\s+(\d{3})#', $statusLine, $m)) {
            $statusCode = (int)$m[1];
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        // Non-2xx from API; you can debug by temporarily var_dump($response)
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }

    return $data;
}





///////////////////////////////////////////////////////////////////////////////
// Get embedding vector for text using OpenAI-compatible endpoint.
// Returns array of floats or null on error.
function get_embedding_vector(string $text): ?array
{
    // Configuration must already be stored in the session
    if (!has_embedding_config()) {
        return null;   // nothing to call the API with
    }

    // Users supply an API base URL such as:
    //   https://api.openai.com/v1/embeddings
    // or sometimes:
    //   https://api.openai.com/v1
    //
    // We must avoid attaching "/embeddings" twice.
    $apiBase = rtrim($_SESSION['api_base'], '/');  
    $apiKey  = $_SESSION['api_key'];
    $model   = $_SESSION['model'];

    // If the base URL *already* ends in "/embeddings",
    // use it exactly. Otherwise append it.
    if (preg_match('#/embeddings$#', $apiBase)) {
        $url = $apiBase;
    } else {
        $url = $apiBase . '/embeddings';
    }

    // Minimal payload: embedding model + text to embed.
    $payload = [
        'model' => $model,
        'input' => $text,
    ];

    // Authorization header only (http_post_json adds Content-Type).
    $headers = [
        'Authorization: Bearer ' . $apiKey,
    ];

    // Call the embedding API. http_post_json returns decoded JSON
    // or null on failure (timeout, network, invalid JSON, etc.).
    $data = http_post_json($url, $headers, $payload);
    if ($data === null) {
        return null;
    }

    // Validate before accessing it.
    // If it's missing or not an array (e.g., API error, wrong model, malformed response),
    // we abort and return null so the caller can safely skip this file.
    if (!isset($data['data'][0]['embedding']) || !is_array($data['data'][0]['embedding'])) {
        return null;
    }

    // Return the actual vector
    return $data['data'][0]['embedding'];
}








/////////////////////////////////////////////////////////////////////////////
//Cosine similarity between two vectors.
function cosine_similarity(array $a, array $b): float
{
    // Use only the overlapping length of both vectors
    $len = min(count($a), count($b));
    if ($len === 0) {
        return 0.0;
    }

    // Dot product accumulator: Σ (a[i] * b[i])
    $dot = 0.0;

    // Norm accumulators: Σ (a[i]^2) and Σ (b[i]^2)
    $na  = 0.0;  // ||A||²
    $nb  = 0.0;  // ||B||²

    // Single loop computes dot product and magnitudes
    for ($i = 0; $i < $len; $i++) {
        $va = (float)$a[$i];  // component of vector A
        $vb = (float)$b[$i];  // component of vector B

        $dot += $va * $vb;    // accumulate dot product
        $na  += $va * $va;    // accumulate squared magnitude of A
        $nb  += $vb * $vb;    // accumulate squared magnitude of B
    }

    // Avoid division by zero (occurs if one vector has all zeros)
    if ($na == 0.0 || $nb == 0.0) {
        return 0.0;
    }

    // Return cos(θ) = (A · B) / (||A|| * ||B||)
    return $dot / (sqrt($na) * sqrt($nb));
}







////////////////////////////////////////////////////////////////////////////////
// Semantic search over filenames in $rows.
//  $rows: array of ['id','filename','created_at', ...]
//  Returns top-K rows with 'similarity' field added.
function semantic_search_filenames(string $query, array $rows, int $topK = 10): array
{
    // 1. Get vector representation of the user's query text
    $queryEmbedding = get_embedding_vector($query);

    // If the embedding API failed, return no results rather than crashing
    if ($queryEmbedding === null) {
        return [];
    }

    $scored = [];

    // 2. Loop through every stored PDF for this user
    foreach ($rows as $row) {

        // Remove the ".pdf" extension before embedding 
        $title = preg_replace('/\.pdf$/i', '', $row['filename']);

        // Create an embedding for this filename/title
        $fileEmbedding = get_embedding_vector($title);

        // Skip files that could not be embedded (API error, etc.)
        if ($fileEmbedding === null) {
            continue;
        }

        //  Measure semantic closeness using cosine similarity
        //  Higher score = filename vector points in a similar 
        $sim = cosine_similarity($queryEmbedding, $fileEmbedding);

        // Attach the similarity score to this row so we can sort by it
        $row['similarity'] = $sim;
        $scored[] = $row;
    }

    // 4. Sort results by similarity descending
    usort($scored, function ($a, $b) {
        return $b['similarity'] <=> $a['similarity'];
    });

    // 5. Return only the top-K most similar PDFs
    return array_slice($scored, 0, $topK);
}
