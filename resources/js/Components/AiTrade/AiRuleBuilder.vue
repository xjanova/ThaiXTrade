<script setup>
/**
 * TPIX TRADE — ตัวสร้างกฎของโหมด "วางกลยุทธ์เอง"
 *
 * ผู้ใช้ประกอบเงื่อนไขเข้า/ออกจากบล็อกสำเร็จรูป — รายการบล็อก ช่อง และช่วงตัวเลขมาจากเซิร์ฟเวอร์
 * (catalog.custom_rules) คอมโพเนนต์นี้จึงไม่รู้จักชนิดบล็อกใดเป็นพิเศษ เพิ่มบล็อกที่ config แล้ววาดให้เอง
 *
 * แก้บนสำเนาของตัวเอง (draft) แล้วคืนกฎชุดใหม่ทั้งก้อนผ่าน update:modelValue — ไม่แก้ของที่รับมา
 * (กฎที่รับมาอาจเป็นของเทมเพลตในแคตตาล็อกที่ทุกฟอร์มใช้ร่วมกัน) และช่องตัวเลขผูกด้วย v-model.number
 * กับสำเนานั้น: ถ้าผูก :value ตรงกับ props ค่าที่พิมพ์ค้างอย่าง "0." จะถูกเขียนทับเป็น "0" ทุกครั้งที่พิมพ์
 *
 * ค่าที่ผิดช่วงถูกโชว์พร้อมคำเตือน ไม่ถูกแก้ให้เงียบๆ — กฎที่ถูกแก้ระหว่างทางคือกฎที่ผู้ใช้ไม่ได้ตั้ง
 * (ด่านจริงอยู่ที่เซิร์ฟเวอร์: RuleSet::validate ปฏิเสธพร้อมบอกจุดที่ผิด)
 *
 * Developed by Xman Studio
 */

import { computed, ref, watch } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';
import {
    RULE_SIDES, normalizeRules, blocksFor, blockSpec, maxBlocks, newBlock, blockProblems, problemMessage, cloneValue, timeframeMinutes,
} from '@/utils/aiRules';

const props = defineProps({
    modelValue: { type: Object, default: null },
    schema: { type: Object, default: null },
    timeframe: { type: String, default: '1h' },
});

const emit = defineEmits(['update:modelValue']);

const { t, locale } = useTranslation();

const rules = ref(normalizeRules(props.modelValue, props.schema));

// ของที่รับมาเปลี่ยนจากข้างนอก (เลือกเทมเพลต · เปิดแก้บอทอีกตัว · แคตตาล็อกเพิ่งโหลด) = เริ่มจากชุดนั้น
// ถ้าเป็นชุดเดียวกับที่เพิ่งส่งออกไปเองก็ไม่แตะ — ไม่งั้นช่องที่กำลังพิมพ์จะถูกวาดใหม่กลางคัน
watch(() => [props.modelValue, props.schema], () => {
    const incoming = normalizeRules(props.modelValue, props.schema);

    if (JSON.stringify(incoming) !== JSON.stringify(rules.value)) rules.value = incoming;
}, { deep: true });
const matches = computed(() => (props.schema?.match?.length ? props.schema.match : ['all', 'any']));

const sideText = {
    entry: { title: 'aiTrade.rules.entryTitle', when: 'aiTrade.rules.entryWhen' },
    exit: { title: 'aiTrade.rules.exitTitle', when: 'aiTrade.rules.exitWhen' },
};

function localized(source, field) {
    if (!source) return '';

    return (locale.value === 'th' ? source[field] : source[`${field}_en`] || source[field]) || '';
}

function optionLabel(field, option) {
    const labels = field.option_labels?.[option];

    return (locale.value === 'th' ? labels?.th : labels?.en) || labels?.th || option;
}

function matchLabel(match) {
    return match === 'any' ? t('aiTrade.rules.matchAny') : t('aiTrade.rules.matchAll');
}

function isFull(side) {
    return rules.value[side].blocks.length >= maxBlocks(props.schema, side);
}

