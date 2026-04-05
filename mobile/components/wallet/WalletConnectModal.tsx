/**
 * Wallet Connect Modal v2 — สร้างจริง + เชื่อมต่อจริง
 *
 * 3 Sections:
 * 1. TPIX Chain (แนะนำ) — สร้างใหม่ / import / เปิด TPIX Wallet app
 * 2. กระเป๋าที่ติดตั้งแล้ว — MetaMask, Trust, etc.
 * 3. กระเป๋าอื่น — ดาวน์โหลด
 *
 * + Backup mnemonic step (บังคับหลังสร้างใหม่)
 * + Import mnemonic step
 *
 * Developed by Xman Studio
 */

import React, { useState, useEffect, useRef } from 'react';
import {
  StyleSheet, Text, View, Modal, Pressable, ActivityIndicator,
  ScrollView, TextInput, Alert, Platform,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import * as Clipboard from 'expo-clipboard';
import * as Haptics from 'expo-haptics';
import { colors, spacing, radius, typography } from '@/theme';
import { useWalletStore } from '@/stores/walletStore';
import { openWalletDownload, WalletProvider } from '@/services/walletService';

type IoniconsName = ComponentProps<typeof Ionicons>['name'];

// ===================== Wallet Item =====================
function WalletItem({
  provider, installed, onConnect, onDownload, isConnecting,
}: {
  provider: WalletProvider; installed: boolean;
  onConnect: () => void; onDownload: () => void; isConnecting: boolean;
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

// ===================== Main Modal =====================
export default function WalletConnectModal() {
  const {
    isModalVisible, hideModal, modalStep, setModalStep,
    detectedWallets, isDetecting, isConnecting, connectError,
    connectExternalWallet, createNewWallet, importWallet,
    pendingMnemonic, confirmMnemonicBackup, clearError,
  } = useWalletStore();

  const [mnemonicInput, setMnemonicInput] = useState('');
  const [mnemonicCopied, setMnemonicCopied] = useState(false);
  const [mnemonicConfirmed, setMnemonicConfirmed] = useState(false);
  const [connectingProviderName, setConnectingProviderName] = useState<string | null>(null);
  const [countdown, setCountdown] = useState(0);
  const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Countdown timer สำหรับแสดงเวลาที่เหลือระหว่างรอ connect
  useEffect(() => {
    if (isConnecting && countdown > 0) {
      countdownRef.current = setInterval(() => {
        setCountdown((prev) => {
          if (prev <= 1) {
            if (countdownRef.current) clearInterval(countdownRef.current);
            return 0;
          }
          return prev - 1;
        });
      }, 1000);
    }
    return () => {
      if (countdownRef.current) clearInterval(countdownRef.current);
    };
  }, [isConnecting, countdown > 0]);

  // รีเซ็ต connecting state เมื่อ modal ปิดหรือ connect สำเร็จ
  useEffect(() => {
    if (!isConnecting) {
      setConnectingProviderName(null);
      setCountdown(0);
      if (countdownRef.current) clearInterval(countdownRef.current);
    }
  }, [isConnecting]);

  if (!isModalVisible) return null;

  const installedWallets = detectedWallets.filter(w => w.installed && w.provider.id !== 'tpix-wallet');
  const otherWallets = detectedWallets.filter(w => !w.installed && w.provider.id !== 'tpix-wallet');
  const tpixWalletDetected = detectedWallets.find(w => w.provider.id === 'tpix-wallet');

  const handleConnect = async (provider: WalletProvider) => {
    clearError();
    setConnectingProviderName(provider.name);
    setCountdown(10); // 10 วินาที timeout
    await connectExternalWallet(provider);
  };

  const handleCancelConnect = () => {
    hideModal();
    setConnectingProviderName(null);
    setCountdown(0);
  };

  const handleDownload = async (provider: WalletProvider) => {
    await openWalletDownload(provider);
  };

  const handleCopyMnemonic = async () => {
    if (pendingMnemonic) {
      await Clipboard.setStringAsync(pendingMnemonic);
      setMnemonicCopied(true);
      if (Platform.OS !== 'web') Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      setTimeout(() => setMnemonicCopied(false), 3000);
    }
  };

  const handleConfirmBackup = () => {
    if (!mnemonicConfirmed) {
      Alert.alert(
        'Confirm Backup',
        'Have you saved your recovery phrase? You cannot recover your wallet without it.',
        [
          { text: 'Not yet', style: 'cancel' },
          { text: 'Yes, I saved it', onPress: () => { setMnemonicConfirmed(true); confirmMnemonicBackup(); } },
        ]
      );
    } else {
      confirmMnemonicBackup();
    }
  };

  const handleImport = async () => {
    if (!mnemonicInput.trim()) return;
    const success = await importWallet(mnemonicInput);
    if (success) {
      setMnemonicInput('');
    }
  };

  // ===================== RENDER =====================
  return (
    <Modal visible={isModalVisible} transparent animationType="slide" statusBarTranslucent onRequestClose={hideModal}>
      <View style={styles.overlay}>
        <Pressable style={styles.backdrop} onPress={hideModal} />
        <View style={styles.sheet}>
          <View style={styles.handleBar} />

          {/* ===== BACKUP MNEMONIC STEP ===== */}
          {modalStep === 'backup' && pendingMnemonic ? (
            <ScrollView showsVerticalScrollIndicator={false}>
              <View style={styles.header}>
                <View>
                  <Text style={styles.title}>🔐 Backup Recovery Phrase</Text>
                  <Text style={styles.subtitle}>เก็บ 12 คำนี้ให้ปลอดภัย — ห้ามแชร์กับใคร</Text>
                </View>
              </View>

              <View style={styles.warningBox}>
                <Ionicons name="warning-outline" size={20} color="#F59E0B" />
                <Text style={styles.warningText}>
                  Write these words down and keep them safe. If you lose them, you lose access to your wallet forever.
                </Text>
              </View>

              <View style={styles.mnemonicGrid}>
                {pendingMnemonic.split(' ').map((word, i) => (
                  <View key={i} style={styles.mnemonicWord}>
                    <Text style={styles.mnemonicIndex}>{i + 1}</Text>
                    <Text style={styles.mnemonicText}>{word}</Text>
                  </View>
                ))}
              </View>

              <Pressable style={styles.copyBtn} onPress={handleCopyMnemonic}>
                <Ionicons name={mnemonicCopied ? 'checkmark-circle' : 'copy-outline'} size={18} color={mnemonicCopied ? colors.trading.green : colors.brand.cyan} />
                <Text style={[styles.copyText, mnemonicCopied && { color: colors.trading.green }]}>
                  {mnemonicCopied ? 'Copied!' : 'Copy to clipboard'}
                </Text>
              </Pressable>

              <Pressable style={styles.primaryBtn} onPress={handleConfirmBackup}>
                <Text style={styles.primaryBtnText}>I've saved my recovery phrase</Text>
              </Pressable>
            </ScrollView>
          ) : modalStep === 'import' ? (
            /* ===== IMPORT WALLET STEP ===== */
            <ScrollView showsVerticalScrollIndicator={false}>
              <View style={styles.header}>
                <View>
                  <Text style={styles.title}>Import Wallet</Text>
                  <Text style={styles.subtitle}>นำเข้ากระเป๋าด้วย Recovery Phrase (12 คำ)</Text>
                </View>
                <Pressable style={styles.closeBtn} onPress={() => setModalStep('choose')}>
                  <Ionicons name="arrow-back" size={20} color={colors.text.tertiary} />
                </Pressable>
              </View>

              <TextInput
                style={styles.mnemonicInput}
                placeholder="Enter 12-word recovery phrase..."
                placeholderTextColor={colors.text.disabled}
                value={mnemonicInput}
                onChangeText={setMnemonicInput}
                multiline
                numberOfLines={3}
                autoCapitalize="none"
                autoCorrect={false}
              />

              {connectError && (
                <View style={styles.errorBox}>
                  <Ionicons name="alert-circle" size={16} color={colors.trading.red} />
                  <Text style={styles.errorText}>{connectError}</Text>
                </View>
              )}

              <Pressable
                style={[styles.primaryBtn, (!mnemonicInput.trim() || isConnecting) && styles.primaryBtnDisabled]}
                onPress={handleImport}
                disabled={!mnemonicInput.trim() || isConnecting}
              >
                {isConnecting ? (
                  <ActivityIndicator size="small" color="#fff" />
                ) : (
                  <Text style={styles.primaryBtnText}>Import Wallet</Text>
                )}
              </Pressable>
            </ScrollView>
          ) : (
            /* ===== CHOOSE WALLET STEP (default) ===== */
            <ScrollView showsVerticalScrollIndicator={false}>
              <View style={styles.header}>
                <View>
                  <Text style={styles.title}>Connect Wallet</Text>
                  <Text style={styles.subtitle}>เชื่อมต่อกระเป๋าเงิน</Text>
                </View>
                <Pressable style={styles.closeBtn} onPress={hideModal}>
                  <Ionicons name="close" size={20} color={colors.text.tertiary} />
                </Pressable>
              </View>

              {isDetecting && (
                <View style={styles.scanningRow}>
                  <ActivityIndicator size="small" color={colors.brand.cyan} />
                  <Text style={styles.scanningText}>Scanning wallets...</Text>
                </View>
              )}

              {/* Connecting status — แสดงกระเป๋าที่กำลังเชื่อมต่อ + ปุ่มยกเลิก */}
              {isConnecting && connectingProviderName && (
                <View style={styles.connectingBox}>
                  <View style={styles.connectingInfo}>
                    <ActivityIndicator size="small" color={colors.brand.cyan} />
                    <View style={{ flex: 1 }}>
                      <Text style={styles.connectingText}>Connecting to {connectingProviderName}...</Text>
                      {countdown > 0 && (
                        <Text style={styles.connectingCountdown}>Timeout in {countdown}s</Text>
                      )}
                    </View>
                  </View>
                  <Pressable style={styles.connectCancelBtn} onPress={handleCancelConnect}>
                    <Text style={styles.connectCancelText}>Cancel</Text>
                  </Pressable>
                </View>
              )}

              {connectError && (
                <View style={styles.errorBox}>
                  <Ionicons name="alert-circle" size={16} color={colors.trading.red} />
                  <Text style={styles.errorText}>{connectError}</Text>
                </View>
              )}

              {/* Section 1: TPIX Chain */}
              <Text style={styles.sectionLabel}>TPIX CHAIN (RECOMMENDED)</Text>
              <View style={styles.tpixSection}>
                {/* สร้างกระเป๋าใหม่ */}
                <Pressable style={styles.tpixAction} onPress={createNewWallet} disabled={isConnecting}>
                  <LinearGradient colors={['rgba(6,182,212,0.15)', 'rgba(139,92,246,0.15)']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.tpixActionGradient}>
                    <View style={[styles.walletIcon, { backgroundColor: colors.brand.cyan + '20' }]}>
                      <Ionicons name="add-circle" size={22} color={colors.brand.cyan} />
                    </View>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.walletName}>Create New Wallet</Text>
                      <Text style={styles.walletChains}>สร้างกระเป๋าใหม่ · Free · Instant</Text>
                    </View>
                    {isConnecting ? <ActivityIndicator size="small" color={colors.brand.cyan} /> : <Ionicons name="chevron-forward" size={18} color={colors.brand.cyan} />}
                  </LinearGradient>
                </Pressable>

                {/* Import กระเป๋า */}
                <Pressable style={styles.tpixAction} onPress={() => setModalStep('import')}>
                  <View style={styles.tpixActionFlat}>
                    <View style={[styles.walletIcon, { backgroundColor: '#8B5CF6' + '20' }]}>
                      <Ionicons name="key" size={22} color="#8B5CF6" />
                    </View>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.walletName}>Import Wallet</Text>
                      <Text style={styles.walletChains}>นำเข้าด้วย Recovery Phrase</Text>
                    </View>
                    <Ionicons name="chevron-forward" size={18} color={colors.text.tertiary} />
                  </View>
                </Pressable>

                {/* เปิด TPIX Wallet app */}
                {tpixWalletDetected ? (
                  <WalletItem
                    provider={tpixWalletDetected.provider}
                    installed={tpixWalletDetected.installed}
                    onConnect={() => handleConnect(tpixWalletDetected.provider)}
                    onDownload={() => handleDownload(tpixWalletDetected.provider)}
                    isConnecting={isConnecting}
                  />
                ) : (
                  <Pressable style={styles.tpixAction} onPress={() => handleDownload({ downloadUrl: { android: 'https://tpix.online/download', ios: 'https://tpix.online/download' } } as WalletProvider)}>
                    <View style={styles.tpixActionFlat}>
                      <View style={[styles.walletIcon, { backgroundColor: '#10B981' + '20' }]}>
                        <Ionicons name="download" size={22} color="#10B981" />
                      </View>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.walletName}>Download TPIX Wallet</Text>
                        <Text style={styles.walletChains}>ดาวน์โหลดแอป TPIX Wallet</Text>
                      </View>
                      <Ionicons name="open-outline" size={18} color={colors.text.tertiary} />
                    </View>
                  </Pressable>
                )}
              </View>

              {/* Section 2: Installed Wallets */}
              {installedWallets.length > 0 && (
                <>
                  <Text style={styles.sectionLabel}>DETECTED WALLETS</Text>
                  {installedWallets.map(({ provider, installed }) => (
                    <WalletItem
                      key={provider.id}
                      provider={provider}
                      installed={installed}
                      onConnect={() => handleConnect(provider)}
                      onDownload={() => handleDownload(provider)}
                      isConnecting={isConnecting}
                    />
                  ))}
                </>
              )}

              {/* Section 3: More Wallets */}
              {otherWallets.length > 0 && (
                <>
                  <Text style={styles.sectionLabel}>MORE WALLETS</Text>
                  {otherWallets.map(({ provider, installed }) => (
                    <WalletItem
                      key={provider.id}
                      provider={provider}
                      installed={installed}
                      onConnect={() => handleConnect(provider)}
                      onDownload={() => handleDownload(provider)}
                      isConnecting={isConnecting}
                    />
                  ))}
                </>
              )}

              <View style={{ height: 40 }} />
            </ScrollView>
          )}
        </View>
      </View>
    </Modal>
  );
}

