/**
 * Shared number/currency formatting utilities
 * ฟังก์ชันจัดรูปแบบตัวเลขและสกุลเงินที่ใช้ร่วมกันทั้งแอป
 *
 * Consolidates duplicate formatPrice/formatCurrency/formatBalance
 * from multiple components into a single source of truth.
 * รวมฟังก์ชันที่ซ้ำกันจากหลายคอมโพเนนต์ไว้ที่เดียว
 */

/**
 * Format a crypto price with appropriate decimal places
 * จัดรูปแบบราคาคริปโตตามจำนวนทศนิยมที่เหมาะสม
 *
 * >= 1000 → 2 decimals (e.g., 98,432.50)
 * >= 1    → 4 decimals (e.g., 0.8920)
 * < 1     → 6 decimals (e.g., 0.184700)
 */
export function formatPrice(price: number): string {
  if (price >= 1_000) {
    return price.toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }
  if (price >= 1) {
    return price.toLocaleString('en-US', {
      minimumFractionDigits: 4,
      maximumFractionDigits: 4,
    });
  }
  return price.toLocaleString('en-US', {
    minimumFractionDigits: 6,
    maximumFractionDigits: 6,
  });
}

/**
 * Format a value as USD currency with $ prefix
 * จัดรูปแบบเป็นสกุลเงิน USD พร้อมเครื่องหมาย $
 */
export function formatCurrency(value: number): string {
  if (value >= 1000) {
    return '$' + value.toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }
  return '$' + value.toFixed(value < 1 ? 4 : 2);
}

/**
 * Format a token balance with appropriate precision
 * จัดรูปแบบยอดคงเหลือโทเค็นตามความแม่นยำที่เหมาะสม
 */
export function formatBalance(balance: number): string {
  return formatPrice(balance);
}

/**
 * Format a USD value (without $ prefix, with 2 decimals)
 * จัดรูปแบบค่า USD (ไม่มี $, ทศนิยม 2 ตำแหน่ง)
 */
export function formatUsdValue(value: number): string {
  return `$${value.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

/**
 * Format a number with specified decimal places
 * จัดรูปแบบตัวเลขตามจำนวนทศนิยมที่กำหนด
 */
export function formatNumber(value: number, decimals: number = 2): string {
  return value.toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
}

/**
 * Format an amount (e.g., for order book display)
 * จัดรูปแบบจำนวน (เช่น สำหรับแสดง order book)
 */
export function formatAmount(amount: number): string {
  if (amount >= 1) {
    return amount.toLocaleString('en-US', {
      minimumFractionDigits: 4,
      maximumFractionDigits: 4,
    });
  }
  return amount.toLocaleString('en-US', {
    minimumFractionDigits: 6,
    maximumFractionDigits: 6,
  });
}

/**
 * Parse a formatted number string back to a number
 * แปลงสตริงตัวเลขที่จัดรูปแบบแล้วกลับเป็นตัวเลข
 *
 * Handles comma-separated values and returns 0 for invalid input.
 * Also rejects negative numbers for trading inputs.
 * รองรับค่าที่คั่นด้วยจุลภาค และคืนค่า 0 สำหรับค่าที่ไม่ถูกต้อง
 */
export function parseInputNumber(text: string): number {
  const cleaned = text.replace(/,/g, '');
  const num = parseFloat(cleaned);
  if (isNaN(num) || num < 0) return 0;
  // Reject extremely large values / ปฏิเสธค่าที่ใหญ่เกินไป
  if (num > 1e15) return 0;
  return num;
}
