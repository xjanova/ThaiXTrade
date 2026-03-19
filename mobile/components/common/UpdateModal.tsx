/**
 * Update Modal Component
 * คอมโพเนนต์ Modal แจ้งเตือนอัปเดต
 *
 * Shows when a new version is available with download/dismiss options.
 * Mandatory updates cannot be dismissed.
 * แสดงเมื่อมีเวอร์ชันใหม่ พร้อมปุ่มดาวน์โหลด/ปิด
 * อัปเดตบังคับไม่สามารถปิดได้
 */

import React from 'react';
import {
  StyleSheet,
  Text,
  View,
  Modal,
  Pressable,
  Platform,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { colors, spacing, radius, typography } from '@/theme';
import { useUpdateStore } from '@/stores/updateStore';
import { downloadUpdate, openReleasesPage } from '@/services/updateService';

export default function UpdateModal() {
  const { updateInfo, showModal, dismissModal } = useUpdateStore();

  if (!showModal || !updateInfo?.available) return null;

  const handleDownload = async () => {
    if (updateInfo.downloadUrl) {
      await downloadUpdate(updateInfo.downloadUrl);
    } else {
      await openReleasesPage();
    }
  };

  const handleViewRelease = async () => {
    await openReleasesPage();
  };

  const canDismiss = !updateInfo.mandatory;

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
          {/* Header gradient bar / แถบ gradient ด้านบน */}
          <LinearGradient
            colors={colors.gradient.brand}
            start={{ x: 0, y: 0 }}
            end={{ x: 1, y: 0 }}
            style={styles.headerBar}
          />

          {/* Icon / ไอคอน */}
          <View style={styles.iconContainer}>
            <LinearGradient
              colors={colors.gradient.brand}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={styles.iconGradient}
            >
              <Ionicons name="rocket-outline" size={28} color={colors.white} />
            </LinearGradient>
          </View>

          {/* Title / หัวข้อ */}
          <Text style={styles.title}>Update Available</Text>
          <Text style={styles.titleTh}>มีเวอร์ชันใหม่</Text>

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

          {/* Release name / ชื่อ release */}
          {updateInfo.releaseName ? (
            <Text style={styles.releaseName} numberOfLines={2}>
              {updateInfo.releaseName}
            </Text>
          ) : null}

          {/* Mandatory badge / ป้ายบังคับอัปเดต */}
          {updateInfo.mandatory && (
            <View style={styles.mandatoryBadge}>
              <Ionicons name="alert-circle" size={14} color={colors.trading.yellow} />
              <Text style={styles.mandatoryText}>
                Required Update / อัปเดตที่จำเป็น
              </Text>
            </View>
          )}

          {/* Buttons / ปุ่ม */}
          <View style={styles.buttonRow}>
            {canDismiss && (
              <Pressable style={styles.laterBtn} onPress={dismissModal}>
                <Text style={styles.laterText}>Later / ภายหลัง</Text>
              </Pressable>
            )}
            <Pressable style={styles.downloadBtn} onPress={handleDownload}>
              <LinearGradient
                colors={colors.gradient.brand}
                start={{ x: 0, y: 0 }}
                end={{ x: 1, y: 0 }}
                style={styles.downloadGradient}
              >
                <Ionicons name="download-outline" size={18} color={colors.white} />
                <Text style={styles.downloadText}>
                  {Platform.OS === 'android' ? 'Download APK' : 'View Release'}
                </Text>
              </LinearGradient>
            </Pressable>
          </View>

          {/* View on GitHub link / ลิงก์ดูบน GitHub */}
          <Pressable style={styles.githubLink} onPress={handleViewRelease}>
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
  releaseName: {
    ...typography.bodySmall,
    color: colors.text.secondary,
    textAlign: 'center',
    paddingHorizontal: spacing.xl,
    marginBottom: spacing.lg,
  },
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
  buttonRow: {
    flexDirection: 'row',
    gap: spacing.md,
    paddingHorizontal: spacing.xl,
    width: '100%',
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
  },
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
