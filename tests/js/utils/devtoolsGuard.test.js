import { describe, it, expect, beforeAll } from 'vitest';
import { isBlockedShortcut, isEditable, isGuardedPath, installDevtoolsGuard } from '@/utils/devtoolsGuard';

const key = (o) => ({ key: '', code: '', ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, ...o });

describe('devtools guard — shortcuts', () => {
    it('blocks every way to open DevTools / view source from the keyboard', () => {
        expect(isBlockedShortcut(key({ key: 'F12', code: 'F12' }))).toBe(true);
        for (const code of ['KeyI', 'KeyJ', 'KeyC', 'KeyK']) {
            expect(isBlockedShortcut(key({ code, ctrlKey: true, shiftKey: true }))).toBe(true);
            expect(isBlockedShortcut(key({ code, metaKey: true, shiftKey: true }))).toBe(true);
        }
        for (const code of ['KeyI', 'KeyJ', 'KeyC', 'KeyU']) {
            // Mac: ⌘+⌥ ให้ e.key เป็นตัวพิเศษ (เช่น ˆ) → ต้องดู e.code
            expect(isBlockedShortcut(key({ code, key: 'ˆ', metaKey: true, altKey: true }))).toBe(true);
        }
        expect(isBlockedShortcut(key({ code: 'KeyU', ctrlKey: true }))).toBe(true); // ดูซอร์ส
        expect(isBlockedShortcut(key({ code: 'KeyS', metaKey: true }))).toBe(true); // บันทึกหน้า
    });

    it('leaves normal typing and editing shortcuts alone', () => {
        expect(isBlockedShortcut(key({ key: 'i', code: 'KeyI' }))).toBe(false);
        expect(isBlockedShortcut(key({ code: 'KeyV', ctrlKey: true }))).toBe(false); // วาง
        expect(isBlockedShortcut(key({ code: 'KeyA', ctrlKey: true }))).toBe(false);
        expect(isBlockedShortcut(key({ code: 'KeyZ', ctrlKey: true, shiftKey: true }))).toBe(false);
        expect(isBlockedShortcut(key({ key: 'F5', code: 'F5' }))).toBe(false);
    });
});

describe('devtools guard — where it applies', () => {
    it('does not guard the admin back office', () => {
        expect(isGuardedPath('/admin')).toBe(false);
        expect(isGuardedPath('/admin/bug-reports')).toBe(false);
        expect(isGuardedPath('/')).toBe(true);
        expect(isGuardedPath('/trade/BTC-USDT')).toBe(true);
        expect(isGuardedPath('/administrator-guide')).toBe(true);
    });

    it('treats form fields as editable (right click / copy / paste stay normal there)', () => {
        document.body.innerHTML = '<input id="a"><div contenteditable="true"><b id="b">x</b></div><p id="c">text</p>';
        expect(isEditable(document.getElementById('a'))).toBe(true);
        expect(isEditable(document.getElementById('b').firstChild)).toBe(true); // text node ในช่องแก้ไขได้
        expect(isEditable(document.getElementById('c'))).toBe(false);
    });
});

describe('devtools guard — installed', () => {
    beforeAll(() => {
        window.history.replaceState({}, '', '/');
        installDevtoolsGuard({ force: true });
    });

    const fire = (target, type, init = {}) => {
        const e = type === 'keydown'
            ? new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init })
            : new Event(type, { bubbles: true, cancelable: true });
        target.dispatchEvent(e);
        return e.defaultPrevented;
    };

    it('blocks F12, right click and plain copy on page content', () => {
        document.body.innerHTML = '<p id="c">0xabc</p><input id="i">';
        const p = document.getElementById('c');
        expect(fire(p, 'keydown', { key: 'F12', code: 'F12' })).toBe(true);
        expect(fire(p, 'contextmenu')).toBe(true);
        expect(fire(p, 'copy')).toBe(true);
    });

    it('keeps right click and copy working inside form fields', () => {
        document.body.innerHTML = '<input id="i">';
        const i = document.getElementById('i');
        expect(fire(i, 'contextmenu')).toBe(false);
        expect(fire(i, 'copy')).toBe(false);
    });

    it('stays out of the admin back office', () => {
        window.history.replaceState({}, '', '/admin/settings');
        document.body.innerHTML = '<p id="c">x</p>';
        const p = document.getElementById('c');
        expect(fire(p, 'contextmenu')).toBe(false);
        expect(fire(p, 'keydown', { key: 'F12', code: 'F12' })).toBe(false);
        window.history.replaceState({}, '', '/');
    });
});
