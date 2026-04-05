/**
 * Update Modal with Download Progress
 * Modal อัปเดตพร้อมแถบความคืบหน้าดาวน์โหลด
 *
 * Flow: Check → Show Modal → Download APK (progress bar) → Install
 * ขั้นตอน: ตรวจสอบ → แสดง Modal → ดาวน์โหลด APK (progress bar) → ติดตั้ง
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
import { colors, spacing, radius, typography } from '@/theme';
import { useUpdateStore } from '@/stores/updateStore';
import { formatFileSize, openDownloadPage } from '@/services/updateService';

export default function UpdateModal() {
  const {
    updateInfo,
    showModal,
    dismissModal,
    downloadStatus,
    downloadPercent,
    downloadedBytes,
    totalBytes,
    error,
    startDownload,
    startInstall,
    resetDownload,
    cancelDownload,
  } = useUpdateStore();

  if (!showModal || !updateInfo?.available) return null;

  const canDismiss = !updateInfo.mandatory;
  const isDownloading = downloadStatus === 'downloading';
  const isCompleted = downloadStatus === 'completed';
  const isInstalling = downloadStatus === 'installing';
  const isError = downloadStatus === 'error';
  const isIdle = downloadStatus === 'idle';

  const handleMainAction = () => {
    if (isIdle || isError) {
      startDownload();
    } else if (isCompleted) {
      startInstall();
    }
  };

  const handleRetry = () => {
    resetDownload();
    startDownload();
  };

  return (
    <Modal
      visible={showModal}
      transparent
      animationType="fade"
      statusBarTranslucent
      onRequestClose={canDismiss ? dismissModal : undefined}
    >
      <View style={styles.overlay}>
        <View style={styles.card}>
          {/* Header gradient bar */}
          <LinearGradient
            colors={colors.gradient.brand}
            start={{ x: 0, y: 0 }}
            end={{ x: 1, y: 0 }}
            style={styles.headerBar}
          />

          {/* Icon */}
          <View style={styles.iconContainer}>
            <LinearGradient
              colors={colors.gradient.brand}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={styles.iconGradient}
            >
              <Ionicons
                name={isCompleted ? 'checkmark-circle-outline' : 'rocket-outline'}
                size={28}
                color={colors.white}
              />
            </LinearGradient>
          </View>

          {/* Title / หัวข้อ */}
          <Text style={styles.title}>
            {isCompleted
              ? 'Download Complete'
              : isDownloading
              ? 'Downloading...'
              : isInstalling
              ? 'Installing...'
              : 'Update Available'}
          </Text>
          <Text style={styles.titleTh}>
            {isCompleted
              ? 'ดาวน์โหลดเสร็จสิ้น'
              : isDownloading
              ? 'กำลังดาวน์โหลด...'
              : isInstalling
              ? 'กำลังติดตั้ง...'
              : 'มีเวอร์ชันใหม่'}
          </Text>

          {/* Version info / ข้อมูลเวอร์ชัน */}
          <View style={styles.versionRow}>
            <View style={styles.versionBox}>
              <Text style={styles.versionLabel}>Current / ปัจจุบัน</Text>
              <Text style={styles.versionText}>v{updateInfo.currentVersion}</Text>
            </View>
            <Ionicons name="arrow-forward" size={20} color={colors.brand.cyan} />
            <View style={styles.versionBox}>
              <Text style={styles.versionLabel}>New / ใหม่</Text>
              <Text style={[styles.versionText, styles.versionNew]}>
                v{updateInfo.latestVersion}
              </Text>
            </View>
          </View>

          {/* Release notes / บันทึกการเปลี่ยนแปลง (เหมือน wallet: "What's new:") */}
          {isIdle && updateInfo.releaseNotes ? (
            <View style={styles.releaseNotesSection}>
              <Text style={styles.releaseNotesLabel}>What's new:</Text>
              <ScrollView style={styles.releaseNotesScroll} nestedScrollEnabled>
                <Text style={styles.releaseNotesText}>{updateInfo.releaseNotes}</Text>
              </ScrollView>
            </View>
          ) : null}

          {/* Download method indicator (เหมือน wallet: "Download & install automatically") */}
          {isIdle && !isError && (
            <View style={styles.downloadMethodBox}>
              <Ionicons
                name={updateInfo.downloadUrl ? 'download-outline' : 'open-outline'}
                size={16}
                color={colors.brand.cyan}
              />
              <Text style={styles.downloadMethodText}>
                {updateInfo.downloadUrl
                  ? 'Download & install automatically'
                  : 'Download from tpix.online'}
              </Text>
            </View>
          )}

          {/* ============ PROGRESS BAR SECTION ============ */}
          {(isDownloading || isCompleted || isInstalling) && (
            <View style={styles.progressSection}>
              {/* Progress bar background / พื้นหลัง progress bar */}
              <View style={styles.progressBarBg}>
                <LinearGradient
                  colors={isCompleted ? colors.gradient.green : colors.gradient.brand}
                  start={{ x: 0, y: 0 }}
                  end={{ x: 1, y: 0 }}
                  style={[styles.progressBarFill, { width: `${downloadPercent}%` }]}
                />
              </View>

              {/* Percent text / ข้อความเปอร์เซ็นต์ */}
              <View style={styles.progressInfo}>
                <Text style={styles.progressPercent}>{downloadPercent}%</Text>
                <Text style={styles.progressSize}>
                  {formatFileSize(downloadedBytes)} / {formatFileSize(totalBytes)}
                </Text>
              </View>

              {/* Status text / ข้อความสถานะ */}
              {isInstalling && (
                <View style={styles.installingRow}>
                  <ActivityIndicator size="small" color={colors.brand.cyan} />
                  <Text style={styles.installingText}>
                    Opening installer... / กำลังเปิดตัวติดตั้ง...
                  </Text>
                </View>
              )}
            </View>
          )}

          {/* Error section + fallback / ส่วนแสดง error + ลิงก์สำรอง */}
          {isError && (
            <View style={styles.errorSection}>
              <Ionicons name="alert-circle" size={18} color={colors.trading.red} />
              <View style={{ flex: 1 }}>
                <Text style={styles.errorText}>
                  {error || 'Download failed / ดาวน์โหลดล้มเหลว'}
                </Text>
                <Pressable style={styles.browserFallback} onPress={openDownloadPage}>
                  <Ionicons name="open-outline" size={14} color={colors.brand.cyan} />
                  <Text style={styles.browserFallbackText}>
                    Download from website / ดาวน์โหลดจากเว็บไซต์แทน
                  </Text>
                </Pressable>
              </View>
            </View>
          )}

          {/* File size info (idle state) / ข้อมูลขนาดไฟล์ */}
          {isIdle && updateInfo.fileSize > 0 && (
            <Text style={styles.fileSizeText}>
              APK size: {formatFileSize(updateInfo.fileSize)}
            </Text>
          )}

          {/* Mandatory badge / ป้ายบังคับอัปเดต */}
          {updateInfo.mandatory && isIdle && (
            <View style={styles.mandatoryBadge}>
              <Ionicons name="alert-circle" size={14} color={colors.trading.yellow} />
              <Text style={styles.mandatoryText}>
                Required Update / อัปเดตที่จำเป็น
              </Text>
            </View>
          )}

          {/* Buttons / ปุ่ม */}
          <View style={styles.buttonRow}>
            {/* Later / Dismiss button */}
            {canDismiss && !isCompleted && !isInstalling && (
              <Pressable style={styles.laterBtn} onPress={dismissModal}>
                <Text style={styles.laterText}>Later / ภายหลัง</Text>
              </Pressable>
            )}

            {/* Main action button (ซ่อนตอน downloading / installing เพราะมีปุ่ม cancel แทน) */}
            {!isDownloading && !isInstalling && (
              <Pressable
                style={[styles.downloadBtn, isError && { flex: 1 }]}
                onPress={isError ? handleRetry : handleMainAction}
              >
                <LinearGradient
                  colors={isCompleted ? colors.gradient.green : colors.gradient.brand}
                  start={{ x: 0, y: 0 }}
                  end={{ x: 1, y: 0 }}
                  style={styles.downloadGradient}
                >
                  <Ionicons
                    name={
                      isCompleted
                        ? 'install-outline' as any
                        : isError
                        ? 'refresh-outline'
                        : 'download-outline'
                    }
                    size={18}
                    color={colors.white}
                  />
                  <Text style={styles.downloadText}>
                    {isCompleted
                      ? 'Install Now / ติดตั้งเลย'
                      : isError
                      ? 'Retry / ลองใหม่'
                      : 'Update Now / อัปเดตเลย'}
                  </Text>
                </LinearGradient>
              </Pressable>
            )}

            {/* Downloading — show progress + cancel button */}
            {isDownloading && (
              <View style={styles.downloadingRow}>
                <ActivityIndicator size="small" color={colors.brand.cyan} />
                <Text style={styles.downloadingText}>
                  Downloading... / กำลังดาวน์โหลด...
                </Text>
                <Pressable style={styles.cancelBtn} onPress={cancelDownload}>
                  <Text style={styles.cancelText}>Cancel</Text>
                </Pressable>
              </View>
            )}

            {/* Installing — show status + cancel fallback */}
            {isInstalling && (
              <View style={styles.downloadingRow}>
                <ActivityIndicator size="small" color={colors.brand.cyan} />
                <Text style={styles.downloadingText}>
                  Installing... / กำลังติดตั้ง...
                </Text>
                <Pressable style={styles.cancelBtn} onPress={resetDownload}>
                  <Text style={styles.cancelText}>Cancel</Text>
                </Pressable>
              </View>
            )}
          </View>

          {/* GitHub link */}
          <Pressable style={styles.githubLink} onPress={openDownloadPage}>
            <Ionicons name="logo-github" size={14} color={colors.text.tertiary} />
            <Text style={styles.githubText}>View on GitHub</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: colors.bg.overlay,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing['2xl'],
  },
  card: {
    width: '100%',
    maxWidth: 360,
    backgroundColor: colors.bg.secondary,
    borderRadius: radius.xl,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    alignItems: 'center',
    overflow: 'hidden',
  },
  headerBar: {
    width: '100%',
    height: 3,
  },
  iconContainer: {
    marginTop: spacing['2xl'],
    marginBottom: spacing.lg,
  },
  iconGradient: {
    width: 56,
    height: 56,
    borderRadius: 28,
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: {
    ...typography.h3,
    color: colors.text.primary,
  },
  titleTh: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    marginTop: 2,
    marginBottom: spacing.xl,
  },
  versionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    marginBottom: spacing.lg,
    paddingHorizontal: spacing.xl,
  },
  versionBox: {
    flex: 1,
    alignItems: 'center',
    backgroundColor: colors.bg.input,
    borderRadius: radius.sm,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
  },
  versionLabel: {
    ...typography.caption,
    color: colors.text.tertiary,
    fontSize: 9,
    marginBottom: 2,
  },
  versionText: {
    ...typography.mono,
    color: colors.text.secondary,
    fontSize: 14,
  },
  versionNew: {
    color: colors.brand.cyan,
  },

  // Release notes (เหมือน wallet: "What's new:" section)
  releaseNotesSection: {
    width: '100%',
    paddingHorizontal: spacing.xl,
    marginBottom: spacing.lg,
  },
  releaseNotesLabel: {
    ...typography.bodySmall,
    color: colors.text.secondary,
    fontWeight: '700',
    fontSize: 12,
    marginBottom: spacing.xs,
  },
  releaseNotesScroll: {
    maxHeight: 80,
  },
  releaseNotesText: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontSize: 11,
    lineHeight: 16,
  },

  // Download method indicator (เหมือน wallet)
  downloadMethodBox: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.brand.cyan + '10',
    borderWidth: 1,
    borderColor: colors.brand.cyan + '20',
    borderRadius: radius.sm,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    marginHorizontal: spacing.xl,
    marginBottom: spacing.lg,
  },
  downloadMethodText: {
    ...typography.bodySmall,
    color: colors.text.secondary,
    fontSize: 11,
  },

  // Progress bar / แถบความคืบหน้า
  progressSection: {
    width: '100%',
    paddingHorizontal: spacing.xl,
    marginBottom: spacing.lg,
  },
  progressBarBg: {
    width: '100%',
    height: 8,
    backgroundColor: colors.bg.tertiary,
    borderRadius: 4,
    overflow: 'hidden',
  },
  progressBarFill: {
    height: '100%',
    borderRadius: 4,
    minWidth: 8,
  },
  progressInfo: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginTop: spacing.sm,
  },
  progressPercent: {
    ...typography.mono,
    color: colors.brand.cyan,
    fontSize: 16,
    fontWeight: '700',
  },
  progressSize: {
    ...typography.monoSmall,
    color: colors.text.tertiary,
  },
  installingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.md,
    justifyContent: 'center',
  },
  installingText: {
    ...typography.bodySmall,
    color: colors.brand.cyan,
  },

  // Error / ข้อผิดพลาด
  errorSection: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.trading.redBg,
    borderRadius: radius.sm,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    marginHorizontal: spacing.xl,
    marginBottom: spacing.lg,
  },
  errorText: {
    ...typography.bodySmall,
    color: colors.trading.red,
  },
  browserFallback: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    marginTop: spacing.sm,
  },
  browserFallbackText: {
    ...typography.bodySmall,
    color: colors.brand.cyan,
    fontWeight: '600',
    fontSize: 11,
  },

  // File size / ขนาดไฟล์
  fileSizeText: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    marginBottom: spacing.lg,
  },

  // Mandatory / บังคับ
  mandatoryBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    backgroundColor: 'rgba(255, 214, 0, 0.1)',
    borderRadius: radius.sm,
    paddingVertical: spacing.xs,
    paddingHorizontal: spacing.md,
    marginBottom: spacing.lg,
  },
  mandatoryText: {
    ...typography.bodySmall,
    color: colors.trading.yellow,
    fontWeight: '600',
    fontSize: 11,
  },

  // Buttons / ปุ่ม
  buttonRow: {
    flexDirection: 'row',
    gap: spacing.md,
    paddingHorizontal: spacing.xl,
    width: '100%',
    minHeight: 48,
    alignItems: 'center',
  },
  laterBtn: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
  },
  laterText: {
    ...typography.body,
    color: colors.text.tertiary,
    fontWeight: '600',
    fontSize: 13,
  },
  downloadBtn: {
    flex: 2,
    borderRadius: radius.md,
    overflow: 'hidden',
  },
  downloadGradient: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.md,
    borderRadius: radius.md,
  },
  downloadText: {
    ...typography.body,
    color: colors.white,
    fontWeight: '700',
    fontSize: 14,
  },
  downloadingRow: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.md,
  },
  downloadingText: {
    ...typography.bodySmall,
    color: colors.brand.cyan,
    fontWeight: '600',
    flex: 1,
  },
  cancelBtn: {
    paddingVertical: spacing.xs,
    paddingHorizontal: spacing.md,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.trading.red,
  },
  cancelText: {
    ...typography.bodySmall,
    color: colors.trading.red,
    fontWeight: '600',
    fontSize: 12,
  },

  // GitHub link
  githubLink: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    paddingVertical: spacing.lg,
    paddingBottom: spacing.xl,
  },
  githubText: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontSize: 11,
  },
});