/** ส่งกฎชุดปัจจุบันออกไปเป็นสำเนา — ผู้รับแก้ของที่ได้ไปแล้วต้องไม่ย้อนมาแก้ draft */
function commit() {
    emit('update:modelValue', cloneValue({ entry: rules.value.entry, exit: rules.value.exit }));
}

function change(side, mutate) {
    mutate(rules.value[side]);
    commit();
}

function setMatch(side, match) {
    if (!matches.value.includes(match)) return;
    change(side, (group) => { group.match = match; });
}

function addBlock(side) {
    if (isFull(side)) return;

    const first = blocksFor(props.schema, side)[0];
    const block = first ? newBlock(props.schema, first.type) : null;
    if (!block) return;

    change(side, (group) => { group.blocks.push(block); });
}

function removeBlock(side, index) {
    change(side, (group) => { group.blocks.splice(index, 1); });
}

/** เปลี่ยนชนิด = เริ่มจากค่าปริยายของชนิดใหม่ (ช่องของชนิดเดิมไม่มีความหมายกับชนิดใหม่) */
function setType(side, index, type) {
    const block = newBlock(props.schema, type);
    if (!block) return;

    change(side, (group) => { group.blocks.splice(index, 1, block); });
}

function setOption(side, index, field, value) {
    change(side, (group) => { group.blocks[index][field.key] = value; });
}

function problemsOf(block, side) {
    return blockProblems(block, side, props.schema)
        .map((problem) => problemMessage({ ...problem, type: block.type }, props.schema, t, locale.value));
}

/**
 * ช่องจำนวนเต็มของบล็อกนับเป็น "แท่ง" — บอกให้ผู้ใช้เห็นว่ากี่แท่งคือเวลาเท่าไหร่บนกรอบเวลาที่เลือก
 * (รอบซื้อ 6 แท่งบนแท่ง 4 ชม. = 24 ชม. · บนแท่ง 1 วัน = 6 วัน คนละเรื่องกันเลย)
 */
function barsAsTime(field, value) {
    const minutes = timeframeMinutes(props.timeframe);
    const bars = Number(value);

    if (field.type !== 'int' || !minutes || !Number.isFinite(bars) || bars <= 0) return '';

    const total = bars * minutes;
    const trim = (n) => String(Math.round(n * 10) / 10);

    if (total < 120) return t('aiTrade.rules.durMinutes', { n: trim(total) });
    if (total < 2880) return t('aiTrade.rules.durHours', { n: trim(total / 60) });

    return t('aiTrade.rules.durDays', { n: trim(total / 1440) });
}
</script>

