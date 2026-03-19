import { View, Text, StyleSheet, ScrollView, Pressable, Switch, ActivityIndicator } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useState } from 'react';
import { colors, spacing, radius, typography } from '@/theme';
import GlassCard from '@/components/common/GlassCard';
import { useUpdateStore } from '@/stores/updateStore';
import { CURRENT_VERSION } from '@/services/updateService';

type IoniconsName = ComponentProps<typeof Ionicons>['name'];

interface SettingItemProps {
  icon: IoniconsName;
  iconColor?: string;
  title: string;
  subtitle?: string;
  rightElement?: React.ReactNode;
  onPress?: () => void;
  showArrow?: boolean;
}

function SettingItem({
  icon,
  iconColor = colors.brand.cyan,
  title,
  subtitle,
  rightElement,
  onPress,
  showArrow = true,
}: SettingItemProps) {
  return (
    <Pressable style={styles.settingItem} onPress={onPress}>
      <View style={[styles.settingIcon, { backgroundColor: iconColor + '20' }]}>
        <Ionicons name={icon} size={18} color={iconColor} />
      </View>
      <View style={styles.settingContent}>
        <Text style={styles.settingTitle}>{title}</Text>
        {subtitle && <Text style={styles.settingSubtitle}>{subtitle}</Text>}
      </View>
      {rightElement || (
        showArrow && (
          <Ionicons name="chevron-forward" size={18} color={colors.text.tertiary} />
        )
      )}
    </Pressable>
  );
}

