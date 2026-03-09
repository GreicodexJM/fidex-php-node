/**
 * FideX QR Onboarding Webapp — TypeScript Source
 *
 * Compiled to: public/onboarding/app.js
 * Run: npm run build  (or: make build-webapp)
 *
 * No framework. Strict TypeScript. Zero CDN deps (libs vendored).
 */

// ── Type declarations for vendored libs ─────────────────────────────────────

declare function qrcode(typeNumber: number, errorCorrectionLevel: string): {
  addData(data: string): void;
  make(): void;
  createImgTag(cellSize?: number, margin?: number): string;
  createSvgTag(cellSize?: number, margin?: number): string;
};

declare const jsQR: (
  data: Uint8ClampedArray,
  width: number,
  height: number,
  options?: { inversionAttempts?: string }
) => { data: string } | null;

// ── API Types ────────────────────────────────────────────────────────────────

interface NodeInfo {
  fidex_version: string;
  node_id: string;
  node_name: string;
  node_base_url: string;
  as5_config_url: string;
  qr_data: string;
  keys_configured: boolean;
  partner_count: number;
  queued_jobs: number;
  implementation: string;
}

interface RegisterResult {
  partner_id?: string;
  name?: string;
  error?: string;
  message?: string;
}

// ── State ────────────────────────────────────────────────────────────────────

let videoStream: MediaStream | null = null;
let scanAnimFrame: number | null = null;
let currentTab: 'scan' | 'manual' = 'scan';
let scannedAs5Url: string | null = null;

// ── DOM helpers ──────────────────────────────────────────────────────────────

function el<T extends HTMLElement>(id: string): T {
  return document.getElementById(id) as T;
}

function show(id: string): void { el(id).hidden = false; }
function hide(id: string): void { el(id).hidden = true; }
function text(id: string, value: string): void { el(id).textContent = value; }

function showAlert(msg: string, isError = false): void {
  const banner = el('alertBanner');
  el('alertMessage').textContent = msg;
  el('alertIcon').textContent = isError ? '✗' : '⚠';
  banner.style.borderColor = isError ? 'var(--error)' : 'var(--warning)';
  banner.style.background = isError ? 'rgba(239,68,68,.12)' : 'rgba(245,158,11,.12)';
  banner.hidden = false;
}

// ── Initialisation ───────────────────────────────────────────────────────────

async function init(): Promise<void> {
  await loadNodeInfo();
  restoreApiKey();
  el('copyUrlBtn').addEventListener('click', copyAs5Url);
  el('registerScannedBtn').addEventListener('click', () => {
    if (scannedAs5Url) registerPartner(scannedAs5Url);
  });
  el('registerManualBtn').addEventListener('click', registerManual);
}

// ── Load node info ───────────────────────────────────────────────────────────

async function loadNodeInfo(): Promise<void> {
  try {
    const resp = await fetch('/api/v1/node-info');
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const info: NodeInfo = await resp.json();
    renderNodeInfo(info);
  } catch (err) {
    setStatus('error', 'Node unreachable');
    showAlert(`Could not load node info: ${String(err)}`, true);
  }
}

function renderNodeInfo(info: NodeInfo): void {
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
  } else {
    hide('qrPlaceholder');
    show('qrCanvas');
    show('keysWarning');
    el('qrCanvas').innerHTML =
      '<div style="text-align:center;padding:2rem;color:var(--warning)">⚠ Keys not configured</div>';
    show('qrCanvas');
  }
}

function setStatus(state: 'loading' | 'online' | 'error', label: string): void {
  const dot = el('nodeStatus').querySelector('.status-dot') as HTMLElement;
  dot.className = `status-dot ${state}`;
  text('statusLabel', label);
}

// ── QR Code generation ───────────────────────────────────────────────────────

function renderQrCode(data: string): void {
  try {
    const qr = qrcode(0, 'M');
    qr.addData(data);
    qr.make();
    el('qrCanvas').innerHTML = qr.createImgTag(5, 2);
  } catch {
    // If auto type-number fails, try with explicit type 10
    try {
      const qr = qrcode(10, 'M');
      qr.addData(data);
      qr.make();
      el('qrCanvas').innerHTML = qr.createImgTag(4, 2);
    } catch (e2) {
      el('qrCanvas').innerHTML =
        '<p style="color:var(--error)">QR generation failed: ' + String(e2) + '</p>';
    }
  }
}

// ── Copy AS5 URL ─────────────────────────────────────────────────────────────

