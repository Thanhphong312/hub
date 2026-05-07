<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print label · {{ $order->system_id }}</title>
    <style>
        html, body { margin: 0; padding: 0; background: #fff; }
        .frame { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .frame img, .frame embed { max-width: 100%; max-height: 100vh; }
        .err { font-family: system-ui, sans-serif; padding: 32px; color: #b91c1c; }
        @media print {
            @page { margin: 0; }
            html, body { margin: 0; padding: 0; }
            .frame { min-height: auto; }
            .frame img { max-height: none; width: 100%; height: auto; }
        }
    </style>
</head>
<body>
    <div class="frame" id="frame">
        <img id="label" src="{{ $imageUrl }}" alt="Shipping label" />
    </div>

    <script>
        (function () {
            const img = document.getElementById('label');
            const frame = document.getElementById('frame');
            let printed = false;

            const triggerPrint = () => {
                if (printed) return;
                printed = true;
                // Defer to give layout one tick to settle.
                setTimeout(() => window.print(), 100);
            };

            img.addEventListener('load', triggerPrint);

            img.addEventListener('error', async () => {
                // Fallback: probably a PDF — swap <img> for <embed>.
                try {
                    const head = await fetch(img.src, { method: 'HEAD' });
                    const ct = head.headers.get('content-type') || '';
                    if (ct.includes('pdf')) {
                        const embed = document.createElement('embed');
                        embed.src = img.src;
                        embed.type = 'application/pdf';
                        embed.style.width = '100%';
                        embed.style.height = '100vh';
                        frame.replaceChild(embed, img);
                        // PDF embed has no reliable load event — print after a delay.
                        setTimeout(triggerPrint, 800);
                        return;
                    }
                    frame.innerHTML = '<div class="err">Could not load label image (' + ct + ').</div>';
                } catch (e) {
                    frame.innerHTML = '<div class="err">Could not load label image.</div>';
                }
            });

            // Auto-close after print so the operator gets back to /qr quickly.
            window.addEventListener('afterprint', () => setTimeout(() => window.close(), 200));
        })();
    </script>
</body>
</html>
