<script setup>
/**
 * TokenomicsChart — Donut chart แสดงการกระจายเหรียญ TPIX
 * ใช้ Canvas API วาด donut chart (ไม่ต้องพึ่ง library)
 * Developed by Xman Studio
 */
import { ref, onMounted, watch } from 'vue';

const props = defineProps({
    /** ข้อมูล allocation แต่ละส่วน */
    data: {
        type: Array,
        default: () => [
            { label: 'Public Sale', percent: 10, color: '#06B6D4' },    // primary/cyan
            { label: 'Liquidity Pool', percent: 30, color: '#8B5CF6' }, // accent/purple
            { label: 'Master Node Rewards', percent: 20, color: '#00C853' }, // trading green
            { label: 'Team & Advisors', percent: 20, color: '#F97316' }, // warm/orange
            { label: 'Ecosystem Fund', percent: 10, color: '#3B82F6' }, // blue
            { label: 'Development', percent: 10, color: '#EC4899' },     // pink
        ],
    },
    /** ขนาด chart (px) */
    size: { type: Number, default: 280 },
});

const canvasRef = ref(null);

function drawChart() {
    const canvas = canvasRef.value;
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const size = props.size;

    // HiDPI support
    canvas.width = size * dpr;
    canvas.height = size * dpr;
    canvas.style.width = size + 'px';
    canvas.style.height = size + 'px';
    ctx.scale(dpr, dpr);

    const centerX = size / 2;
    const centerY = size / 2;
    const outerRadius = size / 2 - 10;
    const innerRadius = outerRadius * 0.6; // donut hole

    // วาด donut segments
    let currentAngle = -Math.PI / 2; // เริ่มจากด้านบน
    const total = props.data.reduce((sum, d) => sum + d.percent, 0);

    props.data.forEach((segment) => {
        const sliceAngle = (segment.percent / total) * 2 * Math.PI;

        ctx.beginPath();
        ctx.arc(centerX, centerY, outerRadius, currentAngle, currentAngle + sliceAngle);
        ctx.arc(centerX, centerY, innerRadius, currentAngle + sliceAngle, currentAngle, true);
        ctx.closePath();

        ctx.fillStyle = segment.color;
        ctx.fill();

        // เส้นขอบบางๆ
        ctx.strokeStyle = 'rgba(0,0,0,0.3)';
        ctx.lineWidth = 1;
        ctx.stroke();

        currentAngle += sliceAngle;
    });

    // วงกลมตรงกลาง (เพิ่มความชัด)
    ctx.beginPath();
    ctx.arc(centerX, centerY, innerRadius, 0, 2 * Math.PI);
    ctx.fillStyle = '#0c0e14'; // dark background
    ctx.fill();

    // ข้อความตรงกลาง
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 18px Inter, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('7B TPIX', centerX, centerY - 8);

    ctx.fillStyle = '#9CA3AF';
    ctx.font = '12px Inter, sans-serif';
    ctx.fillText('Total Supply', centerX, centerY + 12);
}

onMounted(drawChart);
watch(() => props.data, drawChart, { deep: true });
</script>

<template>
    <div class="tokenomics-chart">
        <h3 class="text-xl font-bold text-white mb-6 text-center">Tokenomics</h3>

        <div class="flex flex-col lg:flex-row items-center gap-8">
            <!-- Donut Chart -->
            <div class="flex-shrink-0">
                <canvas ref="canvasRef" />
            </div>

            <!-- Legend -->
            <div class="space-y-3 flex-1 min-w-[200px]">
                <div
                    v-for="item in data"
                    :key="item.label"
                    class="flex items-center gap-3"
                >
                    <div
                        class="w-3 h-3 rounded-full flex-shrink-0"
                        :style="{ backgroundColor: item.color }"
                    />
                    <div class="flex-1 flex justify-between items-center">
                        <span class="text-sm text-gray-300">{{ item.label }}</span>
                        <span class="text-sm font-bold text-white">{{ item.percent }}%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