<template>
    <div class="space-y-3" data-testid="rule-builder">
        <div>
            <p class="text-xs font-bold text-white">{{ t('aiTrade.rules.title') }}</p>
            <p class="text-[10px] text-dark-400 leading-relaxed">{{ t('aiTrade.rules.intro') }}</p>
        </div>

        <section
            v-for="side in RULE_SIDES"
            :key="side"
            class="rounded-xl border border-white/10 bg-white/5 p-3 space-y-2.5"
            :data-testid="`rule-group-${side}`"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span :class="['text-xs font-bold', side === 'entry' ? 'text-trading-green' : 'text-trading-red']">
                    {{ t(sideText[side].title) }}
                </span>
                <span class="text-[11px] text-dark-400">{{ t(sideText[side].when) }}</span>
                <select
                    class="trading-input text-xs py-1 w-auto"
                    :value="rules[side].match"
                    :aria-label="t(sideText[side].when)"
                    :data-testid="`rule-match-${side}`"
                    @change="setMatch(side, $event.target.value)"
                >
                    <option v-for="match in matches" :key="match" :value="match">{{ matchLabel(match) }}</option>
                </select>
                <span class="ml-auto text-[10px] text-dark-500 font-mono" :data-testid="`rule-count-${side}`">
                    {{ t('aiTrade.rules.count', { used: rules[side].blocks.length, max: maxBlocks(schema, side) }) }}
                </span>
            </div>

            <p
                v-if="!rules[side].blocks.length"
                :class="['text-[11px] leading-relaxed', side === 'entry' ? 'text-amber-300' : 'text-dark-400']"
                :data-testid="`rule-empty-${side}`"
            >
                {{ side === 'entry' ? t('aiTrade.rules.noEntry') : t('aiTrade.rules.noExit') }}
            </p>

            <div
                v-for="(block, index) in rules[side].blocks"
                :key="`${side}-${index}`"
                class="rounded-lg border border-white/10 bg-dark-900/40 p-2.5 space-y-2"
                :data-testid="`rule-block-${side}-${index}`"
            >
                <div class="flex items-center gap-2">
                    <span class="text-[10px] text-dark-500 font-mono w-4 shrink-0">{{ index + 1 }}</span>

                    <select
                        v-if="blockSpec(schema, block.type)"
                        class="trading-input text-xs py-1.5 flex-1 min-w-0"
                        :value="block.type"
                        :aria-label="t('aiTrade.rules.blockType')"
                        :data-testid="`rule-type-${side}-${index}`"
                        @change="setType(side, index, $event.target.value)"
                    >
                        <option v-for="spec in blocksFor(schema, side)" :key="spec.type" :value="spec.type">
                            {{ localized(spec, 'label') }}
                        </option>
                    </select>
                    <span v-else class="flex-1 min-w-0 text-xs text-dark-300 font-mono truncate">{{ block.type }}</span>

                    <button
                        type="button"
                        class="text-[11px] text-dark-400 hover:text-trading-red transition-colors shrink-0"
                        :data-testid="`rule-remove-${side}-${index}`"
                        @click="removeBlock(side, index)"
                    >
                        {{ t('aiTrade.rules.remove') }}
                    </button>
                </div>

                <template v-if="blockSpec(schema, block.type)">
                    <p class="text-[10px] text-dark-500 leading-relaxed">{{ localized(blockSpec(schema, block.type), 'hint') }}</p>

                    <div class="grid gap-2 sm:grid-cols-3">
                        <label v-for="field in blockSpec(schema, block.type).fields" :key="field.key" class="block">
                            <span class="block text-[10px] text-dark-400 mb-1">{{ localized(field, 'label') }}</span>

                            <select
                                v-if="field.type === 'select'"
                                class="trading-input text-xs py-1.5"
                                :value="block[field.key]"
                                :data-testid="`rule-field-${side}-${index}-${field.key}`"
                                @change="setOption(side, index, field, $event.target.value)"
                            >
                                <option v-for="option in field.options" :key="option" :value="option">{{ optionLabel(field, option) }}</option>
                            </select>
                            <!-- ช่องว่างเก็บเป็นค่าว่าง (ให้คำเตือนขึ้น) ไม่ใช่ 0 — 0 มีความหมายในบางช่อง -->
                            <input
                                v-else
                                v-model.number="block[field.key]"
                                type="number"
                                class="trading-input text-xs py-1.5 font-mono"
                                :min="field.min"
                                :max="field.max"
                                :step="field.type === 'int' ? 1 : (field.step ?? 'any')"
                                :data-testid="`rule-field-${side}-${index}-${field.key}`"
                                @input="commit"
                            >
                            <span v-if="barsAsTime(field, block[field.key])" class="block mt-0.5 text-[10px] text-dark-500">
                                {{ barsAsTime(field, block[field.key]) }}
                            </span>
                        </label>
                    </div>
                </template>

                <p
                    v-for="(problem, n) in problemsOf(block, side)"
                    :key="n"
                    class="text-[11px] text-trading-red leading-relaxed"
                    :data-testid="`rule-problem-${side}-${index}`"
                >
                    {{ problem }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="text-[11px] px-2.5 py-1 rounded-lg border border-white/15 text-primary-300 hover:border-primary-500/50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    :disabled="isFull(side) || !blocksFor(schema, side).length"
                    :data-testid="`rule-add-${side}`"
                    @click="addBlock(side)"
                >
                    {{ t('aiTrade.rules.add') }}
                </button>
                <span v-if="isFull(side)" class="text-[10px] text-dark-500">
                    {{ t('aiTrade.rules.maxReached', { max: maxBlocks(schema, side) }) }}
                </span>
            </div>

            <p v-if="side === 'exit'" class="text-[10px] text-dark-500 leading-relaxed">{{ t('aiTrade.rules.exitNote') }}</p>
        </section>
    </div>
</template>