export default function SettingsScreen() {
  const insets = useSafeAreaInsets();
  const [biometric, setBiometric] = useState(true);
  const [notifications, setNotifications] = useState(true);
  const [priceAlerts, setPriceAlerts] = useState(false);

  // Update state / สถานะอัปเดต
  const { updateInfo, isChecking, forceCheck, openModal } = useUpdateStore();
  const hasUpdate = updateInfo?.available ?? false;

  const handleCheckUpdate = async () => {
    // Force re-check (ignore throttle) / บังคับตรวจใหม่ (ไม่สน throttle)
    await forceCheck();
  };

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      <View style={styles.header}>
        <Text style={styles.title}>Settings</Text>
      </View>

      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.scrollContent}
      >
        {/* Update Banner - show when update available */}
        {/* แบนเนอร์อัปเดต - แสดงเมื่อมีเวอร์ชันใหม่ */}
        {hasUpdate && (
          <Pressable onPress={openModal}>
            <LinearGradient
              colors={['rgba(6, 182, 212, 0.15)', 'rgba(139, 92, 246, 0.15)']}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={styles.updateBanner}
            >
              <View style={styles.updateDot} />
              <View style={{ flex: 1 }}>
                <Text style={styles.updateBannerTitle}>
                  New version available / มีเวอร์ชันใหม่
                </Text>
                <Text style={styles.updateBannerVersion}>
                  v{updateInfo?.latestVersion} → Tap to update / แตะเพื่ออัปเดต
                </Text>
              </View>
              <Ionicons name="chevron-forward" size={18} color={colors.brand.cyan} />
            </LinearGradient>
          </Pressable>
        )}

        {/* Profile Card / การ์ดโปรไฟล์ */}
        <GlassCard variant="brand" style={styles.profileCard}>
          <LinearGradient
            colors={colors.gradient.brand}
            start={{ x: 0, y: 0 }}
            end={{ x: 1, y: 1 }}
            style={styles.avatar}
          >
            <Ionicons name="person" size={28} color={colors.white} />
          </LinearGradient>
          <View style={{ flex: 1 }}>
            <Text style={styles.profileName}>Connected Wallet</Text>
            <Text style={styles.profileAddress}>0x1234...5678</Text>
          </View>
          <View style={styles.networkBadge}>
            <View style={styles.networkDot} />
            <Text style={styles.networkText}>BSC</Text>
          </View>
        </GlassCard>

        {/* Account / บัญชี */}
        <Text style={styles.sectionLabel}>ACCOUNT</Text>
        <GlassCard style={styles.settingGroup}>
          <SettingItem
            icon="wallet-outline"
            title="Wallet Management"
            subtitle="Connected wallets & networks"
          />
          <View style={styles.settingDivider} />
          <SettingItem
            icon="key-outline"
            iconColor={colors.brand.purple}
            title="Security"
            subtitle="Password, 2FA, backup"
          />
          <View style={styles.settingDivider} />
          <SettingItem
            icon="finger-print-outline"
            iconColor={colors.trading.green}
            title="Biometric Login"
            showArrow={false}
            rightElement={
              <Switch
                value={biometric}
                onValueChange={setBiometric}
                trackColor={{ false: colors.bg.tertiary, true: colors.brand.cyan + '60' }}
                thumbColor={biometric ? colors.brand.cyan : colors.text.tertiary}
              />
            }
          />
        </GlassCard>

        {/* Trading / การเทรด */}
        <Text style={styles.sectionLabel}>TRADING</Text>
        <GlassCard style={styles.settingGroup}>
          <SettingItem
            icon="options-outline"
            title="Trading Preferences"
            subtitle="Default pair, order type, slippage"
          />
          <View style={styles.settingDivider} />
          <SettingItem
            icon="shield-checkmark-outline"
            iconColor={colors.trading.green}
            title="Transaction Settings"
            subtitle="Gas, speed, approval limits"
          />
          <View style={styles.settingDivider} />
          <SettingItem
            icon="analytics-outline"
            iconColor={colors.brand.purple}
            title="Chart Settings"
            subtitle="Indicators, timeframes, style"
          />
        </GlassCard>

        {/* Notifications / การแจ้งเตือน */}
        <Text style={styles.sectionLabel}>NOTIFICATIONS</Text>
        <GlassCard style={styles.settingGroup}>
          <SettingItem
            icon="notifications-outline"
            title="Push Notifications"
            showArrow={false}
            rightElement={
              <Switch
                value={notifications}
                onValueChange={setNotifications}
                trackColor={{ false: colors.bg.tertiary, true: colors.brand.cyan + '60' }}
                thumbColor={notifications ? colors.brand.cyan : colors.text.tertiary}
              />
            }
          />
          <View style={styles.settingDivider} />
          <SettingItem
            icon="pulse-outline"
            iconColor={colors.trading.yellow}
            title="Price Alerts"
            showArrow={false}
            rightElement={
              <Switch
                value={priceAlerts}
                onValueChange={setPriceAlerts}
                trackColor={{ false: colors.bg.tertiary, true: colors.brand.cyan + '60' }}
                thumbColor={priceAlerts ? colors.brand.cyan : colors.text.tertiary}
              />
            }
          />
        </GlassCard>

        {/* General / ทั่วไป */}
        <Text style={styles.sectionLabel}>GENERAL</Text>
        <GlassCard style={styles.settingGroup}>
          <SettingItem
            icon="language-outline"
            title="Language"
            subtitle="English"
          />
          <View style={styles.settingDivider} />
          <SettingItem
            icon="cash-outline"
            iconColor={colors.trading.green}
            title="Currency"
            subtitle="USD"
          />
          <View style={styles.settingDivider} />
          <SettingItem
            icon="moon-outline"
            iconColor={colors.brand.purple}
            title="Appearance"
            subtitle="Dark (Default)"
          />
          <View style={styles.settingDivider} />
          <SettingItem
            icon="document-text-outline"
            title="Terms & Privacy"
          />
          <View style={styles.settingDivider} />
          <SettingItem
            icon="help-circle-outline"
            title="Help & Support"
          />
        </GlassCard>

        {/* App Info with Update Check / ข้อมูลแอปพร้อมตรวจสอบอัปเดต */}
        <View style={styles.appInfo}>
          <Text style={styles.appInfoName}>TPIX TRADE</Text>
          <Text style={styles.appInfoVersion}>Version {CURRENT_VERSION}</Text>
          <Text style={styles.appInfoDev}>by Xman Studio</Text>

          {/* Check for Updates Button / ปุ่มตรวจสอบอัปเดต */}
          <Pressable
            style={styles.checkUpdateBtn}
            onPress={handleCheckUpdate}
            disabled={isChecking}
          >
            {isChecking ? (
              <ActivityIndicator size="small" color={colors.brand.cyan} />
            ) : (
              <>
                <Ionicons
                  name={hasUpdate ? 'arrow-up-circle' : 'refresh-outline'}
                  size={16}
                  color={hasUpdate ? colors.trading.green : colors.brand.cyan}
                />
                <Text style={[
                  styles.checkUpdateText,
                  hasUpdate && { color: colors.trading.green },
                ]}>
                  {hasUpdate
                    ? `Update to v${updateInfo?.latestVersion} / อัปเดตเป็น v${updateInfo?.latestVersion}`
                    : 'Check for Updates / ตรวจสอบอัปเดต'}
                </Text>
              </>
            )}
          </Pressable>
        </View>

        <View style={{ height: 120 }} />
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.bg.primary,
  },
  header: {
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.lg,
  },
  title: {
    ...typography.h2,
    color: colors.text.primary,
  },
  scrollContent: {
    paddingHorizontal: spacing.xl,
  },
  // Update Banner / แบนเนอร์อัปเดต
  updateBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.lg,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.brand.cyan + '30',
    marginBottom: spacing.xl,
  },
  updateDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: colors.brand.cyan,
  },
  updateBannerTitle: {
    ...typography.bodySmall,
    color: colors.text.primary,
    fontWeight: '600',
  },
  updateBannerVersion: {
    ...typography.bodySmall,
    color: colors.brand.cyan,
    fontSize: 11,
    marginTop: 1,
  },
  // Profile / โปรไฟล์
  profileCard: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: spacing.lg,
    marginBottom: spacing['2xl'],
    gap: spacing.md,
  },
  avatar: {
    width: 52,
    height: 52,
    borderRadius: 26,
    alignItems: 'center',
    justifyContent: 'center',
  },
  profileName: {
    ...typography.body,
    color: colors.text.primary,
    fontWeight: '600',
  },
  profileAddress: {
    ...typography.monoSmall,
    color: colors.text.tertiary,
    marginTop: 2,
  },
  networkBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    backgroundColor: colors.trading.greenBg,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
    borderRadius: 12,
  },
  networkDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: colors.trading.green,
  },
  networkText: {
    ...typography.bodySmall,
    color: colors.trading.green,
    fontSize: 11,
    fontWeight: '600',
  },
  // Section / ส่วน
  sectionLabel: {
    ...typography.caption,
    color: colors.text.tertiary,
    marginBottom: spacing.sm,
    marginLeft: spacing.xs,
  },
  // Setting Group / กลุ่มการตั้งค่า
  settingGroup: {
    padding: spacing.xs,
    marginBottom: spacing.xl,
  },
  settingItem: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: spacing.md,
    gap: spacing.md,
  },
  settingIcon: {
    width: 36,
    height: 36,
    borderRadius: 10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  settingContent: {
    flex: 1,
  },
  settingTitle: {
    ...typography.body,
    color: colors.text.primary,
    fontWeight: '500',
    fontSize: 14,
  },
  settingSubtitle: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontSize: 11,
    marginTop: 1,
  },
  settingDivider: {
    height: 1,
    backgroundColor: colors.divider,
    marginLeft: 60,
  },
  // App Info / ข้อมูลแอป
  appInfo: {
    alignItems: 'center',
    paddingVertical: spacing['3xl'],
    gap: 4,
  },
  appInfoName: {
    ...typography.h4,
    color: colors.brand.cyan,
    letterSpacing: 2,
  },
  appInfoVersion: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
  },
  appInfoDev: {
    ...typography.bodySmall,
    color: colors.text.disabled,
    fontSize: 11,
  },
  checkUpdateBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.lg,
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    backgroundColor: colors.bg.card,
  },
  checkUpdateText: {
    ...typography.bodySmall,
    color: colors.brand.cyan,
    fontWeight: '600',
  },
});
