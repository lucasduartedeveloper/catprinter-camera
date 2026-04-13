<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>MXW01 Camera Printer</title>
    <style>
        body { font-family: sans-serif; display: flex; flex-direction: column; align-items: center; background: #f0f0f0; margin: 0; padding: 20px; }
        #canvasContainer { position: relative; width: 300px; height: 300px; background: #000; border: 2px solid #333; overflow: hidden; }
        canvas { width: 300px; height: 300px; display: block; }
        .controls { display: flex; flex-direction: column; gap: 10px; width: 300px; margin-top: 15px; }
        button { padding: 12px; font-size: 16px; border: none; border-radius: 5px; cursor: pointer; background: #007bff; color: white; }
        button:active { background: #0056b3; }
        .config-row { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 5px 10px; border-radius: 5px; font-size: 14px; }
        input { width: 60px; padding: 5px; }
        #logOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: white; z-index: 1000; display: none; flex-direction: column; }
        #logContent { flex: 1; padding: 15px; overflow-y: auto; font-family: monospace; font-size: 12px; background: #1e1e1e; color: #adadad; }
        .active { display: flex !important; }
        #progressBar { height: 5px; background: #4caf50; width: 0%; transition: width 0.2s; }
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
                <option value="floydSteinberg">Floyd-Steinberg</option>
                <option value="bayer">Bayer Matrix</option>
                <option value="threshold">Simple</option>
            </select>
        </div>
        <div class="config-row">
            <span>Threshold (0-255):</span>
            <input type="number" id="threshold" value="128" min="0" max="255">
        </div>

        <button id="btnConnect">Connect MXW01 Printer</button>
        <button id="btnToggleCamera">Pause/Start Camera</button>
        <button id="btnPrint" style="background: #28a745;">PRINT</button>
        <button id="btnOpenLogs" style="background: #6c757d;">LOGS</button>
    </div>

    <div id="logOverlay">
        <div id="progressBar"></div>
        <div id="logContent"></div>
        <button id="btnCloseLogs">Close Logs</button>
    </div>

    <script>
        // --- SETTINGS AND GLOBAL VARIABLES ---
        const canvas = document.getElementById('cameraCanvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        let video = document.createElement('video');
        let stream = null;
        let isCameraRunning = false;
        let facingMode = 'user'; 
        let isMirrored = false;
        let animationId = null;

        // Printer Bluetooth Vars
        let device = null;
        let controlChar = null;
        let dataChar = null;
        const PRINTER_WIDTH = 384;

        // --- LOGGING SYSTEM ---
        const logContent = document.getElementById('logContent');
        const progressBar = document.getElementById('progressBar');
        function addLog(msg, type = 'info') {
            const entry = document.createElement('div');
            entry.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
            entry.style.color = type === 'error' ? '#ff5555' : (type === 'success' ? '#55ff55' : '#adadad');
            logContent.appendChild(entry);
            logContent.scrollTop = logContent.scrollHeight;
        }

        // --- CAMERA CONTROL ---
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
                addLog(`Camera ${facingMode === 'user' ? 'Front' : 'Rear'} started.`);
            } catch (err) {
                addLog("Error accessing camera: " + err, 'error');
            }
        }

        function drawFrame() {
            if (!isCameraRunning) return;
            
            ctx.save();
            if (isMirrored) {
                ctx.translate(canvas.width, 0);
                ctx.scale(-1, 1);
            }

            // "Cover" logic to fill 300x300 without stretching
            const videoWidth = video.videoWidth;
            const videoHeight = video.videoHeight;
            if (videoWidth > 0) {
                const aspect = videoWidth / videoHeight;
                let drawWidth, drawHeight, offsetX, offsetY;

                if (aspect > 1) { // Landscape
                    drawHeight = canvas.height;
                    drawWidth = canvas.height * aspect;
                    offsetX = -(drawWidth - canvas.width) / 2;
                    offsetY = 0;
                } else { // Portrait
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

        // Canvas Events (Tap, Double Tap, Swipe)
        let lastTap = 0;
        let touchStartX = 0;

        canvas.addEventListener('click', (e) => {
            const now = Date.now();
            if (now - lastTap < 300) { // Double click/tap
                facingMode = (facingMode === 'user') ? 'environment' : 'user';
                startCamera();
            } else {
                if (!isCameraRunning) startCamera();
            }
            lastTap = now;
        });

        canvas.addEventListener('touchstart', e => touchStartX = e.touches[0].clientX);
        canvas.addEventListener('touchend', e => {
            const touchEndX = e.changedTouches[0].clientX;
            if (Math.abs(touchEndX - touchStartX) > 50) {
                isMirrored = !isMirrored;
                addLog(`Mirroring: ${isMirrored ? 'On' : 'Off'}`);
            }
        });

        document.getElementById('btnToggleCamera').onclick = () => {
            if (isCameraRunning) {
                isCameraRunning = false;
                cancelAnimationFrame(animationId);
                addLog("Camera paused.");
            } else {
                isCameraRunning = true;
                drawFrame();
                addLog("Camera resumed.");
            }
        };

        // --- PRINTER LOGIC (CatPrinter Core) ---
        document.getElementById('btnConnect').onclick = async () => {
            try {
                device = await navigator.bluetooth.requestDevice({
                    filters: [{ namePrefix: 'MXW' }, { services: ['0000ae30-0000-1000-8000-00805f9b34fb'] }],
                    optionalServices: ['0000ae30-0000-1000-8000-00805f9b34fb']
                });
                const server = await device.gatt.connect();
                const service = await server.getPrimaryService('0000ae30-0000-1000-8000-00805f9b34fb');
                controlChar = await service.getCharacteristic('0000ae01-0000-1000-8000-00805f9b34fb');
                dataChar = await service.getCharacteristic('0000ae03-0000-1000-8000-00805f9b34fb');
                addLog("Printer Connected!", 'success');
            } catch (err) {
                addLog("Connection failed: " + err, 'error');
            }
        };

        // Simplified Dithering/Image Processing
        function processImageData() {
            const imgData = ctx.getImageData(0, 0, 300, 300);
            const data = imgData.data;
            const threshold = parseInt(document.getElementById('threshold').value);
            
            const factor = PRINTER_WIDTH / 300;
            const newHeight = Math.floor(300 * factor);
            const output = new Uint8Array((PRINTER_WIDTH * newHeight) / 8);

            for (let y = 0; y < newHeight; y++) {
                for (let x = 0; x < PRINTER_WIDTH; x++) {
                    const origX = Math.floor(x / factor);
                    const origY = Math.floor(y / factor);
                    const idx = (origY * 300 + origX) * 4;
                    
                    const gray = (data[idx] + data[idx+1] + data[idx+2]) / 3;
                    if (gray < threshold) {
                        const bitIdx = y * PRINTER_WIDTH + x;
                        output[Math.floor(bitIdx / 8)] |= (0x80 >> (bitIdx % 8));
                    }
                }
            }
            return { data: output, height: newHeight };
        }

        document.getElementById('btnPrint').onclick = async () => {
            if (!dataChar) return alert("Connect the printer first!");
            
            addLog("Starting print...");
            const { data, height } = processImageData();
            
            try {
                const header = new Uint8Array([0x51, 0x78, 0xA9, 0x00, data.length % 256, Math.floor(data.length / 256), 0x00]);
                await controlChar.writeValue(header);

                const chunkSize = 100;
                for (let i = 0; i < data.length; i += chunkSize) {
                    const chunk = data.slice(i, i + chunkSize);
                    await dataChar.writeValueWithoutResponse(chunk);
                    const prog = Math.round((i / data.length) * 100);
                    progressBar.style.width = prog + "%";
                    if (i % 500 === 0) addLog(`Progress: ${prog}%`);
                }
                
                await controlChar.writeValue(new Uint8Array([0x51, 0x78, 0xFF, 0xFF, 0x00, 0x00, 0x00]));
                addLog("Print finished!", 'success');
                progressBar.style.width = "0%";
            } catch (err) {
                addLog("Error printing: " + err, 'error');
            }
        };

        // --- UI LOGS ---
        document.getElementById('btnOpenLogs').onclick = () => document.getElementById('logOverlay').classList.add('active');
        document.getElementById('btnCloseLogs').onclick = () => document.getElementById('logOverlay').classList.remove('active');

    </script>
</body>
</html>