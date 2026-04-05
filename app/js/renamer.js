// Renamer Logic (Enhanced with Multi-Provider & PDF Pagination)
const pdfjsLib = window['pdfjs-dist/build/pdf'];
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

// --- State ---
let pdfDoc = null;
let currentPage = 1;
let totalPages = 0;
let currentPdfBlob = null;
let extractedText = ""; // Text from the *current* page
let PROMPTS = {
    metadata_prompt: "Extract metadata from the following academic paper text (from page {{page}}).\nCRITICAL: Extract the FULL, COMPLETE title for the title field.\nFormat your response as a JSON object with keys: \"year\", \"author\" (last name only of first author), \"venue\" (short name), \"title\".\nText: {{text}}",
    abstract_prompt: "Based on the following paper text, extract the abstract (up to 3 sentences) and generate a shortened \"Ai\" style title (e.g. \"Ai_Vision_Transformer\").\nFormat your response as a JSON object with keys: \"abstract\", \"short_title\".\nText: {{text}}"
};

const MODEL_MAP = {
    openai: ['gpt-5-mini', 'gpt-5-nano', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-5', 'gpt-4o-mini', 'gpt-4o'],
    claude: ['claude-3-5-sonnet-20240620', 'claude-3-opus-20240229', 'claude-3-haiku-20240307'],
    gemini: ['gemini-1.5-flash', 'gemini-1.5-pro'],
    custom: ['llama3', 'mistral', 'phi3']
};

const BASE_URL_MAP = {
    openai: 'https://api.openai.com/v1',
    claude: 'https://api.anthropic.com/v1',
    gemini: 'https://generativelanguage.googleapis.com/v1beta',
    custom: 'http://localhost:11434/v1'
};

// --- UI Elements ---
const dropZone = document.getElementById('drop-zone');
const pdfInput = document.getElementById('pdf-input');
const canvas = document.getElementById('pdf-canvas');
const ctx = canvas.getContext('2d');
const previewPlaceholder = document.getElementById('preview-placeholder');
const pageIndicator = document.getElementById('page-indicator');
const pageHint = document.getElementById('page-hint');
const prevPageBtn = document.getElementById('prev-page');
const nextPageBtn = document.getElementById('next-page');

const metaYear = document.getElementById('meta-year');
const metaAuthor = document.getElementById('meta-author');
const metaVenue = document.getElementById('meta-venue');
const metaTitle = document.getElementById('meta-title');
const metaShortTitle = document.getElementById('meta-short-title');
const metaAbstract = document.getElementById('meta-abstract');
const metaKeywords = document.getElementById('meta-keywords');
const metaAllAuthors = document.getElementById('meta-all-authors');
const previewFilename = document.getElementById('preview-filename');

const extractBtn = document.getElementById('extract-btn');
const extractPageBtn = document.getElementById('extract-page-btn');
const downloadBtn = document.getElementById('download-btn');
const clearBtn = document.getElementById('clear-btn');

const providerContainer = document.getElementById('provider-container');
const addProviderBtn = document.getElementById('add-provider');
const saveApiBtn = document.getElementById('save-api');
const clearApiBtn = document.getElementById('clear-api');
const storageStatus = document.getElementById('storage-status');

// --- Metadata Viewer ---
const viewMetaBtn = document.getElementById('view-meta-btn-v2') || document.getElementById('view-meta-btn');
const metadataModal = document.getElementById('metadata-modal');
const metadataDisplay = document.getElementById('metadata-display');
const metadataFilename = document.getElementById('metadata-filename');
const closeMetadataModal = document.getElementById('close-metadata-modal');
const closeMetadataModalBtn = document.getElementById('close-metadata-modal-btn');
const metadataModalOverlay = document.getElementById('metadata-modal-overlay');

// --- Auth Check ---
async function initAuth() {
    if (window.DISABLE_SUPABASE_AUTH) {
        console.log("Renamer: Auth Bypass Active (Guest Mode)");
        // Hide auth UI if disabled
        const authSection = document.querySelector('.flex.items-center.gap-3.text-sm.border-r.border-slate-700.pr-5');
        if (authSection) {
            authSection.style.opacity = '0.3';
            authSection.title = "Supabase Auth is currently disabled by developer flag.";
        }
        return;
    }

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
        if (window.DISABLE_SUPABASE_AUTH) return;
        await window.auth.signOut();
        window.location.href = 'index.html';
    });
}

