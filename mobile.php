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
        
        .mode-selector { display: flex; gap: 5px; width: 300px; margin-bottom: 15px; }
        .mode-selector button { flex: 1; padding: 10px 5px; font-size: 12px; background: #555; }
        .mode-selector button.active-mode { background: #007bff; }

        .controls { display: flex; flex-direction: column; gap: 10px; width: 300px; transition: transform 0.3s ease; }
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

        let currentAppMode = 'camera'; 
        let isDrawing = false;
        let overlayImage = null; // Armazena a imagem de upload para sobreposição
        
        // --- ESTADO STREAM (VOZ PARA TEXTO) ---
        let recognition = null;
        let isStreamPaused = false;
        const LINE_HEIGHT = 40; 

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

        function setMode(mode) {
            // Se mudarmos para câmera, não paramos a câmera se ela já estiver rodando
            if (mode !== 'camera') {
                stopCamera();
                overlayImage = null; 
            }
            if (recognition) recognition.stop();
            currentAppMode = mode;
            ctx.setTransform(1, 0, 0, 1, 0, 0); 
            
            document.querySelectorAll('.mode-selector button').forEach(b => b.classList.remove('active-mode'));
            
            if (mode === 'camera') {
                document.getElementById('btnModeCam').classList.add('active-mode');
                if(!isCameraRunning) startCamera();
            } else if (mode === 'draw') {
                document.getElementById('btnModeDraw').classList.add('active-mode');
                clearCanvas();
            } else if (mode === 'stream') {
                document.getElementById('btnModeStream').classList.add('active-mode');
                initVoiceStream();
            }
        }

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
            if (!isCameraRunning || (currentAppMode !== 'camera' && currentAppMode !== 'upload')) return;
            
            ctx.save();
            // 1. Desenha o vídeo (Fundo)
            if (isMirrored) { ctx.translate(canvas.width, 0); ctx.scale(-1, 1); }
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            ctx.restore();

            // 2. Desenha a sobreposição (Se houver imagem carregada)
            if (overlayImage) {
                const scale = Math.max(canvas.width / overlayImage.width, canvas.height / overlayImage.height);
                const x = (canvas.width / 2) - (overlayImage.width / 2) * scale;
                const y = (canvas.height / 2) - (overlayImage.height / 2) * scale;
                ctx.drawImage(overlayImage, x, y, overlayImage.width * scale, overlayImage.height * scale);
            }

            animationId = requestAnimationFrame(drawFrame);
        }

        // --- LÓGICA DE STREAM (VOICE TO PRINT) ---
        async function initVoiceStream() {
            clearCanvas();
            try {
                const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                if (!SpeechRecognition) return alert("Navegador incompatível.");

                recognition = new SpeechRecognition();
                recognition.lang = 'pt-BR';
                recognition.continuous = true;
                recognition.interimResults = true; 

                recognition.onresult = async (event) => {
                    if (isStreamPaused) return;
                    let result = event.results[event.results.length - 1];
                    let text = result[0].transcript.trim();

                    if (result.isFinal) {
                        await processTextLine(text);
                    } else {
                        renderTextToCanvas(text); 
                    }
                };

                recognition.onend = () => { if(currentAppMode === 'stream') recognition.start(); };
                recognition.start();
                logger.info("Microfone aberto. Diga algo ou 'Pronto'.");

            } catch (err) { logger.error("Erro no Microfone", err); }
        }

        async function processTextLine(text) {
            const isFinished = text.toLowerCase().includes("pronto");
            const cleanText = text.replace(/pronto/gi, "").trim();

            if (cleanText.length > 0) {
                renderTextToCanvas(cleanText);

                imageProcessor.updateSettings({ 
                    padding: 0, 
                    autoscale: false, 
                    threshold: parseInt(document.getElementById('threshold').value) 
                });

                const sliceCanvas = document.createElement('canvas');
                sliceCanvas.width = 384; 
                sliceCanvas.height = 30; 
                const sCtx = sliceCanvas.getContext('2d');
                sCtx.fillStyle = "white";
                sCtx.fillRect(0, 0, 384, 30);
                sCtx.fillStyle = "black";
                sCtx.font = "bold 24px sans-serif";
                sCtx.textAlign = "center";
                sCtx.fillText(cleanText, 192, 24);

                if (isPrinterConnected()) {
                    await imageProcessor.loadImage(sliceCanvas.toDataURL());
                    const processed = imageProcessor.processImage();
                    await printImage(processed);
                }

                setTimeout(() => {
                    const imgData = ctx.getImageData(0, LINE_HEIGHT, canvas.width, canvas.height - LINE_HEIGHT);
                    clearCanvas();
                    ctx.putImageData(imgData, 0, 0);
                }, 500);
            }

            if (isFinished) {
                recognition.stop();
                setMode('camera');
            }
        }

        function renderTextToCanvas(text) {
            ctx.fillStyle = "white";
            ctx.fillRect(0, 0, canvas.width, canvas.height); 
            ctx.fillStyle = "black";
            ctx.font = "bold 20px sans-serif";
            ctx.textAlign = "center";
            ctx.fillText(text, canvas.width/2, canvas.height/2);
        }

        function clearCanvas() {
            ctx.fillStyle = "white";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            overlayImage = null;
        }

        // --- GESTOS E CONFIGURAÇÕES ---
        let dragStartX = 0;
        settingsDiv.addEventListener('touchstart', (e) => dragStartX = e.touches[0].clientX);
        settingsDiv.addEventListener('touchend', (e) => {
            if (e.changedTouches[0].clientX - dragStartX > 80) settingsDiv.classList.add('settings-hidden');
        });
        document.body.addEventListener('touchstart', (e) => dragStartX = e.touches[0].clientX);
        document.body.addEventListener('touchend', (e) => {
            if (dragStartX - e.changedTouches[0].clientX > 80) settingsDiv.classList.remove('settings-hidden');
        });

        canvas.addEventListener('touchstart', (e) => {
            const t = e.touches;
            touchStartX = t[0].clientX; touchStartY = t[0].clientY;
            
            const now = Date.now();
            const TIMESPAN = 300;
            if (now - lastTap < TIMESPAN) {
                facingMode = facingMode === 'user' ? 'environment' : 'user';
                if(isCameraRunning) startCamera();
                logger.info("Câmera trocada");
            } else {
                if (!isCameraRunning && currentAppMode === 'camera') startCamera();
            }
            lastTap = now;

            if (currentAppMode === 'draw') {
                isDrawing = true;
                const rect = canvas.getBoundingClientRect();
                ctx.beginPath(); ctx.moveTo(t[0].clientX - rect.left, t[0].clientY - rect.top);
            }
        });

        canvas.addEventListener('touchmove', (e) => {
            if (currentAppMode === 'draw' && isDrawing) {
                const rect = canvas.getBoundingClientRect();
                ctx.lineWidth = 5; ctx.lineCap = 'round'; ctx.strokeStyle = 'black';
                ctx.lineTo(e.touches[0].clientX - rect.left, e.touches[0].clientY - rect.top); ctx.stroke();
                e.preventDefault();
            }
        });

        canvas.addEventListener('touchend', (e) => {
            isDrawing = false;
            const touchEndX = e.changedTouches[0].clientX;
            const diffX = touchEndX - touchStartX;
            if (Math.abs(diffX) > 50) {
                isMirrored = !isMirrored;
                logger.info(isMirrored ? "Espelhamento Ativo" : "Espelhamento Inativo");
            }
        });

        document.getElementById('btnModeCam').onclick = () => {
            overlayImage = null;
            setMode('camera');
        };
        document.getElementById('btnModeUpload').onclick = () => fileInput.click();
        document.getElementById('btnModeDraw').onclick = () => setMode('draw');
        document.getElementById('btnModeStream').onclick = () => setMode('stream');

        fileInput.onchange = (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            const reader = new FileReader();
            reader.onload = (ev) => {
                const img = new Image();
                img.onload = () => {
                    overlayImage = img; // Salva para desenhar no frame da câmera
                    
                    if (!isCameraRunning) {
                        setMode('upload');
                        clearCanvas();
                        const scale = Math.max(canvas.width / img.width, canvas.height / img.height);
                        const x = (canvas.width / 2) - (img.width / 2) * scale;
                        const y = (canvas.height / 2) - (img.height / 2) * scale;
                        ctx.drawImage(img, x, y, img.width * scale, img.height * scale);
                    } else {
                        currentAppMode = 'camera';
                        document.querySelectorAll('.mode-selector button').forEach(b => b.classList.remove('active-mode'));
                        document.getElementById('btnModeCam').classList.add('active-mode');
                        logger.info("Overlay carregado sobre a câmera.");
                    }
                };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        };

        document.getElementById('btnToggleCamera').onclick = () => {
            if (currentAppMode === 'stream') {
                isStreamPaused = !isStreamPaused;
                return;
            }
            if (isCameraRunning || currentAppMode === 'draw') executeTimedPause();
            else startCamera();
        };

        function executeTimedPause(callback) {
            const delay = parseInt(document.getElementById('timerSelect').value);
            if (delay === 0) { stopCamera(); if(currentAppMode === 'draw') clearCanvas(); if(callback) callback(); return; }
            let tl = delay;
            countdownInterval = setInterval(() => {
                tl -= 100;
                timerDisplay.textContent = (tl/1000).toFixed(1) + "s";
                if (tl <= 0) { clearInterval(countdownInterval); stopCamera(); if(currentAppMode === 'draw') clearCanvas(); if(callback) callback(); }
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