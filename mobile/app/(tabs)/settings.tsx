import { View, Text, StyleSheet, ScrollView, Pressable, Switch } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useState } from 'react';
import { colors, spacing, typography } from '@/theme';
import GlassCard from '@/components/common/GlassCard';

interface SettingItemProps {
  icon: string;
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
        <Ionicons name={icon as any} size={18} color={iconColor} />
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

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      <View style={styles.header}>
        <Text style={styles.title}>Settings</Text>
      </View>

      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.scrollContent}
      >
        {/* Profile Card */}
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

        {/* Account */}
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

        {/* Trading */}
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

        {/* Notifications */}
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

        {/* General */}
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

        {/* App Info */}
        <View style={styles.appInfo}>
          <Text style={styles.appInfoName}>TPIX TRADE</Text>
          <Text style={styles.appInfoVersion}>Version 1.0.0</Text>
          <Text style={styles.appInfoDev}>by Xman Studio</Text>
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
  // Profile
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
  // Section
  sectionLabel: {
    ...typography.caption,
    color: colors.text.tertiary,
    marginBottom: spacing.sm,
    marginLeft: spacing.xs,
  },
  // Setting Group
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
  // App Info
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
});