// --- Utils ---
function logToUi(message) {
    console.log(`[Log] ${message}`);
}

async function fetchPrompts() {
    try {
        const resp = await fetch('prompts.json');
        if (resp.ok) {
            PROMPTS = await resp.json();
            logToUi('External prompts loaded successfully.');
        }
    } catch (e) {
        console.warn('Could not load external prompts, using defaults.', e);
    }
}

// --- Provider Management ---
function createProviderRow(data = {}) {
    const row = document.createElement('div');
    row.className = 'provider-row grid md:grid-cols-12 gap-4 items-end p-4 bg-slate-800/20 rounded-xl border border-slate-700/50 relative';
    
    const providerType = data.type || 'openai';
    const models = MODEL_MAP[providerType] || [];
    
    row.innerHTML = `
        <div class="md:col-span-3 space-y-1">
            <label class="text-[10px] font-bold text-slate-500 uppercase">Provider</label>
            <select class="provider-type w-full rounded-lg px-3 py-2 text-sm focus:outline-none transition bg-slate-900 border border-slate-700">
                <option value="openai" ${providerType === 'openai' ? 'selected' : ''}>OpenAI</option>
                <option value="claude" ${providerType === 'claude' ? 'selected' : ''}>Anthropic (Claude)</option>
                <option value="gemini" ${providerType === 'gemini' ? 'selected' : ''}>Google (Gemini)</option>
                <option value="custom" ${providerType === 'custom' ? 'selected' : ''}>Custom Endpoint</option>
            </select>
        </div>
        <div class="md:col-span-3 space-y-1">
            <label class="text-[10px] font-bold text-slate-500 uppercase">Model</label>
            <div class="model-input-container">
                ${providerType === 'custom' 
                    ? `<input type="text" value="${data.model || ''}" placeholder="Enter model name" class="provider-model w-full rounded-lg px-3 py-2 text-sm focus:outline-none transition bg-slate-900 border border-slate-700">`
                    : `<select class="provider-model w-full rounded-lg px-3 py-2 text-sm focus:outline-none transition bg-slate-900 border border-slate-700">
                        ${models.map(m => `<option value="${m}" ${data.model === m ? 'selected' : ''}>${m}</option>`).join('')}
                       </select>`
                }
            </div>
        </div>
        <div class="md:col-span-4 space-y-1">
            <label class="text-[10px] font-bold text-slate-500 uppercase">API Key</label>
            <input type="password" value="${data.key || ''}" placeholder="sk-..." class="provider-key w-full rounded-lg px-3 py-2 text-sm focus:outline-none transition bg-slate-900 border border-slate-700">
        </div>
        <div class="md:col-span-1 flex items-center justify-center p-1">
            <label class="flex flex-col items-center gap-1 cursor-pointer">
                <span class="text-[10px] font-bold text-slate-500 uppercase">Active</span>
                <input type="radio" name="active-provider" ${data.active ? 'checked' : ''} class="provider-active w-4 h-4 accent-cyan-400">
            </label>
        </div>
        <div class="md:col-span-1 flex justify-end">
            <button class="remove-provider p-2 text-slate-500 hover:text-rose-400 transition" title="Remove Provider">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
            </button>
        </div>
        <div class="custom-url-container ${providerType === 'custom' ? '' : 'hidden'} md:col-span-12 mt-2 space-y-1">
            <label class="text-[10px] font-bold text-slate-500 uppercase">Custom API Base URL</label>
            <input type="text" value="${data.baseUrl || BASE_URL_MAP[providerType]}" placeholder="https://your-domain.com/v1" class="provider-base-url w-full rounded-lg px-3 py-2 text-sm focus:outline-none transition bg-slate-900 border border-slate-700">
        </div>
    `;

    // Event Listeners for the row
    const typeSelect = row.querySelector('.provider-type');
    const modelSelect = row.querySelector('.provider-model');
    const customUrlContainer = row.querySelector('.custom-url-container');
    const baseUrlInput = row.querySelector('.provider-base-url');

    typeSelect.addEventListener('change', (e) => {
        const type = e.target.value;
        const modelContainer = row.querySelector('.model-input-container');
        
        // Update models UI
        if (type === 'custom') {
            modelContainer.innerHTML = `<input type="text" placeholder="Enter model name" class="provider-model w-full rounded-lg px-3 py-2 text-sm focus:outline-none transition bg-slate-900 border border-slate-700">`;
        } else {
            const newModels = MODEL_MAP[type] || [];
            modelContainer.innerHTML = `<select class="provider-model w-full rounded-lg px-3 py-2 text-sm focus:outline-none transition bg-slate-900 border border-slate-700">
                ${newModels.map(m => `<option value="${m}">${m}</option>`).join('')}
            </select>`;
        }
        
        // Show/Hide Custom URL
        if (type === 'custom') {
            customUrlContainer.classList.remove('hidden');
        } else {
            customUrlContainer.classList.add('hidden');
        }
        baseUrlInput.value = BASE_URL_MAP[type] || '';
    });

    row.querySelector('.remove-provider').addEventListener('click', () => {
        if (document.querySelectorAll('.provider-row').length > 1) {
            row.remove();
        } else {
            alert("At least one provider is required.");
        }
    });

    return row;
}