// ===================== STYLES =====================
const styles = StyleSheet.create({
  overlay: { flex: 1, justifyContent: 'flex-end' },
  backdrop: { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(0,0,0,0.6)' },
  sheet: { backgroundColor: colors.bg.secondary, borderTopLeftRadius: 24, borderTopRightRadius: 24, maxHeight: '85%', paddingHorizontal: spacing.xl, paddingBottom: spacing['3xl'] },
  handleBar: { width: 40, height: 4, borderRadius: 2, backgroundColor: colors.bg.tertiary, alignSelf: 'center', marginTop: spacing.md, marginBottom: spacing.lg },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: spacing.xl },
  title: { ...typography.h3, color: colors.text.primary },
  subtitle: { ...typography.bodySmall, color: colors.text.tertiary, marginTop: 2 },
  closeBtn: { width: 36, height: 36, borderRadius: 18, backgroundColor: colors.bg.tertiary, alignItems: 'center', justifyContent: 'center' },

  // Connecting state
  connectingBox: { marginBottom: spacing.lg, padding: spacing.md, borderRadius: radius.md, backgroundColor: colors.brand.cyan + '10', borderWidth: 1, borderColor: colors.brand.cyan + '30', gap: spacing.sm },
  connectingInfo: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  connectingText: { ...typography.bodySmall, color: colors.brand.cyan, fontWeight: '600' },
  connectingCountdown: { ...typography.caption, color: colors.text.tertiary, marginTop: 1 },
  connectCancelBtn: { alignSelf: 'flex-end', paddingVertical: spacing.xs, paddingHorizontal: spacing.lg, borderRadius: radius.sm, borderWidth: 1, borderColor: colors.trading.red + '50', backgroundColor: colors.trading.red + '10' },
  connectCancelText: { ...typography.bodySmall, color: colors.trading.red, fontWeight: '600', fontSize: 12 },

  // Scanning & Error
  scanningRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginBottom: spacing.lg, padding: spacing.md, borderRadius: radius.md, backgroundColor: colors.brand.cyan + '10' },
  scanningText: { ...typography.bodySmall, color: colors.brand.cyan },
  errorBox: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginBottom: spacing.lg, padding: spacing.md, borderRadius: radius.md, backgroundColor: colors.trading.red + '15', borderWidth: 1, borderColor: colors.trading.red + '30' },
  errorText: { ...typography.bodySmall, color: colors.trading.red, flex: 1 },

  // Section
  sectionLabel: { ...typography.caption, color: colors.text.tertiary, marginBottom: spacing.sm, marginTop: spacing.lg, letterSpacing: 1 },

  // TPIX Section
  tpixSection: { gap: spacing.sm },
  tpixAction: { borderRadius: radius.lg, overflow: 'hidden' },
  tpixActionGradient: { flexDirection: 'row', alignItems: 'center', padding: spacing.md, gap: spacing.md, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.brand.cyan + '30' },
  tpixActionFlat: { flexDirection: 'row', alignItems: 'center', padding: spacing.md, gap: spacing.md, borderRadius: radius.lg, backgroundColor: colors.bg.card, borderWidth: 1, borderColor: colors.bg.cardBorder },

  // Wallet Items
  walletItem: { flexDirection: 'row', alignItems: 'center', padding: spacing.md, gap: spacing.md, borderRadius: radius.lg, backgroundColor: colors.bg.card, borderWidth: 1, borderColor: colors.bg.cardBorder, marginBottom: spacing.xs },
  walletItemInstalled: { borderColor: colors.brand.cyan + '30' },
  walletIcon: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  walletInfo: { flex: 1 },
  walletNameRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  walletName: { ...typography.body, color: colors.text.primary, fontWeight: '600', fontSize: 14 },
  walletChains: { ...typography.bodySmall, color: colors.text.tertiary, fontSize: 11, marginTop: 1 },
  installedBadge: { flexDirection: 'row', alignItems: 'center', gap: 3, backgroundColor: colors.trading.greenBg, paddingHorizontal: 6, paddingVertical: 2, borderRadius: 8 },
  installedDot: { width: 5, height: 5, borderRadius: 3, backgroundColor: colors.trading.green },
  installedText: { fontSize: 9, color: colors.trading.green, fontWeight: '600' },

  // Backup Mnemonic
  warningBox: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm, padding: spacing.md, borderRadius: radius.md, backgroundColor: '#F59E0B15', borderWidth: 1, borderColor: '#F59E0B30', marginBottom: spacing.lg },
  warningText: { ...typography.bodySmall, color: '#F59E0B', flex: 1 },
  mnemonicGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.lg },
  mnemonicWord: { flexDirection: 'row', alignItems: 'center', gap: spacing.xs, backgroundColor: colors.bg.card, borderWidth: 1, borderColor: colors.bg.cardBorder, borderRadius: radius.md, paddingHorizontal: spacing.md, paddingVertical: spacing.sm, width: '30%', flexGrow: 1 },
  mnemonicIndex: { ...typography.caption, color: colors.text.disabled, width: 16 },
  mnemonicText: { ...typography.body, color: colors.text.primary, fontSize: 13, fontWeight: '600' },
  copyBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: spacing.sm, padding: spacing.md, borderRadius: radius.md, backgroundColor: colors.bg.card, borderWidth: 1, borderColor: colors.bg.cardBorder, marginBottom: spacing.lg },
  copyText: { ...typography.bodySmall, color: colors.brand.cyan, fontWeight: '600' },

  // Import
  mnemonicInput: { ...typography.body, color: colors.text.primary, backgroundColor: colors.bg.card, borderWidth: 1, borderColor: colors.bg.cardBorder, borderRadius: radius.lg, padding: spacing.lg, minHeight: 100, textAlignVertical: 'top', marginBottom: spacing.lg },

  // Buttons
  primaryBtn: { backgroundColor: colors.brand.cyan, borderRadius: radius.lg, padding: spacing.lg, alignItems: 'center', marginBottom: spacing.lg },
  primaryBtnDisabled: { opacity: 0.5 },
  primaryBtnText: { ...typography.body, color: '#000', fontWeight: '700', fontSize: 15 },
});
