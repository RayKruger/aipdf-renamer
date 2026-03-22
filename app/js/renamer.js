// Renamer Logic
const pdfjsLib = window['pdfjs-dist/build/pdf'];
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

let currentPdfBlob = null;
let extractedText = "";

// --- UI Elements ---
const dropZone = document.getElementById('drop-zone');
const pdfInput = document.getElementById('pdf-input');
const canvas = document.getElementById('pdf-canvas');
const ctx = canvas.getContext('2d');
const previewPlaceholder = document.getElementById('preview-placeholder');

const metaYear = document.getElementById('meta-year');
const metaAuthor = document.getElementById('meta-author');
const metaVenue = document.getElementById('meta-venue');
const metaTitle = document.getElementById('meta-title');
const previewFilename = document.getElementById('preview-filename');
const rawLog = document.getElementById('raw-log');

const extractBtn = document.getElementById('extract-btn');
const downloadBtn = document.getElementById('download-btn');
const clearBtn = document.getElementById('clear-btn');

const apiBaseInput = document.getElementById('api-base');
const apiModelInput = document.getElementById('api-model');
const apiKeyInput = document.getElementById('api-key');
const saveApiBtn = document.getElementById('save-api');
const clearApiBtn = document.getElementById('clear-api');

// --- Auth Check ---
async function initAuth() {
    const session = await window.auth.checkSession();
    if (!session) {
        window.location.href = 'index.html';
        return;
    }
    const userEmailEl = document.getElementById('user-email');
    if (userEmailEl) userEmailEl.innerText = session.user.email;
}

const logoutBtn = document.getElementById('logout-btn');
if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
        await window.auth.signOut();
        window.location.href = 'index.html';
    });
}

// --- Utils ---
function logToUi(message) {
    if (rawLog) {
        const timestamp = new Date().toLocaleTimeString();
        rawLog.value = `[${timestamp}] ${message}\n` + rawLog.value;
    }
}

// --- API Config Handling ---
function loadApiConfig() {
    apiBaseInput.value = localStorage.getItem('pdf_renamer_base') || 'https://api.openai.com/v1';
    apiModelInput.value = localStorage.getItem('pdf_renamer_model') || 'gpt-4o-mini';
    apiKeyInput.value = localStorage.getItem('pdf_renamer_key') || '';
}

if (saveApiBtn) {
    saveApiBtn.addEventListener('click', () => {
        localStorage.setItem('pdf_renamer_base', apiBaseInput.value);
        localStorage.setItem('pdf_renamer_model', apiModelInput.value);
        localStorage.setItem('pdf_renamer_key', apiKeyInput.value);
        logToUi('Configuration saved to browser local storage.');
        alert('Configuration saved to browser!');
    });
}

if (clearApiBtn) {
    clearApiBtn.addEventListener('click', () => {
        localStorage.removeItem('pdf_renamer_base');
        localStorage.removeItem('pdf_renamer_model');
        localStorage.removeItem('pdf_renamer_key');
        loadApiConfig();
        logToUi('API configuration cleared.');
    });
}

// --- PDF Handling ---
if (dropZone) {
    dropZone.addEventListener('click', () => pdfInput.click());

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            handleFile(e.dataTransfer.files[0]);
        }
    });
}

if (pdfInput) {
    pdfInput.addEventListener('change', (e) => {
        if (e.target.files.length) {
            handleFile(e.target.files[0]);
        }
    });
}

async function handleFile(file) {
    if (file.type !== 'application/pdf') {
        alert('Please upload a PDF file.');
        return;
    }

    currentPdfBlob = file;
    logToUi(`File loaded: ${file.name} (${(file.size / 1024).toFixed(1)} KB)`);

    const reader = new FileReader();
    reader.onload = async function() {
        const typedarray = new Uint8Array(this.result);
        const pdf = await pdfjsLib.getDocument(typedarray).promise;
        const page = await pdf.getPage(1);
        
        // Render to canvas
        const viewport = page.getViewport({ scale: 1.2 });
        if (canvas) {
            canvas.height = viewport.height;
            canvas.width = viewport.width;

            const renderContext = {
                canvasContext: ctx,
                viewport: viewport
            };
            await page.render(renderContext).promise;
        }
        
        if (previewPlaceholder) previewPlaceholder.style.display = 'none';

        // Extract text for LLM
        const textContent = await page.getTextContent();
        extractedText = textContent.items.map(item => item.str).join(' ');
        
        logToUi(`First page rendered. Text extraction complete (${extractedText.length} chars).`);
        updateFilenamePreview();
    };
    reader.readAsArrayBuffer(file);
}

