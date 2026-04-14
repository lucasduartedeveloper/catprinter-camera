<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>MXW01 Mobile Camera</title>
    <style>
        body { font-family: sans-serif; display: flex; flex-direction: column; align-items: center; background: #f0f0f0; margin: 0; padding: 20px; touch-action: manipulation; }
        /* Pontas de 90 graus conforme solicitado */
        #canvasContainer { position: relative; width: 300px; height: 300px; background: #000; border: 2px solid #333; overflow: hidden; border-radius: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        canvas { width: 300px; height: 300px; display: block; }
        .controls { display: flex; flex-direction: column; gap: 10px; width: 300px; margin-top: 15px; }
        button { padding: 12px; font-size: 16px; border: none; border-radius: 5px; cursor: pointer; background: #007bff; color: white; font-weight: bold; }
        button:active { filter: brightness(0.8); }
        .config-row { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 8px 12px; border-radius: 5px; font-size: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        select, input { padding: 5px; border: 1px solid #ccc; border-radius: 4px; }
        
        #logOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #1a1a1a; z-index: 1000; display: none; flex-direction: column; }
        #logContent { flex: 1; padding: 15px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 11px; color: #00ff00; }
        .active { display: flex !important; }
        #progressBarContainer { height: 6px; background: #333; width: 100%; }
        #progressBar { height: 100%; background: #4caf50; width: 0%; transition: width 0.1s; }
        .log-entry { margin-bottom: 4px; border-bottom: 1px solid #222; padding-bottom: 2px; }
        .close-btn { background: #ff4444; border-radius: 0; padding: 15px; }
    </style>
</head>
<body>

    <div id="canvasContainer">
        <canvas id="cameraCanvas" width="300" height="300"></canvas>
    </div>

    <div class="controls">
        <div class="config-row">
            <span>Dithering:</span>
            <select id="ditherMode">
                <option value="threshold">Simples (Threshold)</option>
                <option value="floydSteinberg">Floyd-Steinberg</option>
                <option value="stucki">Stucki (Qualidade Foto)</option>
                <option value="atkinson">Atkinson</option>
                <option value="bayer">Bayer Matrix</option>
                <option value="halftone">Halftone</option>
                <option value="none">Nenhum (Grayscale)</option>
            </select>
        </div>
        <div class="config-row">
            <span>Threshold:</span>
            <input type="number" id="threshold" value="128" min="0" max="255">
        </div>

        <button id="btnConnect">1. Conectar Impressora</button>
        <button id="btnToggleCamera">Pausar/Play Câmera</button>
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
        let video = document.createElement('video');
        let stream = null;
        let isCameraRunning = false;
        let facingMode = 'user'; 
        let isMirrored = true;   
        let animationId = null;
        let lastTap = 0;
        let pressTimer = null;
        let touchStartX = 0;

        setupLoggerUI(document.getElementById('logContent'), document.getElementById('progressBar'));

        // === INTEGRAÇÃO DO SWITCH DE DITHERING ===
        // Esta função simula a lógica interna que deve estar no seu imageProcessor.js
        // Mas aqui garantimos que a interface envie os comandos corretos.
        function updateProcessorSettings() {
            const threshold = parseInt(document.getElementById('threshold').value);
            const ditherMethod = document.getElementById('ditherMode').value;
            
            imageProcessor.updateSettings({ 
                threshold: threshold,
                ditherMethod: ditherMethod 
            });
            
            logger.info(`Configurações: Threshold ${threshold}, Modo ${ditherMethod}`);
        }

        document.getElementById('threshold').addEventListener('blur', updateProcessorSettings);
        document.getElementById('ditherMode').addEventListener('change', updateProcessorSettings);

        async function startCamera() {
            try {
                if (stream) stream.getTracks().forEach(t => t.stop());
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: facingMode, width: { ideal: 640 }, height: { ideal: 640 } }
                });
                video.srcObject = stream;
                video.play();
                isCameraRunning = true;
                drawFrame();
                logger.info(`Câmera ${facingMode === 'user' ? 'Frontal' : 'Traseira'} iniciada.`);
            } catch (err) {
                logger.error("Erro ao acessar câmera", err);
            }
        }

        function stopCamera() {
            isCameraRunning = false;
            cancelAnimationFrame(animationId);
            if (stream) stream.getTracks().forEach(t => t.stop());
            stream = null;
        }

        function drawFrame() {
            if (!isCameraRunning) return;
            ctx.save();
            if (isMirrored) {
                ctx.translate(canvas.width, 0);
                ctx.scale(-1, 1);
            }
            const videoWidth = video.videoWidth;
            const videoHeight = video.videoHeight;
            if (videoWidth > 0) {
                const aspect = videoWidth / videoHeight;
                let drawWidth, drawHeight, offsetX, offsetY;
                if (aspect > 1) {
                    drawHeight = canvas.height;
                    drawWidth = canvas.height * aspect;
                    offsetX = -(drawWidth - canvas.width) / 2;
                    offsetY = 0;
                } else {
                    drawWidth = canvas.width;
                    drawHeight = canvas.width / aspect;
                    offsetX = 0;
                    offsetY = -(drawHeight - canvas.height) / 2;
                }
                ctx.drawImage(video, offsetX, offsetY, drawWidth, drawHeight);
            }
            ctx.restore();
            animationId = requestAnimationFrame(drawFrame);
        }

        function handleFile(e) {
            const file = e.target.files[0];
            if (!file) return;
            stopCamera();
            const reader = new FileReader();
            reader.onload = (event) => {
                const img = new Image();
                img.onload = () => {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    const aspect = img.width / img.height;
                    let drawWidth, drawHeight, offsetX, offsetY;
                    if (aspect > 1) {
                        drawHeight = canvas.height;
                        drawWidth = canvas.height * aspect;
                        offsetX = -(drawWidth - canvas.width) / 2;
                        offsetY = 0;
                    } else {
                        drawWidth = canvas.width;
                        drawHeight = canvas.width / aspect;
                        offsetX = 0;
                        offsetY = -(drawHeight - canvas.height) / 2;
                    }
                    ctx.drawImage(img, offsetX, offsetY, drawWidth, drawHeight);
                    logger.info("Imagem carregada do dispositivo.");
                };
                img.src = event.target.result;
            };
            reader.readAsDataURL(file);
        }

        fileInput.addEventListener('change', handleFile);

        canvas.addEventListener('touchstart', (e) => {
            touchStartX = e.touches[0].clientX;
            pressTimer = setTimeout(() => {
                fileInput.click();
            }, 3000);
        });

        canvas.addEventListener('touchend', (e) => {
            clearTimeout(pressTimer);
            const now = Date.now();
            if (Math.abs(e.changedTouches[0].clientX - touchStartX) > 60) {
                isMirrored = !isMirrored;
                logger.info(`Espelhamento: ${isMirrored ? 'Ligado' : 'Desligado'}`);
            } else {
                if (!isCameraRunning) {
                    if (now - lastTap > 300) startCamera(); 
                } else if (now - lastTap < 300) {
                    facingMode = (facingMode === 'user') ? 'environment' : 'user';
                    isMirrored = (facingMode === 'user');
                    startCamera();
                }
            }
            lastTap = now;
        });

        canvas.addEventListener('click', (e) => {
            if (!isCameraRunning && e.detail === 1) startCamera();
        });

        canvas.addEventListener('mousedown', () => {
            pressTimer = setTimeout(() => fileInput.click(), 3000);
        });
        canvas.addEventListener('mouseup', () => clearTimeout(pressTimer));

        document.getElementById('btnToggleCamera').onclick = () => {
            if (isCameraRunning) {
                stopCamera();
                logger.info("Câmera pausada.");
            } else {
                startCamera();
            }
        };

        document.getElementById('btnConnect').onclick = async () => {
            try {
                await connectPrinter();
                document.getElementById('btnConnect').style.background = '#2f855a';
                document.getElementById('btnConnect').textContent = 'Impressora Conectada';
            } catch (err) {}
        };

        document.getElementById('btnPrint').onclick = async () => {
            if (!isPrinterConnected()) {
                alert("Por favor, conecte a impressora primeiro.");
                return;
            }
            document.getElementById('logOverlay').classList.add('active');
            
            const imageDataUrl = canvas.toDataURL('image/png');
            updateProcessorSettings();
            
            try {
                await imageProcessor.loadImage(imageDataUrl);
                const processedCanvas = imageProcessor.processImage();
                await printImage(processedCanvas);
                logger.success("Impressão finalizada!");
            } catch (err) {
                logger.error("Falha na impressão", err);
            }
        };

        document.getElementById('btnOpenLogs').onclick = () => document.getElementById('logOverlay').classList.add('active');
        document.getElementById('btnCloseLogs').onclick = () => document.getElementById('logOverlay').classList.remove('active');
    </script>
</body>
</html>