/* เสิร์ฟ public_html แบบรองรับ HTTP Range — ใช้ตรวจว่าการกระโดดเวลาในวิดีโอทำงานจริง
   (php artisan serve ตอบ 200 เต็มไฟล์เสมอ จึงทดสอบ seek ไม่ได้) */
import { createServer } from 'node:http';
import { createReadStream, statSync, existsSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';

const ROOT = normalize(new URL('../../public_html', import.meta.url).pathname.replace(/^\//, ''));
const PORT = 8099;
const MIME = { '.mp4': 'video/mp4', '.html': 'text/html', '.js': 'text/javascript',
               '.css': 'text/css', '.webp': 'image/webp', '.png': 'image/png' };

createServer((req, res) => {
    const rel = decodeURIComponent(req.url.split('?')[0]).replace(/^\/+/, '');
    const file = join(ROOT, rel);
    if (!file.startsWith(ROOT) || !existsSync(file) || statSync(file).isDirectory()) {
        res.writeHead(404).end('not found');
        return;
    }
    const { size } = statSync(file);
    const type = MIME[extname(file).toLowerCase()] || 'application/octet-stream';
    const range = req.headers.range;

    if (range) {
        const m = /bytes=(\d*)-(\d*)/.exec(range);
        const start = m[1] ? parseInt(m[1], 10) : 0;
        const end = m[2] ? parseInt(m[2], 10) : size - 1;
        res.writeHead(206, {
            'Content-Range': `bytes ${start}-${end}/${size}`,
            'Accept-Ranges': 'bytes',
            'Content-Length': end - start + 1,
            'Content-Type': type,
        });
        createReadStream(file, { start, end }).pipe(res);
    } else {
        res.writeHead(200, { 'Content-Length': size, 'Content-Type': type,
                             'Accept-Ranges': 'bytes' });
        createReadStream(file).pipe(res);
    }
}).listen(PORT, () => console.log(`range-capable static server on http://localhost:${PORT}`));
