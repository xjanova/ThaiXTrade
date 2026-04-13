/**
 * Wallet Connect Modal v3 — Simplified & Reliable
 *
 * 2 Options:
 * 1. Create New Wallet — สร้างกระเป๋าใหม่ (แนะนำ)
 * 2. Import Wallet — นำเข้าจาก Recovery Phrase
 *
 * + Backup mnemonic step (บังคับหลังสร้างใหม่)
 *
 * v3 Changes:
 * - ลบ wallet scanning/detection (ไม่เสถียร)
 * - ลบ external wallet deep link (ยังไม่มี WalletConnect v2)
 * - UI ง่ายขึ้น โหลดเร็วขึ้น ไม่ค้าง
 *
 * Developed by Xman Studio
 */

import React, { useState } from 'react';
import {
  StyleSheet, Text, View, Modal, Pressable, ActivityIndicator,
  ScrollView, TextInput, Alert, Platform,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import * as Clipboard from 'expo-clipboard';
import * as Haptics from 'expo-haptics';
import { colors, spacing, radius, typography } from '@/theme';
import { useWalletStore } from '@/stores/walletStore';

export default function WalletConnectModal() {
  const {
    isModalVisible, hideModal, modalStep, setModalStep,
    isConnecting, connectError,
    createNewWallet, importWallet,
    pendingMnemonic, confirmMnemonicBackup, clearError,
  } = useWalletStore();

  const [mnemonicInput, setMnemonicInput] = useState('');
  const [mnemonicCopied, setMnemonicCopied] = useState(false);

  if (!isModalVisible) return null;

  const haptic = () => {
    if (Platform.OS !== 'web') Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
  };

  const handleCreate = async () => {
    haptic();
    await createNewWallet();
  };

  const handleCopyMnemonic = async () => {
    if (!pendingMnemonic) return;
    await Clipboard.setStringAsync(pendingMnemonic);
    setMnemonicCopied(true);
    if (Platform.OS !== 'web') Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
    setTimeout(() => setMnemonicCopied(false), 3000);
  };

  const handleConfirmBackup = () => {
    Alert.alert(
      'Confirm Backup',
      'Have you saved your recovery phrase? You cannot recover your wallet without it.',
      [
        { text: 'Not yet', style: 'cancel' },
        { text: 'Yes, I saved it', onPress: () => { haptic(); confirmMnemonicBackup(); } },
      ]
    );
  };

  const handleImport = async () => {
    const trimmed = mnemonicInput.trim();
    if (!trimmed) return;
    haptic();
    const success = await importWallet(trimmed);
    if (success) setMnemonicInput('');
  };

  // ป้องกันปิด modal ระหว่าง backup step (ต้องกด confirm ก่อน)
  const isBackupStep = modalStep === 'backup' && !!pendingMnemonic;

  const handleDismiss = () => {
    if (isBackupStep) {
      // บังคับ confirm ก่อนปิด
      handleConfirmBackup();
      return;
    }
    hideModal();
  };

  // ===================== RENDER =====================
  return (
    <Modal visible={isModalVisible} transparent animationType="slide" statusBarTranslucent onRequestClose={handleDismiss}>
      <View style={styles.overlay}>
        <Pressable style={styles.backdrop} onPress={handleDismiss} />
        <View style={styles.sheet}>
          <View style={styles.handleBar} />

          {/* ===== BACKUP MNEMONIC STEP ===== */}
          {modalStep === 'backup' && pendingMnemonic ? (
            <ScrollView showsVerticalScrollIndicator={false}>
              <View style={styles.header}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.title}>Backup Recovery Phrase</Text>
                  <Text style={styles.subtitle}>Write down and keep safe</Text>
                </View>
              </View>

              <View style={styles.warningBox}>
                <Ionicons name="warning-outline" size={20} color="#F59E0B" />
                <Text style={styles.warningText}>
                  Write these 12 words down and keep them safe. If you lose them, you lose access to your wallet forever.
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
                <Ionicons
                  name={mnemonicCopied ? 'checkmark-circle' : 'copy-outline'}
                  size={18}
                  color={mnemonicCopied ? colors.trading.green : colors.brand.cyan}
                />
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
                <View style={{ flex: 1 }}>
                  <Text style={styles.title}>Import Wallet</Text>
                  <Text style={styles.subtitle}>Enter your 12-word recovery phrase</Text>
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
                  <ActivityIndicator size="small" color="#000" />
                ) : (
                  <Text style={styles.primaryBtnText}>Import Wallet</Text>
                )}
              </Pressable>
            </ScrollView>

          ) : (
            /* ===== CHOOSE WALLET STEP (default) ===== */
            <ScrollView showsVerticalScrollIndicator={false}>
              <View style={styles.header}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.title}>Connect Wallet</Text>
                  <Text style={styles.subtitle}>Create or import a wallet</Text>
                </View>
                <Pressable style={styles.closeBtn} onPress={hideModal}>
                  <Ionicons name="close" size={20} color={colors.text.tertiary} />
                </Pressable>
              </View>

              {connectError && (
                <View style={styles.errorBox}>
                  <Ionicons name="alert-circle" size={16} color={colors.trading.red} />
                  <Text style={styles.errorText}>{connectError}</Text>
                </View>
              )}

              {/* Create New Wallet — Recommended */}
              <Pressable
                style={[styles.optionCard, isConnecting && styles.optionDisabled]}
                onPress={handleCreate}
                disabled={isConnecting}
              >
                <LinearGradient
                  colors={['rgba(6,182,212,0.15)', 'rgba(139,92,246,0.15)']}
                  start={{ x: 0, y: 0 }}
                  end={{ x: 1, y: 1 }}
                  style={styles.optionGradient}
                >
                  <View style={[styles.optionIcon, { backgroundColor: colors.brand.cyan + '20' }]}>
                    <Ionicons name="add-circle" size={28} color={colors.brand.cyan} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <View style={styles.optionTitleRow}>
                      <Text style={styles.optionTitle}>Create New Wallet</Text>
                      <View style={styles.recommendedBadge}>
                        <Text style={styles.recommendedText}>RECOMMENDED</Text>
                      </View>
                    </View>
                    <Text style={styles.optionDesc}>Generate a new TPIX wallet instantly</Text>
                  </View>
                  {isConnecting ? (
                    <ActivityIndicator size="small" color={colors.brand.cyan} />
                  ) : (
                    <Ionicons name="chevron-forward" size={20} color={colors.brand.cyan} />
                  )}
                </LinearGradient>
              </Pressable>

              {/* Import Wallet */}
              <Pressable
                style={styles.optionCard}
                onPress={() => { clearError(); setModalStep('import'); }}
              >
                <View style={styles.optionFlat}>
                  <View style={[styles.optionIcon, { backgroundColor: '#8B5CF6' + '20' }]}>
                    <Ionicons name="key" size={28} color="#8B5CF6" />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.optionTitle}>Import Wallet</Text>
                    <Text style={styles.optionDesc}>Restore from 12-word recovery phrase</Text>
                  </View>
                  <Ionicons name="chevron-forward" size={20} color={colors.text.tertiary} />
                </View>
              </Pressable>

              {/* Info */}
              <View style={styles.infoBox}>
                <Ionicons name="shield-checkmark-outline" size={16} color={colors.text.tertiary} />
                <Text style={styles.infoText}>
                  Your private keys are stored securely on your device and never shared.
                </Text>
              </View>

              <View style={{ height: 20 }} />
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
  sheet: {
    backgroundColor: colors.bg.secondary,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    maxHeight: '80%',
    paddingHorizontal: spacing.xl,
    paddingBottom: spacing['3xl'],
  },
  handleBar: {
    width: 40, height: 4, borderRadius: 2,
    backgroundColor: colors.bg.tertiary,
    alignSelf: 'center',
    marginTop: spacing.md,
    marginBottom: spacing.lg,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    marginBottom: spacing.xl,
  },
  title: { ...typography.h3, color: colors.text.primary },
  subtitle: { ...typography.bodySmall, color: colors.text.tertiary, marginTop: 2 },
  closeBtn: {
    width: 36, height: 36, borderRadius: 18,
    backgroundColor: colors.bg.tertiary,
    alignItems: 'center', justifyContent: 'center',
  },

  // Options
  optionCard: { marginBottom: spacing.md, borderRadius: radius.lg, overflow: 'hidden' },
  optionDisabled: { opacity: 0.7 },
  optionGradient: {
    flexDirection: 'row', alignItems: 'center',
    padding: spacing.lg, gap: spacing.md,
    borderRadius: radius.lg,
    borderWidth: 1, borderColor: colors.brand.cyan + '30',
  },
  optionFlat: {
    flexDirection: 'row', alignItems: 'center',
    padding: spacing.lg, gap: spacing.md,
    borderRadius: radius.lg,
    backgroundColor: colors.bg.card,
    borderWidth: 1, borderColor: colors.bg.cardBorder,
  },
  optionIcon: {
    width: 48, height: 48, borderRadius: 14,
    alignItems: 'center', justifyContent: 'center',
  },
  optionTitleRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  optionTitle: { ...typography.body, color: colors.text.primary, fontWeight: '600', fontSize: 15 },
  optionDesc: { ...typography.bodySmall, color: colors.text.tertiary, fontSize: 12, marginTop: 2 },
  recommendedBadge: {
    backgroundColor: colors.brand.cyan + '20',
    paddingHorizontal: 6, paddingVertical: 2,
    borderRadius: 6,
  },
  recommendedText: {
    fontSize: 8, fontWeight: '700', color: colors.brand.cyan, letterSpacing: 0.5,
  },

  // Error & Info
  errorBox: {
    flexDirection: 'row', alignItems: 'center', gap: spacing.sm,
    marginBottom: spacing.lg, padding: spacing.md,
    borderRadius: radius.md,
    backgroundColor: colors.trading.red + '15',
    borderWidth: 1, borderColor: colors.trading.red + '30',
  },
  errorText: { ...typography.bodySmall, color: colors.trading.red, flex: 1 },
  infoBox: {
    flexDirection: 'row', alignItems: 'center', gap: spacing.sm,
    marginTop: spacing.md, padding: spacing.md,
    borderRadius: radius.md,
    backgroundColor: colors.bg.card,
  },
  infoText: { ...typography.caption, color: colors.text.tertiary, flex: 1 },

  // Backup Mnemonic
  warningBox: {
    flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm,
    padding: spacing.md, borderRadius: radius.md,
    backgroundColor: '#F59E0B15',
    borderWidth: 1, borderColor: '#F59E0B30',
    marginBottom: spacing.lg,
  },
  warningText: { ...typography.bodySmall, color: '#F59E0B', flex: 1 },
  mnemonicGrid: {
    flexDirection: 'row', flexWrap: 'wrap',
    gap: spacing.sm, marginBottom: spacing.lg,
  },
  mnemonicWord: {
    flexDirection: 'row', alignItems: 'center', gap: spacing.xs,
    backgroundColor: colors.bg.card,
    borderWidth: 1, borderColor: colors.bg.cardBorder,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md, paddingVertical: spacing.sm,
    width: '30%', flexGrow: 1,
  },
  mnemonicIndex: { ...typography.caption, color: colors.text.disabled, width: 16 },
  mnemonicText: { ...typography.body, color: colors.text.primary, fontSize: 13, fontWeight: '600' },
  copyBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center',
    gap: spacing.sm, padding: spacing.md,
    borderRadius: radius.md,
    backgroundColor: colors.bg.card,
    borderWidth: 1, borderColor: colors.bg.cardBorder,
    marginBottom: spacing.lg,
  },
  copyText: { ...typography.bodySmall, color: colors.brand.cyan, fontWeight: '600' },

  // Import
  mnemonicInput: {
    ...typography.body, color: colors.text.primary,
    backgroundColor: colors.bg.card,
    borderWidth: 1, borderColor: colors.bg.cardBorder,
    borderRadius: radius.lg,
    padding: spacing.lg,
    minHeight: 100,
    textAlignVertical: 'top',
    marginBottom: spacing.lg,
  },

  // Buttons
  primaryBtn: {
    backgroundColor: colors.brand.cyan,
    borderRadius: radius.lg,
    padding: spacing.lg,
    alignItems: 'center',
    marginBottom: spacing.lg,
  },
  primaryBtnDisabled: { opacity: 0.5 },
  primaryBtnText: { ...typography.body, color: '#000', fontWeight: '700', fontSize: 15 },
});
