import React from 'react';
import {
  StyleSheet,
  TextInput,
  View,
  Pressable,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors, spacing, radius } from '@/theme';

interface SearchBarProps {
  value: string;
  onChangeText: (text: string) => void;
  placeholder?: string;
}

export function SearchBar({
  value,
  onChangeText,
  placeholder = 'Search...',
}: SearchBarProps) {
  const hasValue = value.length > 0;

  return (
    <View style={styles.container}>
      <Ionicons
        name="search-outline"
        size={18}
        color={colors.text.tertiary}
        style={styles.searchIcon}
      />

      <TextInput
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={colors.text.disabled}
        style={styles.input}
        selectionColor={colors.brand.cyan}
        autoCapitalize="none"
        autoCorrect={false}
        returnKeyType="search"
      />

      {hasValue && (
        <Pressable
          onPress={() => onChangeText('')}
          style={styles.clearButton}
          hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
        >
          <View style={styles.clearIcon}>
            <Ionicons
              name="close"
              size={14}
              color={colors.text.secondary}
            />
          </View>
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg.input,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    height: 44,
    paddingHorizontal: spacing.md,
  },
  searchIcon: {
    marginRight: spacing.sm,
  },
  input: {
    flex: 1,
    fontSize: 15,
    color: colors.text.primary,
    height: '100%',
    padding: 0,
  },
  clearButton: {
    marginLeft: spacing.sm,
  },
  clearIcon: {
    width: 22,
    height: 22,
    borderRadius: 11,
    backgroundColor: colors.bg.tertiary,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
