<script setup>
/**
 * TPIX TRADE — แสดงข้อความของบอทให้หน้าตาใกล้กับที่จะขึ้นใน Discord (ใช้ในหน้าทดสอบบอท /admin/discord)
 *
 * รับ payload รูปเดียวกับที่ส่งให้ Discord API: { content, embeds[], components[] }
 * ⚠️ ไม่ใช้ v-html — เนื้อหามาจาก AI/บทความ แยกเป็นชิ้น (ข้อความ · ตัวหนา · โค้ด · ลิงก์) แล้วให้ Vue escape เอง
 *
 * Developed by Xman Studio.
 */
defineProps({
    payload: { type: Object, required: true },
    botName: { type: String, default: 'TPIX TRADE' },
});

/** แยกข้อความเป็นชิ้นที่แสดงได้ปลอดภัย: ลิงก์ http(s) / **ตัวหนา** / `โค้ด` / ข้อความธรรมดา */
function tokens(text) {
    const out = [];
    String(text || '').split(/(https?:\/\/[^\s<>()]+)/g).forEach((part, i) => {
        if (!part) return;
        if (i % 2 === 1) {
            out.push({ type: 'link', value: part });
            return;
        }
        part.split(/(\*\*[^*\n]+\*\*|`[^`\n]+`)/g).forEach((piece) => {
            if (!piece) return;
            if (piece.startsWith('**') && piece.endsWith('**') && piece.length > 4) out.push({ type: 'bold', value: piece.slice(2, -2) });
            else if (piece.startsWith('`') && piece.endsWith('`') && piece.length > 2) out.push({ type: 'code', value: piece.slice(1, -1) });
            else out.push({ type: 'text', value: piece });
        });
    });
    return out;
}

const color = (value) => (Number.isInteger(value) ? `#${value.toString(16).padStart(6, '0')}` : '#4e5058');

const buttons = (payload) => (payload.components || [])
    .flatMap((row) => row.components || [])
    .filter((b) => b.style === 5 && /^https?:\/\//.test(b.url || ''));
</script>

<template>
    <div class="rounded-lg bg-[#313338] text-[#dbdee1] p-3 text-sm leading-relaxed">
        <div class="flex items-center gap-2 mb-1">
            <span class="w-7 h-7 rounded-full bg-primary-500/40 flex items-center justify-center text-[11px] font-bold text-white">TP</span>
            <span class="font-semibold text-white">{{ botName }}</span>
            <span class="text-[10px] px-1 rounded bg-[#5865f2] text-white">BOT</span>
        </div>

        <p v-if="payload.content" class="whitespace-pre-line break-words">
            <template v-for="(t, i) in tokens(payload.content)" :key="i">
                <a v-if="t.type === 'link'" :href="t.value" target="_blank" rel="noopener noreferrer" class="text-[#00a8fc] hover:underline break-all">{{ t.value }}</a>
                <strong v-else-if="t.type === 'bold'" class="text-white">{{ t.value }}</strong>
                <code v-else-if="t.type === 'code'" class="px-1 rounded bg-black/30 text-[13px]">{{ t.value }}</code>
                <template v-else>{{ t.value }}</template>
            </template>
        </p>

        <div v-for="(embed, e) in payload.embeds || []" :key="e"
             class="mt-2 max-w-xl rounded border-l-4 bg-[#2b2d31] p-3" :style="{ borderColor: color(embed.color) }">
            <a v-if="embed.title && embed.url" :href="embed.url" target="_blank" rel="noopener noreferrer" class="font-semibold text-[#00a8fc] hover:underline">{{ embed.title }}</a>
            <p v-else-if="embed.title" class="font-semibold text-white">{{ embed.title }}</p>

            <p v-if="embed.description" class="mt-1 whitespace-pre-line break-words">
                <template v-for="(t, i) in tokens(embed.description)" :key="i">
                    <a v-if="t.type === 'link'" :href="t.value" target="_blank" rel="noopener noreferrer" class="text-[#00a8fc] hover:underline break-all">{{ t.value }}</a>
                    <strong v-else-if="t.type === 'bold'" class="text-white">{{ t.value }}</strong>
                    <code v-else-if="t.type === 'code'" class="px-1 rounded bg-black/30 text-[13px]">{{ t.value }}</code>
                    <template v-else>{{ t.value }}</template>
                </template>
            </p>

            <div v-for="(field, f) in embed.fields || []" :key="f" class="mt-2">
                <p class="font-semibold text-white text-xs">{{ field.name }}</p>
                <p class="whitespace-pre-line text-xs">{{ field.value }}</p>
            </div>

            <img v-if="embed.image?.url" :src="embed.image.url" alt="" class="mt-2 rounded max-h-48 object-cover" loading="lazy" />
            <p v-if="embed.footer?.text" class="mt-2 text-[11px] text-[#949ba4]">{{ embed.footer.text }}</p>
        </div>

        <div v-if="buttons(payload).length" class="mt-2 flex flex-wrap gap-2">
            <a v-for="(b, i) in buttons(payload)" :key="i" :href="b.url" target="_blank" rel="noopener noreferrer"
               class="px-3 py-1 rounded bg-[#4e5058] hover:bg-[#6d6f78] text-white text-xs">{{ b.label }} ↗</a>
        </div>
    </div>
</template>
