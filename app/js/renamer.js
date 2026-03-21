// Renamer Logic
const pdfjsLib = window['pdfjs-dist/build/pdf'];
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

let currentPdfBlob = null;
let currentPdfName = "";
let extractedText = "";

// UI Elements
const dropZone = document.getElementById('drop-zone');
const pdfInput = document.getElementById('pdf-input');
const previewContainer = document.getElementById('preview-container');
const editorContainer = document.getElementById('editor-container');
const canvas = document.getElementById('pdf-canvas');
const ctx = canvas.getContext('2d');

const metaYear = document.getElementById('meta-year');
const metaAuthor = document.getElementById('meta-author');
const metaVenue = document.getElementById('meta-venue');
const metaTitle = document.getElementById('meta-title');
const previewFilename = document.getElementById('preview-filename');
const extractBtn = document.getElementById('extract-btn');
const downloadBtn = document.getElementById('download-btn');

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
    document.getElementById('user-email').innerText = session.user.email;
}

document.getElementById('logout-btn').addEventListener('click', async () => {
    await window.auth.signOut();
    window.location.href = 'index.html';
});

// --- API Config Handling ---
function loadApiConfig() {
    apiBaseInput.value = localStorage.getItem('pdf_renamer_base') || 'https://api.openai.com/v1';
    apiModelInput.value = localStorage.getItem('pdf_renamer_model') || 'gpt-4o-mini';
    apiKeyInput.value = localStorage.getItem('pdf_renamer_key') || '';
}

saveApiBtn.addEventListener('click', () => {
    localStorage.setItem('pdf_renamer_base', apiBaseInput.value);
    localStorage.setItem('pdf_renamer_model', apiModelInput.value);
    localStorage.setItem('pdf_renamer_key', apiKeyInput.value);
    alert('Configuration saved to browser!');
});

clearApiBtn.addEventListener('click', () => {
    localStorage.removeItem('pdf_renamer_base');
    localStorage.removeItem('pdf_renamer_model');
    localStorage.removeItem('pdf_renamer_key');
    loadApiConfig();
});

// --- PDF Handling ---
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

pdfInput.addEventListener('change', (e) => {
    if (e.target.files.length) {
        handleFile(e.target.files[0]);
    }
});

async function handleFile(file) {
    if (file.type !== 'application/pdf') {
        alert('Please upload a PDF file.');
        return;
    }

    currentPdfBlob = file;
    currentPdfName = file.name;

    const reader = new FileReader();
    reader.onload = async function() {
        const typedarray = new Uint8Array(this.result);
        const pdf = await pdfjsLib.getDocument(typedarray).promise;
        const page = await pdf.getPage(1);
        
        // Render to canvas
        const viewport = page.getViewport({ scale: 1.5 });
        canvas.height = viewport.height;
        canvas.width = viewport.width;

        const renderContext = {
            canvasContext: ctx,
            viewport: viewport
        };
        await page.render(renderContext).promise;

        // Extract text for LLM
        const textContent = await page.getTextContent();
        extractedText = textContent.items.map(item => item.str).join(' ');

        previewContainer.classList.remove('hidden');
        editorContainer.classList.remove('hidden');
        updateFilenamePreview();
    };
    reader.readAsArrayBuffer(file);
}

// --- Filename Generation ---
function updateFilenamePreview() {
    const year = metaYear.value.trim();
    const author = metaAuthor.value.trim();
    const venue = metaVenue.value.trim();
    const title = metaTitle.value.trim()
        .replace(/[:\/\\|?*<>]/g, '') // Remove invalid chars
        .replace(/\s+/g, '_'); // Replace spaces with underscores

    if (!year && !author && !title) {
        previewFilename.innerText = '...waiting for input';
        return;
    }

    const nameParts = [];
    if (year) nameParts.push(year);
    if (author) nameParts.push(author);
    if (venue) nameParts.push(venue);
    if (title) nameParts.push(title);

    const newName = nameParts.join('_') + '.pdf';
    previewFilename.innerText = newName;
    return newName;
}

[metaYear, metaAuthor, metaVenue, metaTitle].forEach(el => {
    el.addEventListener('input', updateFilenamePreview);
});

// --- AI Extraction ---
extractBtn.addEventListener('click', async () => {
    const apiKey = apiKeyInput.value.trim();
    const apiBase = apiBaseInput.value.trim();
    const model = apiModelInput.value.trim();

    if (!apiKey) {
        alert('Please enter an API Key in the configuration section.');
        return;
    }

    extractBtn.disabled = true;
    extractBtn.innerText = 'Extracting...';

    try {
        const prompt = `Extract metadata from the following academic paper text. 
        Format your response as a JSON object with keys: "year", "author" (last name only of first author), "venue" (short name), "title".
        Text: ${extractedText.substring(0, 3000)}`;

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

        const data = await response.json();
        const meta = JSON.parse(data.choices[0].message.content);

        metaYear.value = meta.year || '';
        metaAuthor.value = meta.author || '';
        metaVenue.value = meta.venue || '';
        metaTitle.value = meta.title || '';

        updateFilenamePreview();
    } catch (error) {
        console.error(error);
        alert('Failed to extract metadata. Check your API settings and console.');
    } finally {
        extractBtn.disabled = false;
        extractBtn.innerText = 'Extract with AI';
    }
});

// --- Download ---
downloadBtn.addEventListener('click', () => {
    if (!currentPdfBlob) return;
    
    const newName = updateFilenamePreview();
    const url = URL.createObjectURL(currentPdfBlob);
    const a = document.createElement('a');
    a.href = url;
    a.download = newName;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
});

// --- Init ---
initAuth();
loadApiConfig();