function loadProviders() {
    providerContainer.innerHTML = '';
    let providers = JSON.parse(localStorage.getItem('pdf_renamer_providers') || '[]');
    
    // Migration from old single storage
    if (providers.length === 0) {
        const oldKey = localStorage.getItem('pdf_renamer_key');
        if (oldKey) {
            providers.push({
                type: 'openai',
                model: localStorage.getItem('pdf_renamer_model') || 'gpt-4o-mini',
                key: oldKey,
                baseUrl: localStorage.getItem('pdf_renamer_base') || BASE_URL_MAP.openai,
                active: true
            });
        } else {
            // Default empty state
            providers.push({ type: 'openai', model: 'gpt-4o-mini', key: '', baseUrl: BASE_URL_MAP.openai, active: true });
        }
    }

    providers.forEach(p => {
        providerContainer.appendChild(createProviderRow(p));
    });

    checkProviderWarning();
}

function checkProviderWarning() {
    const providerWarning = document.getElementById('provider-warning');
    if (!providerWarning) return;
    
    const rows = document.querySelectorAll('.provider-row');
    const hasKey = Array.from(rows).some(row => {
        const key = row.querySelector('.provider-key').value.trim();
        const active = row.querySelector('.provider-active').checked;
        return key !== '' && active;
    });
    
    if (!hasKey) {
        providerWarning.classList.remove('hidden');
    } else {
        providerWarning.classList.add('hidden');
    }
}

loadProviders();

if (addProviderBtn) {
    addProviderBtn.addEventListener('click', () => {
        providerContainer.appendChild(createProviderRow({ active: false }));
    });
}

if (saveApiBtn) {
    saveApiBtn.addEventListener('click', () => {
        const rows = document.querySelectorAll('.provider-row');
        const providers = Array.from(rows).map(row => ({
            type: row.querySelector('.provider-type').value,
            model: row.querySelector('.provider-model').value,
            key: row.querySelector('.provider-key').value,
            active: row.querySelector('.provider-active').checked,
            baseUrl: row.querySelector('.provider-base-url').value
        }));

        localStorage.setItem('pdf_renamer_providers', JSON.stringify(providers));
        
        checkProviderWarning();
        storageStatus.classList.remove('hidden');
        setTimeout(() => storageStatus.classList.add('hidden'), 3000);
        logToUi('Multiple providers saved to secure local storage.');
    });
}

if (clearApiBtn) {
    clearApiBtn.addEventListener('click', () => {
        if (confirm("Reset all AI configurations?")) {
            localStorage.removeItem('pdf_renamer_providers');
            loadProviders();
            logToUi('All provider configurations reset.');
        }
    });
}