// --- Filename Generation ---
function updateFilenamePreview() {
    if (!metaYear) return;
    const year = metaYear.value.trim();
    const author = metaAuthor.value.trim();
    const venue = metaVenue.value.trim();
    const title = metaTitle.value.trim()
        .replace(/[:\/\\|?*<>]/g, '') // Remove invalid chars
        .replace(/\s+/g, '_'); // Replace spaces with underscores

    if (!year && !author && !title) {
        if (previewFilename) previewFilename.innerText = '...waiting for input';
        return;
    }

    const nameParts = [];
    if (year) nameParts.push(year);
    if (author) nameParts.push(author);
    if (venue) nameParts.push(venue);
    if (title) nameParts.push(title);

    const newName = nameParts.join('_') + '.pdf';
    if (previewFilename) previewFilename.innerText = newName;
    return newName;
}

if (metaYear && metaAuthor && metaVenue && metaTitle) {
    [metaYear, metaAuthor, metaVenue, metaTitle].forEach(el => {
        el.addEventListener('input', updateFilenamePreview);
    });
}

// --- AI Extraction ---
if (extractBtn) {
    extractBtn.addEventListener('click', async () => {
        const apiKey = apiKeyInput.value.trim();
        const apiBase = apiBaseInput.value.trim();
        const model = apiModelInput.value.trim();

        if (!apiKey) {
            alert('Please enter an API Key in the configuration section.');
            return;
        }

        if (!extractedText) {
            alert('Please upload a PDF first.');
            return;
        }

        extractBtn.disabled = true;
        extractBtn.innerText = 'Extracting...';
        logToUi(`AI Extraction started using model: ${model}`);

        try {
            const prompt = `Extract metadata from the following academic paper text. 
            Format your response as a JSON object with keys: "year", "author" (last name only of first author), "venue" (short name), "title".
            Text: ${extractedText.substring(0, 4000)}`;

            const response = await fetch(`${apiBase}/chat/completions`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${apiKey}`
                },
                body: JSON.stringify({
                    model: model,
                    messages: [{ role: 'user', content: prompt }],
                    response_format: { type: "json_object" }
                })
            });

            if (!response.ok) throw new Error(`API Error: ${response.status}`);

            const data = await response.json();
            const content = data.choices[0].message.content;
            logToUi(`Model response content: ${content}`);
            
            const meta = JSON.parse(content);

            metaYear.value = meta.year || '';
            metaAuthor.value = meta.author || '';
            metaVenue.value = meta.venue || '';
            metaTitle.value = meta.title || '';

            updateFilenamePreview();
            logToUi('Metadata fields updated from AI.');
        } catch (error) {
            console.error(error);
            logToUi(`Extraction Error: ${error.message}`);
            alert('Failed to extract metadata. Check your API settings and console.');
        } finally {
            extractBtn.disabled = false;
            extractBtn.innerText = 'Extract Fields';
        }
    });
}

// --- Download ---
if (downloadBtn) {
    downloadBtn.addEventListener('click', () => {
        if (!currentPdfBlob) {
            alert('Upload a PDF first!');
            return;
        }
        
        const newName = updateFilenamePreview();
        const url = URL.createObjectURL(currentPdfBlob);
        const a = document.createElement('a');
        a.href = url;
        a.download = newName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        logToUi(`Downloaded: ${newName}`);
    });
}

// --- Clear ---
if (clearBtn) {
    clearBtn.addEventListener('click', () => {
        currentPdfBlob = null;
        extractedText = "";
        metaYear.value = "";
        metaAuthor.value = "";
        metaVenue.value = "";
        metaTitle.value = "";
        if (previewPlaceholder) previewPlaceholder.style.display = 'block';
        if (canvas) ctx.clearRect(0, 0, canvas.width, canvas.height);
        updateFilenamePreview();
        if (rawLog) rawLog.value = "";
        logToUi('All fields cleared.');
    });
}

// --- Init ---
initAuth();
loadApiConfig();
updateFilenamePreview();
