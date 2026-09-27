/**
 * TPIX TRADE — สถานะแชทกลาง (หน้าต่างแชท + บับเบิ้ลน้อง TPIX ใช้ร่วมกัน)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { useChatbot, __resetChatbot, HISTORY_LIMIT } from '@/Composables/useChatbot';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

const ok = (message, navigation = null) => ({ data: { success: true, data: { message, navigation, success: true } } });

beforeEach(() => {
    __resetChatbot();
    axios.post.mockReset();
});

describe('useChatbot', () => {
    it('sends the question with persona, language and the previous turns', async () => {
        const chat = useChatbot();
        axios.post.mockResolvedValueOnce(ok('ตอบแรก'));
        await chat.send('ถามแรก', { language: 'th', persona: 'mascot' });
        axios.post.mockResolvedValueOnce(ok('ตอบสอง'));
        await chat.send('แล้วอันนั้นล่ะ', { language: 'th', persona: 'mascot' });

        const [url, body] = axios.post.mock.calls[1];
        expect(url).toBe('/api/v1/chatbot');
        expect(body).toMatchObject({ message: 'แล้วอันนั้นล่ะ', language: 'th', persona: 'mascot' });
        expect(body.history).toEqual([
            { role: 'user', text: 'ถามแรก' },
            { role: 'bot', text: 'ตอบแรก' },
        ]);
        expect(chat.messages.value).toHaveLength(4);
    });

    it('ignores empty questions and a second send while waiting', async () => {
        const chat = useChatbot();
        expect(await chat.send('   ')).toBeNull();
        let resolve;
        axios.post.mockReturnValueOnce(new Promise((r) => (resolve = r)));
        const first = chat.send('หนึ่ง');
        expect(chat.isLoading.value).toBe(true);
        expect(await chat.send('สอง')).toBeNull();
        resolve(ok('ตอบ'));
        await first;
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(chat.isLoading.value).toBe(false);
    });

    it('keeps only internal navigation links from the AI', async () => {
        const chat = useChatbot();
        axios.post.mockResolvedValueOnce(ok('ไปเลย', '/markets'));
        expect((await chat.send('ตลาด')).navUrl).toBe('/markets');
        axios.post.mockResolvedValueOnce(ok('ไปเลย', '//evil.example'));
        expect((await chat.send('x')).navUrl).toBeNull();
        axios.post.mockResolvedValueOnce(ok('ไปเลย', 'javascript:alert(1)'));
        expect((await chat.send('y')).navUrl).toBeNull();
    });

    it('turns network errors into a failed reply that is not sent back as history', async () => {
        const chat = useChatbot();
        axios.post.mockRejectedValueOnce(new Error('offline'));
        const reply = await chat.send('ฮัลโหล', { errorText: 'ขอโทษค่ะ' });
        expect(reply).toMatchObject({ failed: true, text: 'ขอโทษค่ะ', navUrl: null });

        axios.post.mockResolvedValueOnce(ok('ตอบ'));
        await chat.send('อีกครั้ง');
        const history = axios.post.mock.calls[1][1].history;
        expect(history.some((m) => m.text === 'ขอโทษค่ะ')).toBe(false);
    });

    it('sends at most the last few turns and trims long ones', async () => {
        const chat = useChatbot();
        for (let i = 0; i < 6; i++) {
            axios.post.mockResolvedValueOnce(ok('ต'.repeat(1500)));
            await chat.send(`q${i}`);
        }
        axios.post.mockResolvedValueOnce(ok('last'));
        await chat.send('final');
        const history = axios.post.mock.calls.at(-1)[1].history;
        expect(history).toHaveLength(HISTORY_LIMIT);
        expect(history.every((m) => m.text.length <= 1000)).toBe(true);
    });

    it('opens and closes the shared chat window', () => {
        const chat = useChatbot();
        chat.open();
        expect(useChatbot().isOpen.value).toBe(true);
        chat.close();
        expect(useChatbot().isOpen.value).toBe(false);
    });
});
