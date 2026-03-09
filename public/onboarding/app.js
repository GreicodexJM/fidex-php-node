"use strict";
/**
 * FideX QR Onboarding Webapp — TypeScript Source
 *
 * Compiled to: public/onboarding/app.js
 * Run: npm run build  (or: make build-webapp)
 *
 * No framework. Strict TypeScript. Zero CDN deps (libs vendored).
 */
// ── State ────────────────────────────────────────────────────────────────────
let videoStream = null;
let scanAnimFrame = null;
let currentTab = 'scan';
let scannedAs5Url = null;
// ── DOM helpers ──────────────────────────────────────────────────────────────
function el(id) {
    return document.getElementById(id);
}
function show(id) { el(id).hidden = false; }
function hide(id) { el(id).hidden = true; }
function text(id, value) { el(id).textContent = value; }
function showAlert(msg, isError = false) {
    const banner = el('alertBanner');
    el('alertMessage').textContent = msg;
    el('alertIcon').textContent = isError ? '✗' : '⚠';
    banner.style.borderColor = isError ? 'var(--error)' : 'var(--warning)';
    banner.style.background = isError ? 'rgba(239,68,68,.12)' : 'rgba(245,158,11,.12)';
    banner.hidden = false;
}
// ── Initialisation ───────────────────────────────────────────────────────────
async function init() {
    await loadNodeInfo();
    restoreApiKey();
    el('copyUrlBtn').addEventListener('click', copyAs5Url);
    el('registerScannedBtn').addEventListener('click', () => {
        if (scannedAs5Url)
            registerPartner(scannedAs5Url);
    });
    el('registerManualBtn').addEventListener('click', registerManual);
}
// ── Load node info ───────────────────────────────────────────────────────────
async function loadNodeInfo() {
    try {
        const resp = await fetch('/api/v1/node-info');
        if (!resp.ok)
            throw new Error(`HTTP ${resp.status}`);
        const info = await resp.json();
        renderNodeInfo(info);
    }
    catch (err) {
        setStatus('error', 'Node unreachable');
        showAlert(`Could not load node info: ${String(err)}`, true);
    }
}
function renderNodeInfo(info) {
    // Header status
    setStatus('online', info.node_name || info.node_id);
    // Node bar
    text('nodeId', info.node_id);
    text('nodeName', info.node_name);
    text('partnerCount', String(info.partner_count));
    text('queueCount', String(info.queued_jobs));
    show('nodeBar');
    // QR Code
    if (info.keys_configured) {
        renderQrCode(info.qr_data);
        text('qrUrl', info.qr_data);
        show('qrUrlRow');
        hide('qrPlaceholder');
        show('qrCanvas');
    }
    else {
        hide('qrPlaceholder');
        show('qrCanvas');
        show('keysWarning');
        el('qrCanvas').innerHTML =
            '<div style="text-align:center;padding:2rem;color:var(--warning)">⚠ Keys not configured</div>';
        show('qrCanvas');
    }
}
function setStatus(state, label) {
    const dot = el('nodeStatus').querySelector('.status-dot');
    dot.className = `status-dot ${state}`;
    text('statusLabel', label);
}
// ── QR Code generation ───────────────────────────────────────────────────────
function renderQrCode(data) {
    try {
        const qr = qrcode(0, 'M');
        qr.addData(data);
        qr.make();
        el('qrCanvas').innerHTML = qr.createImgTag(5, 2);
    }
    catch (_a) {
        // If auto type-number fails, try with explicit type 10
        try {
            const qr = qrcode(10, 'M');
            qr.addData(data);
            qr.make();
            el('qrCanvas').innerHTML = qr.createImgTag(4, 2);
        }
        catch (e2) {
            el('qrCanvas').innerHTML =
                '<p style="color:var(--error)">QR generation failed: ' + String(e2) + '</p>';
        }
    }
}
// ── Copy AS5 URL ─────────────────────────────────────────────────────────────
function copyAs5Url() {
    var _a;
    const url = (_a = el('qrUrl').textContent) !== null && _a !== void 0 ? _a : '';
    if (!url)
        return;
    navigator.clipboard.writeText(url).then(() => {
        const btn = el('copyUrlBtn');
        btn.textContent = '✓ Copied';
        setTimeout(() => { btn.textContent = 'Copy'; }, 2000);
    }).catch(() => {
        // Fallback: prompt
        window.prompt('Copy this URL:', url);
    });
}
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(tab) {
    currentTab = tab;
    el('tabScan').classList.toggle('active', tab === 'scan');
    el('tabManual').classList.toggle('active', tab === 'manual');
    el('scanPanel').hidden = tab !== 'scan';
    el('manualPanel').hidden = tab !== 'manual';
    el('registerManualBtn').hidden = tab !== 'manual';
    hideResults();
    if (tab !== 'scan')
        stopCamera();
}
// ── Camera / QR Scanning ─────────────────────────────────────────────────────
async function startCamera() {
    hide('scanResult');
    scannedAs5Url = null;
    try {
        videoStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 480 } }
        });
        const video = el('scanVideo');
        video.srcObject = videoStream;
        await video.play();
        show('stopCamBtn');
        hide('startCamBtn');
        scanLoop();
    }
    catch (err) {
        showAlert('Camera access denied or unavailable. Use the Manual tab instead.', true);
    }
}
function stopCamera() {
    if (scanAnimFrame !== null) {
        cancelAnimationFrame(scanAnimFrame);
        scanAnimFrame = null;
    }
    if (videoStream) {
        videoStream.getTracks().forEach(t => t.stop());
        videoStream = null;
    }
    const video = el('scanVideo');
    video.srcObject = null;
    show('startCamBtn');
    hide('stopCamBtn');
}
function scanLoop() {
    const video = el('scanVideo');
    const canvas = el('scanCanvas');
    const ctx = canvas.getContext('2d');
    if (!ctx || video.readyState !== video.HAVE_ENOUGH_DATA) {
        scanAnimFrame = requestAnimationFrame(scanLoop);
        return;
    }
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const result = jsQR(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: 'dontInvert',
    });
    if (result && result.data) {
        const detected = result.data.trim();
        if (detected.startsWith('http') && detected.includes('as5')) {
            stopCamera();
            onQrDetected(detected);
            return;
        }
        else if (detected.startsWith('http')) {
            // Accept any URL — might be an AS5 config URL without "as5" in path
            stopCamera();
            onQrDetected(detected);
            return;
        }
    }
    scanAnimFrame = requestAnimationFrame(scanLoop);
}
function onQrDetected(url) {
    scannedAs5Url = url;
    text('scannedUrl', url);
    show('scanResult');
}
// ── Partner Registration ──────────────────────────────────────────────────────
function getApiKey() {
    const key = el('apiKeyInput').value.trim();
    sessionStorage.setItem('fidex_api_key', key);
    return key;
}
function restoreApiKey() {
    const saved = sessionStorage.getItem('fidex_api_key');
    if (saved)
        el('apiKeyInput').value = saved;
}
function registerManual() {
    const url = el('partnerUrl').value.trim();
    if (!url) {
        showAlert('Please enter the partner AS5 Config URL.');
        return;
    }
    registerPartner(url);
}
async function registerPartner(as5ConfigUrl) {
    var _a, _b, _c, _d;
    const apiKey = getApiKey();
    if (!apiKey) {
        showAlert('Please enter your API key.');
        return;
    }
    hideResults();
    const registerBtn = el(currentTab === 'scan' ? 'registerScannedBtn' : 'registerManualBtn');
    registerBtn.disabled = true;
    registerBtn.textContent = '⏳ Registering…';
    try {
        const resp = await fetch('/api/v1/partners/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${apiKey}`,
            },
            body: JSON.stringify({ as5_config_url: as5ConfigUrl }),
        });
        const data = await resp.json();
        if (resp.ok) {
            const name = (_b = (_a = data.name) !== null && _a !== void 0 ? _a : data.partner_id) !== null && _b !== void 0 ? _b : 'Partner';
            text('registerSuccessMsg', `✓ "${name}" registered successfully!`);
            show('registerSuccess');
            hide('registerError');
            // Refresh partner count
            await loadNodeInfo();
        }
        else {
            text('registerErrorMsg', (_d = (_c = data.message) !== null && _c !== void 0 ? _c : data.error) !== null && _d !== void 0 ? _d : `HTTP ${resp.status}`);
            show('registerError');
            hide('registerSuccess');
        }
    }
    catch (err) {
        text('registerErrorMsg', `Network error: ${String(err)}`);
        show('registerError');
        hide('registerSuccess');
    }
    finally {
        registerBtn.disabled = false;
        registerBtn.textContent = currentTab === 'scan' ? 'Register Partner' : '🤝 Register Partner';
    }
}
function hideResults() {
    hide('registerSuccess');
    hide('registerError');
}
// ── Bootstrap ────────────────────────────────────────────────────────────────
// Make functions available globally (called from HTML onclick attributes)
window['switchTab'] = switchTab;
window['startCamera'] = startCamera;
window['stopCamera'] = stopCamera;
window['registerManual'] = registerManual;
document.addEventListener('DOMContentLoaded', () => { void init(); });
