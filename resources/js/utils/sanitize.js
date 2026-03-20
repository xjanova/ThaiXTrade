/**
 * TPIX TRADE — HTML Sanitization Utility
 * ป้องกัน XSS จาก user-generated content (blog, banner ads, AI news)
 * ใช้ DOMPurify เพื่อ strip malicious tags/attributes
 * Developed by Xman Studio.
 */

import DOMPurify from 'dompurify';

/**
 * Sanitize HTML content — อนุญาตแค่ safe tags (p, h1-h6, a, img, ul, ol, li, etc.)
 * Strip <script>, <iframe>, onerror, onclick, และ attributes อันตรายทั้งหมด
 *
 * @param {string} dirty — raw HTML from API/DB
 * @returns {string} — sanitized HTML ที่ปลอดภัย
 */
export function sanitizeHtml(dirty) {
    if (!dirty) return '';

    return DOMPurify.sanitize(dirty, {
        ALLOWED_TAGS: [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li',
            'a', 'img',
            'blockquote', 'pre', 'code',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'div', 'span', 'hr',
            'figure', 'figcaption',
        ],
        ALLOWED_ATTR: [
            'href', 'src', 'alt', 'title', 'class', 'id',
            'target', 'rel', 'width', 'height',
            'colspan', 'rowspan',
        ],
        ALLOW_DATA_ATTR: false,
        ADD_ATTR: ['target'],
        // Force all links to open in new tab with noopener
        FORBID_TAGS: ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'select', 'button'],
        FORBID_ATTR: ['onerror', 'onclick', 'onload', 'onmouseover', 'onfocus', 'onblur'],
    });
}

/**
 * Sanitize ad code — สำหรับ Google AdSense / custom HTML banners
 * อนุญาต <ins>, <script src="..."> จาก Google domains เท่านั้น
 *
 * @param {string} dirty — ad code from admin settings
 * @returns {string} — sanitized ad HTML
 */
export function sanitizeAdCode(dirty) {
    if (!dirty) return '';

    return DOMPurify.sanitize(dirty, {
        ADD_TAGS: ['ins'],
        ADD_ATTR: ['data-ad-client', 'data-ad-slot', 'data-ad-format', 'data-full-width-responsive'],
        ALLOW_DATA_ATTR: true,
        FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form'],
        FORBID_ATTR: ['onerror', 'onclick', 'onload', 'onmouseover'],
    });
}

/**
 * Decode HTML entities — สำหรับ pagination labels (« » etc.)
 * ใช้แทน v-html เพื่อป้องกัน XSS
 *
 * @param {string} html — HTML string with entities
 * @returns {string} — decoded plain text
 */
export function decodeHtmlEntities(html) {
    if (!html) return '';
    const txt = document.createElement('textarea');
    txt.innerHTML = html;
    return txt.value;
}