function copyAs5Url(): void {
  const url = el<HTMLSpanElement>('qrUrl').textContent ?? '';
  if (!url) return;
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

function switchTab(tab: 'scan' | 'manual'): void {
  currentTab = tab;
  el('tabScan').classList.toggle('active', tab === 'scan');
  el('tabManual').classList.toggle('active', tab === 'manual');
  el('scanPanel').hidden = tab !== 'scan';
  el('manualPanel').hidden = tab !== 'manual';
  el('registerManualBtn').hidden = tab !== 'manual';
  hideResults();

  if (tab !== 'scan') stopCamera();
}

// ── Camera / QR Scanning ─────────────────────────────────────────────────────

async function startCamera(): Promise<void> {
  hide('scanResult');
  scannedAs5Url = null;

  try {
    videoStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 480 } }
    });
    const video = el<HTMLVideoElement>('scanVideo');
    video.srcObject = videoStream;
    await video.play();

    show('stopCamBtn');
    hide('startCamBtn');
    scanLoop();
  } catch (err) {
    showAlert('Camera access denied or unavailable. Use the Manual tab instead.', true);
  }
}

function stopCamera(): void {
  if (scanAnimFrame !== null) {
    cancelAnimationFrame(scanAnimFrame);
    scanAnimFrame = null;
  }
  if (videoStream) {
    videoStream.getTracks().forEach(t => t.stop());
    videoStream = null;
  }
  const video = el<HTMLVideoElement>('scanVideo');
  video.srcObject = null;

  show('startCamBtn');
  hide('stopCamBtn');
}

function scanLoop(): void {
  const video = el<HTMLVideoElement>('scanVideo');
  const canvas = el<HTMLCanvasElement>('scanCanvas');
  const ctx = canvas.getContext('2d');

  if (!ctx || video.readyState !== video.HAVE_ENOUGH_DATA) {
    scanAnimFrame = requestAnimationFrame(scanLoop);
    return;
  }

  canvas.width  = video.videoWidth;
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
    } else if (detected.startsWith('http')) {
      // Accept any URL — might be an AS5 config URL without "as5" in path
      stopCamera();
      onQrDetected(detected);
      return;
    }
  }

  scanAnimFrame = requestAnimationFrame(scanLoop);
}

function onQrDetected(url: string): void {
  scannedAs5Url = url;
  text('scannedUrl', url);
  show('scanResult');
}

// ── Partner Registration ──────────────────────────────────────────────────────

function getApiKey(): string {
  const key = el<HTMLInputElement>('apiKeyInput').value.trim();
  sessionStorage.setItem('fidex_api_key', key);
  return key;
}

function restoreApiKey(): void {
  const saved = sessionStorage.getItem('fidex_api_key');
  if (saved) el<HTMLInputElement>('apiKeyInput').value = saved;
}

function registerManual(): void {
  const url = el<HTMLInputElement>('partnerUrl').value.trim();
  if (!url) { showAlert('Please enter the partner AS5 Config URL.'); return; }
  registerPartner(url);
}

async function registerPartner(as5ConfigUrl: string): Promise<void> {
  const apiKey = getApiKey();
  if (!apiKey) { showAlert('Please enter your API key.'); return; }

  hideResults();

  const registerBtn = el(currentTab === 'scan' ? 'registerScannedBtn' : 'registerManualBtn') as HTMLButtonElement;
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

    const data: RegisterResult = await resp.json();

    if (resp.ok) {
      const name = data.name ?? data.partner_id ?? 'Partner';
      text('registerSuccessMsg', `✓ "${name}" registered successfully!`);
      show('registerSuccess');
      hide('registerError');
      // Refresh partner count
      await loadNodeInfo();
    } else {
      text('registerErrorMsg', data.message ?? data.error ?? `HTTP ${resp.status}`);
      show('registerError');
      hide('registerSuccess');
    }
  } catch (err) {
    text('registerErrorMsg', `Network error: ${String(err)}`);
    show('registerError');
    hide('registerSuccess');
  } finally {
    registerBtn.disabled = false;
    registerBtn.textContent = currentTab === 'scan' ? 'Register Partner' : '🤝 Register Partner';
  }
}

function hideResults(): void {
  hide('registerSuccess');
  hide('registerError');
}

// ── Bootstrap ────────────────────────────────────────────────────────────────

// Make functions available globally (called from HTML onclick attributes)
(window as unknown as Record<string, unknown>)['switchTab'] = switchTab;
(window as unknown as Record<string, unknown>)['startCamera'] = startCamera;
(window as unknown as Record<string, unknown>)['stopCamera'] = stopCamera;
(window as unknown as Record<string, unknown>)['registerManual'] = registerManual;

document.addEventListener('DOMContentLoaded', () => { void init(); });
