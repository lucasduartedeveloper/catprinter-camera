<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>MXW01 Mobile Camera</title>
    <style>
        body { font-family: sans-serif; display: flex; flex-direction: column; align-items: center; background: #f0f0f0; margin: 0; padding: 20px; touch-action: manipulation; }
        #canvasContainer { position: relative; width: 300px; height: 300px; background: #000; border: 2px solid #333; overflow: hidden; border-radius: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.2); margin-bottom: 15px; }
        canvas { width: 300px; height: 300px; display: block; cursor: crosshair; }
        
        /* Nova linha de botões de modo */
        .mode-selector { display: flex; gap: 5px; width: 300px; margin-bottom: 15px; }
        .mode-selector button { flex: 1; padding: 10px 5px; font-size: 12px; background: #555; }
        .mode-selector button.active-mode { background: #007bff; }

        .controls { display: flex; flex-direction: column; gap: 10px; width: 300px; transition: transform 0.3s ease; }
        
        /* Container para ocultar configs */
        #collapsibleSettings { display: flex; flex-direction: column; gap: 10px; overflow: hidden; transition: all 0.3s ease; }
        .settings-hidden { transform: translateX(120%); position: absolute; }

        button { padding: 12px; font-size: 16px; border: none; border-radius: 5px; cursor: pointer; background: #007bff; color: white; font-weight: bold; }
        button:active { filter: brightness(0.8); }
        .config-row { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 8px 12px; border-radius: 5px; font-size: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        select, input { padding: 5px; border: 1px solid #ccc; border-radius: 4px; }
        
        #logOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #1a1a1a; z-index: 1000; display: none; flex-direction: column; }
        #logContent { flex: 1; padding: 15px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 11px; color: #00ff00; }
        .active { display: flex !important; }
        #progressBarContainer { height: 6px; background: #333; width: 100%; }
        #progressBar { height: 100%; background: #4caf50; width: 0%; transition: width 0.1s; }
        .close-btn { background: #ff4444; border-radius: 0; padding: 15px; }
        
        .flex-group { display: flex; align-items: center; gap: 5px; }
        #timerDisplay { font-size: 24px; font-weight: bold; color: black; margin-bottom: 5px; height: 30px; }
    </style>
</head>
<body>

    <div id="timerDisplay"></div>
    <div id="canvasContainer">
        <canvas id="cameraCanvas" width="300" height="300"></canvas>
    </div>

    <div class="mode-selector">
        <button id="btnModeCam">Câmera</button>
        <button id="btnModeUpload">Upload</button>
        <button id="btnModeDraw">Desenho</button>
        <button id="btnModeStream">Stream</button>
    </div>

    <div class="controls" id="mainControls">
        <div id="collapsibleSettings">
            <div class="config-row">
                <span>Dithering:</span>
                <select id="ditherMode">
                    <option value="threshold">Simples (Threshold)</option>
                    <option value="floydSteinberg" selected>Floyd-Steinberg</option>
                    <option value="stucki">Stucki (Qualidade Foto)</option>
                    <option value="atkinson">Atkinson</option>
                    <option value="bayer">Bayer Matrix</option>
                    <option value="halftone">Halftone</option>
                    <option value="none">Nenhum (Grayscale)</option>
                </select>
            </div>
            <div class="config-row">
                <span>Threshold:</span>
                <div class="flex-group">
                    <input type="number" id="threshold" value="128" min="0" max="255" style="width: 50px;">
                    <select id="rotation">
                        <option value="0">0°</option>
                        <option value="-90">-90°</option>
                        <option value="-180">-180°</option>
                        <option value="-270">-270°</option>
                    </select>
                </div>
            </div>
            <button id="btnConnect">1. Conectar Impressora</button>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <button id="btnToggleCamera" style="flex: 1;">Pausar/Play Câmera</button>
            <select id="timerSelect">
                <option value="0">0s</option>
                <option value="500">500ms</option>
                <option value="1000">1s</option>
                <option value="5000">5s</option>
                <option value="10000">10s</option>
                <option value="20000">20s</option>
            </select>
            <label style="font-size: 14px; display: flex; align-items: center; gap: 4px; background: white; padding: 10px; border-radius: 5px; height: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <input type="checkbox" id="autoPause"> auto
            </label>
        </div>
        <button id="btnPrint" style="background: #28a745;">2. IMPRIMIR AGORA</button>
        <button id="btnOpenLogs" style="background: #6c757d;">Ver Logs</button>
    </div>

    <div id="logOverlay">
        <div id="progressBarContainer"><div id="progressBar"></div></div>
        <div id="logContent"></div>
        <button id="btnCloseLogs" class="close-btn">FECHAR LOGS</button>
    </div>

    <input type="file" id="fileInput" accept="image/*" style="display: none;">

    <script type="module">
        import { connectPrinter, printImage, isPrinterConnected } from './js/printer.js';
        import { setupLoggerUI, logger } from './js/logger.js';
        import * as imageProcessor from './js/imageProcessor.js';

        const canvas = document.getElementById('cameraCanvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const fileInput = document.getElementById('fileInput');
        const timerDisplay = document.getElementById('timerDisplay');
        const settingsDiv = document.getElementById('collapsibleSettings');
        
        let video = document.createElement('video');
        let stream = null;
        let isCameraRunning = false;
        let facingMode = 'user'; 
        let isMirrored = true;   
        let animationId = null;
        let lastTap = 0;
        let touchStartX = 0, touchStartY = 0;
        let countdownInterval = null;

        // Estados de Modo
        let currentAppMode = 'camera'; // camera, draw, stream, upload
        let isDrawing = false;
        
        // Estado Stream e Zoom
        let streamInterval = null;
        let streamRoom = "";
        let isStreamPaused = false;
        let streamZoom = 1;
        let streamOffsetX = 0;
        let streamOffsetY = 0;
        let initialPinchDist = 0;
        let lastTouchX = 0, lastTouchY = 0;

        setupLoggerUI(document.getElementById('logContent'), document.getElementById('progressBar'));

        function updateProcessorSettings() {
            const threshold = parseInt(document.getElementById('threshold').value);
            const ditherMethod = document.getElementById('ditherMode').value;
            const rotation = parseInt(document.getElementById('rotation').value);
            imageProcessor.updateSettings({ threshold, ditherMethod, rotation });
        }

        document.getElementById('threshold').addEventListener('blur', updateProcessorSettings);
        document.getElementById('ditherMode').addEventListener('change', updateProcessorSettings);
        document.getElementById('rotation').addEventListener('change', updateProcessorSettings);

        // --- GESTÃO DE MODOS ---
        function setMode(mode) {
            stopCamera();
            clearInterval(streamInterval);
            currentAppMode = mode;
            ctx.setTransform(1, 0, 0, 1, 0, 0); // Reset transform
            
            document.querySelectorAll('.mode-selector button').forEach(b => b.classList.remove('active-mode'));
            
            if (mode === 'camera') {
                document.getElementById('btnModeCam').classList.add('active-mode');
                logger.info("Modo Câmera Ativo (Aguardando Play)");
            } else if (mode === 'draw') {
                document.getElementById('btnModeDraw').classList.add('active-mode');
                clearCanvas();
                logger.info("Modo Desenho Ativo");
            } else if (mode === 'stream') {
                document.getElementById('btnModeStream').classList.add('active-mode');
                initStream();
            }
        }

        // --- CÂMERA ---
        async function startCamera() {
            try {
                if (stream) stream.getTracks().forEach(t => t.stop());
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: facingMode, width: 640, height: 640 }
                });
                video.srcObject = stream;
                video.play();
                isCameraRunning = true;
                drawFrame();
            } catch (err) { logger.error("Erro câmera", err); }
        }

        function stopCamera() {
            isCameraRunning = false;
            cancelAnimationFrame(animationId);
            if (stream) stream.getTracks().forEach(t => t.stop());
            stream = null;
            timerDisplay.textContent = "";
            if(countdownInterval) clearInterval(countdownInterval);
        }

        function drawFrame() {
            if (!isCameraRunning || currentAppMode !== 'camera') return;
            ctx.save();
            if (isMirrored) { ctx.translate(canvas.width, 0); ctx.scale(-1, 1); }
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            ctx.restore();
            animationId = requestAnimationFrame(drawFrame);
        }

        // --- STREAM ---
        function initStream() {
            const name = prompt("Nome da sala de Stream:");
            if (name) {
                streamRoom = name;
                isStreamPaused = false;
                streamZoom = 1; streamOffsetX = 0; streamOffsetY = 0;
                startStreamInterval();
            }
        }

        function startStreamInterval() {
            clearInterval(streamInterval);
            streamInterval = setInterval(loadStreamImage, 3000);
            loadStreamImage();
        }

        function loadStreamImage() {
            if (isStreamPaused || currentAppMode !== 'stream') return;
            const rand = Math.floor(Math.random() * 999999);
            const img = new Image();
            img.crossOrigin = "anonymous";
            img.onload = () => {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.save();
                ctx.translate(canvas.width/2 + streamOffsetX, canvas.height/2 + streamOffsetY);
                ctx.scale(streamZoom, streamZoom);
                ctx.drawImage(img, -canvas.width/2, -canvas.height/2, canvas.width, canvas.height);
                ctx.restore();
            };
            img.src = `https://jpeg.live.mmcdn.com/stream?room=${streamRoom}&f=${rand}`;
        }

        // --- DESENHO ---
        function clearCanvas() {
            ctx.fillStyle = "white";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        }

        // --- INPUTS E ARRASTAR ---
        let dragStartX = 0;
        settingsDiv.addEventListener('touchstart', (e) => dragStartX = e.touches[0].clientX);
        settingsDiv.addEventListener('touchend', (e) => {
            if (e.changedTouches[0].clientX - dragStartX > 80) {
                settingsDiv.classList.add('settings-hidden');
                logger.info("Configurações ocultas (Arraste para esquerda para voltar)");
            }
        });
        document.body.addEventListener('touchstart', (e) => dragStartX = e.touches[0].clientX);
        document.body.addEventListener('touchend', (e) => {
            if (dragStartX - e.changedTouches[0].clientX > 80 && settingsDiv.classList.contains('settings-hidden')) {
                settingsDiv.classList.remove('settings-hidden');
            }
        });

        // --- EVENTOS DE TOQUE (CANVAS) ---
        canvas.addEventListener('touchstart', (e) => {
            const t = e.touches;
            touchStartX = t[0].clientX; touchStartY = t[0].clientY;
            lastTouchX = touchStartX; lastTouchY = touchStartY;

            if (currentAppMode === 'draw') {
                isDrawing = true;
                const rect = canvas.getBoundingClientRect();
                ctx.beginPath(); ctx.moveTo(t[0].clientX - rect.left, t[0].clientY - rect.top);
            } else if (currentAppMode === 'stream' && t.length === 2) {
                initialPinchDist = Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
            }
        });

        canvas.addEventListener('touchmove', (e) => {
            const t = e.touches;
            const rect = canvas.getBoundingClientRect();

            if (currentAppMode === 'draw' && isDrawing) {
                ctx.lineWidth = 5; ctx.lineCap = 'round'; ctx.strokeStyle = 'black';
                ctx.lineTo(t[0].clientX - rect.left, t[0].clientY - rect.top); ctx.stroke();
                e.preventDefault();
            } else if (currentAppMode === 'stream') {
                if (t.length === 2) {
                    const dist = Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
                    streamZoom *= (dist / initialPinchDist);
                    initialPinchDist = dist;
                } else {
                    streamOffsetX += (t[0].clientX - lastTouchX);
                    streamOffsetY += (t[0].clientY - lastTouchY);
                }
                lastTouchX = t[0].clientX; lastTouchY = t[0].clientY;
                loadStreamImage();
                e.preventDefault();
            }
        });

        canvas.addEventListener('touchend', (e) => {
            isDrawing = false;
            if (currentAppMode !== 'camera') return;
            
            const touch = e.changedTouches[0];
            const dx = touch.clientX - touchStartX;
            if (Math.abs(dx) > 60) {
                isMirrored = !isMirrored;
            } else {
                const now = Date.now();
                if (!isCameraRunning) { if (now - lastTap > 300) startCamera(); }
                else if (now - lastTap < 300) {
                    facingMode = (facingMode === 'user' ? 'environment' : 'user');
                    isMirrored = (facingMode === 'user');
                    startCamera();
                }
                lastTap = now;
            }
        });

        // --- BOTÕES DE CONTROLE ---
        document.getElementById('btnModeCam').onclick = () => setMode('camera');
        document.getElementById('btnModeUpload').onclick = () => fileInput.click();
        document.getElementById('btnModeDraw').onclick = () => setMode('draw');
        document.getElementById('btnModeStream').onclick = () => setMode('stream');

        fileInput.onchange = (e) => {
            const file = e.target.files[0];
            if (!file) return;
            setMode('upload');
            const reader = new FileReader();
            reader.onload = (ev) => {
                const img = new Image();
                img.onload = () => ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        };

        document.getElementById('btnToggleCamera').onclick = () => {
            if (currentAppMode === 'stream') {
                isStreamPaused = !isStreamPaused;
                logger.info(isStreamPaused ? "Stream Pausada" : "Stream Rodando");
                return;
            }
            if (isCameraRunning || currentAppMode === 'draw') {
                executeTimedPause();
            } else {
                startCamera();
            }
        };

        function executeTimedPause(callback) {
            const delay = parseInt(document.getElementById('timerSelect').value);
            if (delay === 0 || (!isCameraRunning && currentAppMode !== 'draw')) {
                if(currentAppMode === 'draw') clearCanvas();
                stopCamera(); if (callback) callback(); return;
            }
            let tl = delay;
            countdownInterval = setInterval(() => {
                tl -= 100;
                timerDisplay.textContent = (tl/1000).toFixed(1) + "s";
                if (tl <= 0) { 
                    clearInterval(countdownInterval); 
                    if(currentAppMode === 'draw') clearCanvas();
                    stopCamera(); if(callback) callback(); 
                }
            }, 100);
        }

        document.getElementById('btnConnect').onclick = async () => {
            try { await connectPrinter(); 
                document.getElementById('btnConnect').style.background = '#2f855a';
                document.getElementById('btnConnect').textContent = 'Conectado';
            } catch (err) {}
        };

        document.getElementById('btnPrint').onclick = async () => {
            if (!isPrinterConnected()) return alert("Conecte a impressora.");
            const print = async () => {
                document.getElementById('logOverlay').classList.add('active');
                updateProcessorSettings();
                await imageProcessor.loadImage(canvas.toDataURL());
                await printImage(imageProcessor.processImage());
            };
            if (document.getElementById('autoPause').checked && isCameraRunning) executeTimedPause(print);
            else print();
        };

        document.getElementById('btnOpenLogs').onclick = () => document.getElementById('logOverlay').classList.add('active');
        document.getElementById('btnCloseLogs').onclick = () => document.getElementById('logOverlay').classList.remove('active');
    </script>
</body>
</html>