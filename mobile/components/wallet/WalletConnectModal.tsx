/**
 * Wallet Connect Modal - Auto-detect wallet apps
 * Modal เชื่อมต่อกระเป๋า - ตรวจจับแอปกระเป๋าอัตโนมัติ
 *
 * Detects installed wallet apps via deep linking.
 * Works from any browser on both Android and iOS.
 * ตรวจจับแอปกระเป๋าที่ติดตั้งผ่าน deep linking
 * ทำงานได้จากทุกเบราว์เซอร์ทั้ง Android และ iOS
 */

import React from 'react';
import {
  StyleSheet,
  Text,
  View,
  Modal,
  Pressable,
  ActivityIndicator,
  ScrollView,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { colors, spacing, radius, typography } from '@/theme';
import { useWalletStore } from '@/stores/walletStore';
import { openWalletDownload, WalletProvider } from '@/services/walletService';

type IoniconsName = ComponentProps<typeof Ionicons>['name'];

function WalletItem({
  provider,
  installed,
  onConnect,
  onDownload,
  isConnecting,
}: {
  provider: WalletProvider;
  installed: boolean;
  onConnect: () => void;
  onDownload: () => void;
  isConnecting: boolean;
}) {
  return (
    <Pressable
      style={[styles.walletItem, installed && styles.walletItemInstalled]}
      onPress={installed ? onConnect : onDownload}
      disabled={isConnecting}
    >
      <View style={[styles.walletIcon, { backgroundColor: provider.iconColor + '20' }]}>
        <Ionicons name={provider.icon as IoniconsName} size={22} color={provider.iconColor} />
      </View>

      <View style={styles.walletInfo}>
        <View style={styles.walletNameRow}>
          <Text style={styles.walletName}>{provider.name}</Text>
          {installed && (
            <View style={styles.installedBadge}>
              <View style={styles.installedDot} />
              <Text style={styles.installedText}>Installed</Text>
            </View>
          )}
        </View>
        <Text style={styles.walletChains} numberOfLines={1}>
          {provider.supportedChains.slice(0, 5).join(' · ')}
          {provider.supportedChains.length > 5 && ` +${provider.supportedChains.length - 5}`}
        </Text>
      </View>

      {isConnecting ? (
        <ActivityIndicator size="small" color={colors.brand.cyan} />
      ) : installed ? (
        <Ionicons name="open-outline" size={18} color={colors.brand.cyan} />
      ) : (
        <Ionicons name="download-outline" size={18} color={colors.text.tertiary} />
      )}
    </Pressable>
  );
}

export default function WalletConnectModal() {
  const {
    isModalVisible,
    hideModal,
    detectedWallets,
    isDetecting,
    isConnecting,
    connectError,
    connectWallet,
    createEmbeddedWallet,
    clearError,
  } = useWalletStore();

  if (!isModalVisible) return null;

  const installedWallets = detectedWallets.filter((w) => w.installed);
  const notInstalledWallets = detectedWallets.filter((w) => !w.installed);

  const handleConnect = async (provider: WalletProvider) => {
    clearError();
    await connectWallet(provider);
  };

  const handleDownload = async (provider: WalletProvider) => {
    await openWalletDownload(provider);
  };

  const handleCreateWallet = async () => {
    clearError();
    await createEmbeddedWallet();
  };

  return (
    <Modal
      visible={isModalVisible}
      transparent
      animationType="slide"
      statusBarTranslucent
      onRequestClose={hideModal}
    >
      <View style={styles.overlay}>
        <Pressable style={styles.backdrop} onPress={hideModal} />

        <View style={styles.sheet}>
          {/* Handle bar / แถบจับ */}
          <View style={styles.handleBar} />

          {/* Header */}
          <View style={styles.header}>
            <View>
              <Text style={styles.title}>Connect Wallet</Text>
              <Text style={styles.subtitle}>เชื่อมต่อกระเป๋าเงิน</Text>
            </View>
            <Pressable style={styles.closeBtn} onPress={hideModal}>
              <Ionicons name="close" size={20} color={colors.text.tertiary} />
            </Pressable>
          </View>

          {/* Scanning indicator / แถบสแกน */}
          {isDetecting && (
            <View style={styles.scanningRow}>
              <ActivityIndicator size="small" color={colors.brand.cyan} />
              <Text style={styles.scanningText}>
                Scanning for wallets... / กำลังสแกนหากระเป๋า...
              </Text>
            </View>
          )}

          {/* Error message / ข้อความ error */}
          {connectError && (
            <View style={styles.errorRow}>
              <Ionicons name="alert-circle" size={16} color={colors.trading.red} />
              <Text style={styles.errorText}>{connectError}</Text>
            </View>
          )}

          <ScrollView
            style={styles.walletList}
            showsVerticalScrollIndicator={false}
            contentContainerStyle={styles.walletListContent}
          >
            {/* ===== TPIX Embedded Wallet — สร้างได้เลยไม่ต้องติดตั้งแอปอื่น ===== */}
            <View style={styles.sectionHeader}>
              <View style={styles.sectionDot} />
              <Text style={styles.sectionLabel}>QUICK START / เริ่มต้นทันที</Text>
            </View>

            <Pressable
              style={[styles.walletItem, styles.embeddedWalletItem]}
              onPress={handleCreateWallet}
              disabled={isConnecting}
            >
              <View style={[styles.walletIcon, { backgroundColor: 'rgba(6, 182, 212, 0.2)' }]}>
                <Ionicons name="wallet-outline" size={22} color={colors.brand.cyan} />
              </View>

              <View style={styles.walletInfo}>
                <View style={styles.walletNameRow}>
                  <Text style={styles.walletName}>TPIX Wallet</Text>
                  <View style={[styles.installedBadge, { backgroundColor: 'rgba(6, 182, 212, 0.15)' }]}>
                    <Ionicons name="flash" size={8} color={colors.brand.cyan} />
                    <Text style={[styles.installedText, { color: colors.brand.cyan }]}>Instant</Text>
                  </View>
                </View>
                <Text style={styles.walletChains}>
                  Create wallet instantly · ETH · BSC · POLYGON
                </Text>
              </View>

              {isConnecting ? (
                <ActivityIndicator size="small" color={colors.brand.cyan} />
              ) : (
                <Ionicons name="add-circle-outline" size={20} color={colors.brand.cyan} />
              )}
            </Pressable>

            {/* Detected / Installed wallets */}
            {installedWallets.length > 0 && (
              <>
                <View style={styles.sectionHeader}>
                  <View style={styles.sectionDot} />
                  <Text style={styles.sectionLabel}>
                    DETECTED / ตรวจพบ ({installedWallets.length})
                  </Text>
                </View>

                {installedWallets.map(({ provider }) => (
                  <WalletItem
                    key={provider.id}
                    provider={provider}
                    installed
                    onConnect={() => handleConnect(provider)}
                    onDownload={() => handleDownload(provider)}
                    isConnecting={isConnecting}
                  />
                ))}
              </>
            )}

            {/* Not installed wallets */}
            {notInstalledWallets.length > 0 && (
              <>
                <View style={styles.sectionHeader}>
                  <Text style={styles.sectionLabel}>
                    {installedWallets.length > 0
                      ? 'MORE WALLETS / กระเป๋าอื่น'
                      : 'EXTERNAL WALLETS / กระเป๋าภายนอก'}
                  </Text>
                </View>

                {notInstalledWallets.map(({ provider }) => (
                  <WalletItem
                    key={provider.id}
                    provider={provider}
                    installed={false}
                    onConnect={() => handleConnect(provider)}
                    onDownload={() => handleDownload(provider)}
                    isConnecting={isConnecting}
                  />
                ))}
              </>
            )}

            {/* Info footer / ข้อมูลด้านล่าง */}
            <View style={styles.infoFooter}>
              <Ionicons name="shield-checkmark-outline" size={14} color={colors.text.disabled} />
              <Text style={styles.infoText}>
                TPIX Wallet creates a local trading wallet instantly. External wallets connect via deep link.
                {'\n'}TPIX Wallet สร้างกระเป๋าเทรดได้ทันที กระเป๋าภายนอกเชื่อมต่อผ่าน deep link
              </Text>
            </View>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    justifyContent: 'flex-end',
  },
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: colors.bg.overlay,
  },
  sheet: {
    backgroundColor: colors.bg.secondary,
    borderTopLeftRadius: radius['2xl'],
    borderTopRightRadius: radius['2xl'],
    borderWidth: 1,
    borderBottomWidth: 0,
    borderColor: colors.bg.cardBorder,
    maxHeight: '80%',
    paddingBottom: spacing['3xl'],
  },
  handleBar: {
    width: 40,
    height: 4,
    backgroundColor: colors.text.disabled,
    borderRadius: 2,
    alignSelf: 'center',
    marginTop: spacing.md,
    marginBottom: spacing.lg,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.xl,
    marginBottom: spacing.lg,
  },
  title: {
    ...typography.h3,
    color: colors.text.primary,
  },
  subtitle: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    marginTop: 1,
  },
  closeBtn: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: colors.bg.tertiary,
    alignItems: 'center',
    justifyContent: 'center',
  },

  // Scanning / สแกน
  scanningRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingHorizontal: spacing.xl,
    marginBottom: spacing.md,
  },
  scanningText: {
    ...typography.bodySmall,
    color: colors.brand.cyan,
  },

  // Error
  errorRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginHorizontal: spacing.xl,
    marginBottom: spacing.md,
    backgroundColor: colors.trading.redBg,
    borderRadius: radius.sm,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
  },
  errorText: {
    ...typography.bodySmall,
    color: colors.trading.red,
    flex: 1,
  },

  // Wallet list / รายการกระเป๋า
  walletList: {
    flex: 1,
  },
  walletListContent: {
    paddingHorizontal: spacing.xl,
    paddingBottom: spacing.xl,
  },

  // Section header / หัวข้อ section
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.lg,
    marginBottom: spacing.sm,
  },
  sectionDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: colors.trading.green,
  },
  sectionLabel: {
    ...typography.caption,
    color: colors.text.tertiary,
    letterSpacing: 1,
  },

  // Wallet item / รายการกระเป๋า
  walletItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.md,
    borderRadius: radius.md,
    marginBottom: spacing.xs,
  },
  walletItemInstalled: {
    backgroundColor: 'rgba(6, 182, 212, 0.06)',
    borderWidth: 1,
    borderColor: 'rgba(6, 182, 212, 0.12)',
  },
  embeddedWalletItem: {
    backgroundColor: 'rgba(6, 182, 212, 0.1)',
    borderWidth: 1,
    borderColor: 'rgba(6, 182, 212, 0.25)',
  },
  walletIcon: {
    width: 44,
    height: 44,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  walletInfo: {
    flex: 1,
  },
  walletNameRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  walletName: {
    ...typography.body,
    color: colors.text.primary,
    fontWeight: '600',
    fontSize: 14,
  },
  installedBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 3,
    backgroundColor: colors.trading.greenBg,
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 8,
  },
  installedDot: {
    width: 5,
    height: 5,
    borderRadius: 3,
    backgroundColor: colors.trading.green,
  },
  installedText: {
    fontSize: 9,
    fontWeight: '700',
    color: colors.trading.green,
  },
  walletChains: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontSize: 11,
    marginTop: 2,
  },

  // Empty state / ไม่พบ
  emptyState: {
    alignItems: 'center',
    paddingVertical: spacing['4xl'],
    gap: spacing.md,
  },
  emptyText: {
    ...typography.body,
    color: colors.text.tertiary,
    textAlign: 'center',
  },

  // Info footer
  infoFooter: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    marginTop: spacing.xl,
    paddingTop: spacing.lg,
    borderTopWidth: 1,
    borderTopColor: colors.divider,
  },
  infoText: {
    ...typography.bodySmall,
    color: colors.text.disabled,
    fontSize: 10,
    flex: 1,
    lineHeight: 15,
  },
});