// --- PDF Handling & Navigation ---
async function renderPage(pageNum) {
    if (!pdfDoc) return;
    currentPage = pageNum;
    
    // UI Updates
    pageIndicator.innerText = `${currentPage}/${totalPages}`;
    prevPageBtn.disabled = (currentPage <= 1);
    nextPageBtn.disabled = (currentPage >= totalPages);
    
    try {
        const page = await pdfDoc.getPage(pageNum);
        const viewport = page.getViewport({ scale: 1.2 });
        
        canvas.height = viewport.height;
        canvas.width = viewport.width;

        const renderContext = {
            canvasContext: ctx,
            viewport: viewport
        };
        await page.render(renderContext).promise;
        
        // Extract symbols/text for the *current* page
        const textContent = await page.getTextContent();
        extractedText = textContent.items.map(item => item.str).join(' ');
        
        logToUi(`Switched to page ${pageNum}. Text extraction ready.`);
    } catch (error) {
        logToUi(`Error rendering page ${pageNum}: ${error.message}`);
    }
}

if (prevPageBtn) prevPageBtn.addEventListener('click', () => {
    if (currentPage > 1) renderPage(currentPage - 1);
});

if (nextPageBtn) nextPageBtn.addEventListener('click', () => {
    if (currentPage < totalPages) renderPage(currentPage + 1);
});

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
        pdfDoc = await pdfjsLib.getDocument(typedarray).promise;
        totalPages = pdfDoc.numPages;
        
        if (previewPlaceholder) previewPlaceholder.style.display = 'none';
        if (pageHint) pageHint.classList.remove('hidden');

        await renderPage(1);
        updateFilenamePreview();
    };
    reader.readAsArrayBuffer(file);
}

if (dropZone) {
    dropZone.addEventListener('click', () => pdfInput.click());
    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
    });
}

if (pdfInput) {
    pdfInput.addEventListener('change', (e) => {
        if (e.target.files.length) handleFile(e.target.files[0]);
    });
}

// --- Filename Generation ---
function updateFilenamePreview() {
    if (!metaYear) return;
    const year = metaYear.value.trim();
    const author = metaAuthor.value.trim();
    
    // Prioritize Short Title for filename (Exclude Venue/Journal)
    let title = (metaShortTitle.value.trim() || metaTitle.value.trim())
        .replace(/[:\/\\|?*<>]/g, '') 
        .replace(/\s+/g, '_'); 

    if (!year && !author && !title) {
        if (previewFilename) previewFilename.innerText = '...waiting for input';
        return;
    }

    const nameParts = [];
    if (year) nameParts.push(year);
    if (author) nameParts.push(author);
    if (title) nameParts.push(title);

    const newName = nameParts.join('_') + '.pdf';
    if (previewFilename) previewFilename.innerText = newName;
    return newName;
}

if (metaYear && metaAuthor && metaVenue && metaTitle && metaShortTitle && metaAbstract) {
    [metaYear, metaAuthor, metaVenue, metaTitle, metaShortTitle, metaAbstract].forEach(el => {
        el.addEventListener('input', updateFilenamePreview);
    });
}

