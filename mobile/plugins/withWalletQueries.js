/**
 * Expo Config Plugin: Android Wallet Queries
 * เพิ่ม <queries> ใน AndroidManifest.xml สำหรับ Android 11+ (API 30+)
 *
 * Required for Linking.canOpenURL() to detect wallet apps.
 * จำเป็นเพื่อให้ Linking.canOpenURL() ตรวจจับแอปกระเป๋าได้
 */

const { withAndroidManifest } = require('expo/config-plugins');

const WALLET_SCHEMES = [
  'tpixwallet',
  'metamask',
  'trust',
  'cbwallet',
  'tpoutside',
  'okx',
  'phantom',
  'safepalwallet',
  'bitkeep',
];

function withWalletQueries(config) {
  return withAndroidManifest(config, (config) => {
    const manifest = config.modResults.manifest;

    // Add <queries> block with wallet deep link schemes
    // เพิ่มบล็อก <queries> พร้อม deep link scheme ของกระเป๋า
    if (!manifest.queries) {
      manifest.queries = [];
    }

    // Build intent entries for each wallet scheme
    // สร้าง intent entries สำหรับแต่ละ scheme ของกระเป๋า
    const intentEntries = WALLET_SCHEMES.map((scheme) => ({
      intent: [
        {
          action: [{ $: { 'android:name': 'android.intent.action.VIEW' } }],
          data: [{ $: { 'android:scheme': scheme } }],
        },
      ],
    }));

    manifest.queries.push(...intentEntries);

    return config;
  });
}

module.exports = withWalletQueries;