// --- AI Extraction ---
if (extractBtn) {
    extractBtn.addEventListener('click', async () => {
        const providers = JSON.parse(localStorage.getItem('pdf_renamer_providers') || '[]');
        const active = providers.find(p => p.active);

        if (!active || !active.key) {
            alert('Please configure and set an "Active" API provider.');
            return;
        }

        if (!extractedText) {
            alert('Please upload a PDF first.');
            return;
        }

        extractBtn.disabled = true;
        extractBtn.innerText = 'Extracting...';
        if (extractPageBtn) extractPageBtn.disabled = true;
        
        logToUi(`AI Extraction started from page ${currentPage} using ${active.type} (${active.model})`);

        try {
            // PROMPT 1: Basic Metadata
            const prompt1 = PROMPTS.metadata_prompt
                .replace('{{page}}', currentPage)
                .replace('{{text}}', extractedText.substring(0, 4000));

            const reqHeaders = {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${active.key}`,
                ...(active.type === 'claude' ? { 'anthropic-version': '2023-06-01' } : {})
            };

            const response1 = await fetch(`${active.baseUrl}/chat/completions`, {
                method: 'POST',
                headers: reqHeaders,
                body: JSON.stringify({
                    model: active.model,
                    messages: [{ role: 'user', content: prompt1 }],
                    response_format: { type: "json_object" }
                })
            });

            if (!response1.ok) throw new Error(`API Error (Prompt 1): ${response1.status}`);
            const data1 = await response1.json();
            const meta1 = JSON.parse(data1.choices[0].message.content);

            metaYear.value = meta1.year || '';
            metaAuthor.value = meta1.author || '';
            metaVenue.value = meta1.venue || '';
            metaTitle.value = meta1.title || '';
            metaShortTitle.value = meta1.short_title || ''; // Pre-calculated short title
            updateFilenamePreview();
            logToUi(`Prompt 1 complete: Metadata extracted.`);

            // PROMPT 2: Abstract & Short Title
            logToUi(`Starting Prompt 2: Abstract & AI Short Title...`);
            const prompt2 = PROMPTS.abstract_prompt
                .replace('{{page}}', currentPage)
                .replace('{{text}}', extractedText.substring(0, 4000));

            const response2 = await fetch(`${active.baseUrl}/chat/completions`, {
                method: 'POST',
                headers: reqHeaders,
                body: JSON.stringify({
                    model: active.model,
                    messages: [{ role: 'user', content: prompt2 }],
                    response_format: { type: "json_object" }
                })
            });

            if (!response2.ok) throw new Error(`API Error (Prompt 2): ${response2.status}`);
            const data2 = await response2.json();
            const meta2 = JSON.parse(data2.choices[0].message.content);

            metaAbstract.value = meta2.abstract || '';
            metaShortTitle.value = meta2.short_title || metaShortTitle.value;
            if (metaKeywords) metaKeywords.value = meta2.keywords || '';
            if (metaAllAuthors) metaAllAuthors.value = meta2.all_authors || '';

            updateFilenamePreview();
            logToUi(`Prompt 2 complete: Abstract, Short Title, Keywords and Authors extracted.`);
        } catch (error) {
            console.error(error);
            logToUi(`Extraction Error: ${error.message}`);
            alert('Failed to extract metadata. Check your API settings and quota.');
        } finally {
            extractBtn.disabled = false;
            extractBtn.innerText = 'Extract Fields';
            if (extractPageBtn) extractPageBtn.disabled = false;
        }
    });
}

// --- Download & Metadata Embedding ---
if (downloadBtn) {
    downloadBtn.addEventListener('click', async () => {
        if (!currentPdfBlob) { alert('Upload a PDF first!'); return; }

        const newName = updateFilenamePreview();
        downloadBtn.disabled = true;
        downloadBtn.innerText = 'Embedding Metadata...';

        try {
            logToUi(`Starting metadata embedding...`);

            // 1. Read the PDF blob as ArrayBuffer
            const arrBuffer = await currentPdfBlob.arrayBuffer();

            // 2. Load the PDF with pdf-lib
            const lib = window.PDFLib || PDFLib;
            if (!lib) throw new Error("PDF-Lib library not found. Please check your connection.");

            const pdfDocLib = await lib.PDFDocument.load(arrBuffer);

            // --- Collect field values ---
            const titleStr      = metaTitle.value.trim();
            const authorsStr    = (metaAllAuthors?.value?.trim()) || metaAuthor.value.trim();
            const firstAuthor   = metaAuthor.value.trim();
            const abstractStr   = metaAbstract.value.trim();
            const kwStr         = metaKeywords?.value?.trim() || '';
            const venueStr      = metaVenue.value.trim();
            const yearStr       = metaYear.value.trim();
            const shortTitle    = metaShortTitle?.value?.trim() || '';

            // --- Standard High-Level Fields (RAG field mapping) ---
            // Title   → full paper title
            // Author  → all authors (full names, comma-separated)
            // Subject → abstract ONLY (clean text for semantic search)
            // Keywords→ AI-extracted keywords (replaced, not appended)
            if (titleStr)    pdfDocLib.setTitle(titleStr);
            if (authorsStr)  pdfDocLib.setAuthor(authorsStr);
            if (abstractStr) pdfDocLib.setSubject(abstractStr);
            if (kwStr)       pdfDocLib.setKeywords(kwStr);

            // --- Write custom structured fields into Info Dictionary ---
            // The high-level calls above (setTitle etc.) internally call pdf-lib's
            // getInfoDict() which creates/stores the dict at context.trailerInfo.Info
            // (NOT context.trailer — that path is undefined for arXiv/pikepdf PDFs).
            // We access it via the same internal path so custom fields are guaranteed
            // to land in the same dict that gets saved.
            try {
                const infoRef = pdfDocLib.context.trailerInfo?.Info;
                const infoDict = infoRef ? pdfDocLib.context.lookup(infoRef) : null;

                if (infoDict) {
                    const setInfo = (key, val) => {
                        if (val) infoDict.set(lib.PDFName.of(key), lib.PDFString.of(val));
                    };

                    // Ensure standard fields are in sync (Acrobat fallback)
                    setInfo('Title',    titleStr);
                    setInfo('Author',   authorsStr);
                    setInfo('Subject',  abstractStr);
                    setInfo('Keywords', kwStr);

                    // Structured custom fields for RAG field-level queries
                    setInfo('Year',        yearStr);
                    setInfo('Venue',       venueStr);
                    setInfo('ShortTitle',  shortTitle);
                    setInfo('FirstAuthor', firstAuthor);

                    logToUi("Info Dictionary: all structured fields written.");
                } else {
                    logToUi("Info Dictionary: not accessible, standard fields only.");
                }
            } catch (e) {
                console.warn("Manual InfoDict update failed, relying on high-level API.", e);
            }

            // 3. Save and Download
            const modifiedPdfBytes = await pdfDocLib.save();
            const modifiedBlob = new Blob([modifiedPdfBytes], { type: 'application/pdf' });

            const url = URL.createObjectURL(modifiedBlob);
            const a = document.createElement('a');
            a.href = url;
            a.download = newName;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            logToUi(`SUCCESS: Full metadata embedded in ${newName}`);
        } catch (err) {
            console.error(err);
            logToUi(`CRITICAL ERROR (Using Rename Fallback): ${err.message}`);
            // Fallback: Download original file but with new name
            const url = URL.createObjectURL(currentPdfBlob);
            const a = document.createElement('a');
            a.href = url;
            a.download = newName;
            a.click();
            URL.revokeObjectURL(url);
        } finally {
            downloadBtn.disabled = false;
            downloadBtn.innerText = 'Download PDF';
        }
    });
}

if (clearBtn) {
    clearBtn.addEventListener('click', () => {
        pdfDoc = null; totalPages = 0; currentPage = 1;
        currentPdfBlob = null; extractedText = "";
        metaYear.value = ""; metaAuthor.value = ""; metaVenue.value = ""; metaTitle.value = "";
        metaShortTitle.value = ""; metaAbstract.value = "";
        if (metaKeywords) metaKeywords.value = "";
        if (metaAllAuthors) metaAllAuthors.value = "";
        if (previewPlaceholder) previewPlaceholder.style.display = 'block';
        if (pageHint) pageHint.classList.add('hidden');
        if (canvas) ctx.clearRect(0, 0, canvas.width, canvas.height);
        pageIndicator.innerText = "0/0";
        updateFilenamePreview();
        if (previewFilename) previewFilename.innerText = 'All cleared';
        logToUi('All fields cleared.');
    });
}

if (extractPageBtn) {
    extractPageBtn.addEventListener('click', () => {
        if (extractBtn) extractBtn.click();
    });
}

// --- Metadata Inspection Logic ---
async function extractFullMetadata() {
    if (!currentPdfBlob) {
        alert('Please upload a PDF first to inspect its metadata.');
        return;
    }

    metadataDisplay.value = "Analyzing PDF structure and extracting raw metadata... Please wait.";
    metadataModal.classList.remove('hidden');
    metadataFilename.innerText = currentPdfBlob.name;

    try {
        const arrBuffer = await currentPdfBlob.arrayBuffer();
        const lib = window.PDFLib || PDFLib;
        if (!lib) throw new Error("PDF-Lib not found.");
        
        const pdfDocLib = await lib.PDFDocument.load(arrBuffer, { 
            updateMetadata: false, 
            ignoreEncryption: true 
        });

        let output = `PDF METADATA ANALYSIS REPORT\n`;
        output += `============================\n`;
        output += `File: ${currentPdfBlob.name}\n`;
        output += `Size: ${(currentPdfBlob.size / 1024).toFixed(2)} KB\n`;
        output += `Pages: ${pdfDocLib.getPageCount()}\n`;
        output += `\n`;

        // 1. High-Level PDF-Lib Metadata
        output += `[1] STANDARD FIELDS (High-Level API)\n`;
        output += `------------------------------------\n`;
        output += `Title:    ${pdfDocLib.getTitle() || 'None'}\n`;
        output += `Author:   ${pdfDocLib.getAuthor() || 'None'}\n`;
        output += `Subject:  ${pdfDocLib.getSubject() || 'None'}\n`;
        output += `Creator:  ${pdfDocLib.getCreator() || 'None'}\n`;
        output += `Producer: ${pdfDocLib.getProducer() || 'None'}\n`;
        output += `Keywords: ${pdfDocLib.getKeywords() || 'None'}\n`;
        output += `Created:  ${pdfDocLib.getCreationDate() || 'None'}\n`;
        output += `Modified: ${pdfDocLib.getModificationDate() || 'None'}\n`;
        output += `\n`;

        // 2. Info Dictionary (Raw)
        output += `[2] INFO DICTIONARY (Raw Entries)\n`;
        output += `---------------------------------\n`;
        try {
            const trailer = pdfDocLib.context.trailer;
            const infoRef = (trailer && typeof trailer.get === 'function') ? trailer.get(lib.PDFName.of('Info')) : null;
            
            if (infoRef) {
                const infoDict = pdfDocLib.context.lookup(infoRef);
                if (infoDict && infoDict.entries && typeof infoDict.entries === 'function') {
                    for (const [key, value] of infoDict.entries()) {
                        const keyStr = typeof key.asString === 'function' ? key.asString() : key.toString();
                        output += `${keyStr.padEnd(12)} : ${value.toString()}\n`;
                    }
                } else if (infoDict) {
                    output += `Info dictionary found (raw): ${infoDict.toString()}\n`;
                } else {
                    output += `No entries found in Info dictionary.\n`;
                }
            } else {
                output += !trailer ? `PDF context trailer is undefined (possibly non-standard format).\n` : `No '/Info' dictionary reference found in trailer.\n`;
            }
        } catch (e) {
            output += `Error reading Info Dictionary: ${e.message}\n`;
        }
        output += `\n`;

        // 3. XMP Metadata Stream
        output += `[3] XMP METADATA STREAM\n`;
        output += `-----------------------\n`;
        try {
            // Direct access to the Root/Catalog is more robust than trailer traversal
            const catalog = pdfDocLib.catalog; 
            const metadataStreamRef = (catalog && typeof catalog.get === 'function') ? catalog.get(lib.PDFName.of('Metadata')) : null;
            
            if (metadataStreamRef) {
                const metadataStream = pdfDocLib.context.lookup(metadataStreamRef);
                if (metadataStream && typeof metadataStream.getUncompressedContents === 'function') {
                    const xmpContent = new TextDecoder().decode(metadataStream.getUncompressedContents());
                    output += xmpContent;
                } else if (metadataStream) {
                    output += `Metadata reference found but stream is unreadable or not a stream: ${metadataStream.toString()}\n`;
                } else {
                    output += `Root contains Metadata reference but stream is empty/null.\n`;
                }
            } else {
                output += catalog ? `No '/Metadata' stream found in PDF root catalog.\n` : `PDF root catalog could not be localized.\n`;
            }
        } catch (e) {
            output += `Error reading XMP Stream: ${e.message}\n`;
        }

        metadataDisplay.value = output;
        logToUi(`Full metadata inspection complete for ${currentPdfBlob.name}`);

    } catch (err) {
        console.error(err);
        metadataDisplay.value = `CRITICAL ERROR DURING ANALYSIS:\n${err.message}\n\nStack Trace:\n${err.stack}`;
        logToUi(`Metadata extraction failed: ${err.message}`);
    }
}

// Modal Toggle Logic
if (viewMetaBtn) {
    viewMetaBtn.addEventListener('click', extractFullMetadata);
}

const hideMetadataModal = () => metadataModal.classList.add('hidden');
if (closeMetadataModal) closeMetadataModal.addEventListener('click', hideMetadataModal);
if (closeMetadataModalBtn) closeMetadataModalBtn.addEventListener('click', hideMetadataModal);
if (metadataModalOverlay) metadataModalOverlay.addEventListener('click', hideMetadataModal);

// Close modal on Escape key
window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !metadataModal.classList.contains('hidden')) {
        hideMetadataModal();
    }
});

// --- Init ---
initAuth();
loadProviders();
fetchPrompts();
updateFilenamePreview();
