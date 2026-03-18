import { useEffect, useState } from 'react';
import {
  View,
  Text,
  StyleSheet,
  FlatList,
  Pressable,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import type { ComponentProps } from 'react';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { colors, spacing, typography } from '@/theme';
import SearchBar from '@/components/common/SearchBar';
import MarketRow from '@/components/markets/MarketRow';
import { useMarketStore, MarketPair } from '@/stores/marketStore';

type IoniconsName = ComponentProps<typeof Ionicons>['name'];

// Extracted separator component (avoids re-creating on each render)
// คอมโพเนนต์ separator แยกออกมา (ไม่สร้างใหม่ทุกรอบ render)
function ListSeparator() {
  return <View style={separatorStyles.separator} />;
}
const separatorStyles = StyleSheet.create({
  separator: {
    height: 1,
    backgroundColor: 'rgba(255, 255, 255, 0.06)',
    marginHorizontal: spacing.xl,
  },
});

type SortBy = 'volume' | 'change' | 'price' | 'name';
type FilterTab = 'all' | 'favorites' | 'gainers' | 'losers';

export default function MarketsScreen() {
  const insets = useSafeAreaInsets();
  const { pairs, favorites, searchQuery, setSearchQuery, loadMockData } = useMarketStore();
  const [activeFilter, setActiveFilter] = useState<FilterTab>('all');
  const [sortBy, setSortBy] = useState<SortBy>('volume');

  useEffect(() => {
    loadMockData();
  }, []);

  const filteredPairs = pairs
    .filter((pair) => {
      // Search filter
      if (searchQuery) {
        const q = searchQuery.toLowerCase();
        return (
          pair.symbol.toLowerCase().includes(q) ||
          pair.name.toLowerCase().includes(q)
        );
      }
      return true;
    })
    .filter((pair) => {
      switch (activeFilter) {
        case 'favorites':
          return favorites.includes(pair.symbol);
        case 'gainers':
          return pair.change24h > 0;
        case 'losers':
          return pair.change24h < 0;
        default:
          return true;
      }
    })
    .sort((a, b) => {
      switch (sortBy) {
        case 'change':
          return Math.abs(b.change24h) - Math.abs(a.change24h);
        case 'price':
          return b.price - a.price;
        case 'name':
          return a.symbol.localeCompare(b.symbol);
        default:
          return 0; // volume - already in order
      }
    });

  const filterTabs: { key: FilterTab; label: string; icon?: IoniconsName }[] = [
    { key: 'all', label: 'All' },
    { key: 'favorites', label: 'Favorites', icon: 'star' },
    { key: 'gainers', label: 'Gainers', icon: 'trending-up' },
    { key: 'losers', label: 'Losers', icon: 'trending-down' },
  ];

  const renderItem = ({ item }: { item: MarketPair }) => (
    <MarketRow
      symbol={item.symbol}
      name={item.name}
      price={item.price}
      change24h={item.change24h}
      volume={item.volume24h}
      chartData={item.chartData}
      onPress={() => router.push('/trade')}
    />
  );

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      {/* Header */}
      <View style={styles.header}>
        <Text style={styles.title}>Markets</Text>
        <View style={styles.headerRight}>
          <Pressable style={styles.sortBtn} onPress={() => {
            const sorts: SortBy[] = ['volume', 'change', 'price', 'name'];
            const idx = sorts.indexOf(sortBy);
            setSortBy(sorts[(idx + 1) % sorts.length]);
          }}>
            <Ionicons name="funnel-outline" size={18} color={colors.text.secondary} />
            <Text style={styles.sortLabel}>{sortBy}</Text>
          </Pressable>
        </View>
      </View>

      {/* Search */}
      <View style={styles.searchContainer}>
        <SearchBar
          value={searchQuery}
          onChangeText={setSearchQuery}
          placeholder="Search coins..."
        />
      </View>

      {/* Filter Tabs */}
      <View style={styles.filterTabs}>
        {filterTabs.map((tab) => (
          <Pressable
            key={tab.key}
            style={[
              styles.filterTab,
              activeFilter === tab.key && styles.filterTabActive,
            ]}
            onPress={() => setActiveFilter(tab.key)}
          >
            {tab.icon && (
              <Ionicons
                name={tab.icon}
                size={14}
                color={
                  activeFilter === tab.key
                    ? colors.brand.cyan
                    : colors.text.tertiary
                }
              />
            )}
            <Text
              style={[
                styles.filterTabText,
                activeFilter === tab.key && styles.filterTabTextActive,
              ]}
            >
              {tab.label}
            </Text>
          </Pressable>
        ))}
      </View>

      {/* Column Headers */}
      <View style={styles.columnHeaders}>
        <Text style={[styles.colHeader, { flex: 1 }]}>Pair</Text>
        <Text style={[styles.colHeader, { width: 60, textAlign: 'center' }]}>Chart</Text>
        <Text style={[styles.colHeader, { width: 100, textAlign: 'right' }]}>Price / 24h</Text>
      </View>

      {/* Market List */}
      <FlatList
        data={filteredPairs}
        renderItem={renderItem}
        keyExtractor={(item) => item.symbol}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.listContent}
        ItemSeparatorComponent={ListSeparator}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Ionicons name="search-outline" size={48} color={colors.text.tertiary} />
            <Text style={styles.emptyText}>No markets found</Text>
          </View>
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.bg.primary,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.lg,
  },
  title: {
    ...typography.h2,
    color: colors.text.primary,
  },
  headerRight: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  sortBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    backgroundColor: colors.bg.card,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
    borderRadius: 8,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  sortLabel: {
    ...typography.bodySmall,
    color: colors.text.secondary,
    textTransform: 'capitalize',
    fontSize: 11,
  },
  searchContainer: {
    paddingHorizontal: spacing.xl,
    marginBottom: spacing.lg,
  },
  filterTabs: {
    flexDirection: 'row',
    paddingHorizontal: spacing.xl,
    gap: spacing.sm,
    marginBottom: spacing.lg,
  },
  filterTab: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: 20,
    backgroundColor: colors.bg.card,
    borderWidth: 1,
    borderColor: colors.bg.cardBorder,
  },
  filterTabActive: {
    backgroundColor: colors.brand.cyan + '15',
    borderColor: colors.brand.cyan + '40',
  },
  filterTabText: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    fontSize: 12,
  },
  filterTabTextActive: {
    color: colors.brand.cyan,
  },
  columnHeaders: {
    flexDirection: 'row',
    paddingHorizontal: spacing.xl,
    paddingBottom: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.divider,
  },
  colHeader: {
    ...typography.caption,
    color: colors.text.tertiary,
    fontSize: 10,
  },
  listContent: {
    paddingBottom: 100,
  },
  // Separator styles moved to ListSeparator component / ย้ายไปที่คอมโพเนนต์ ListSeparator
  empty: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing['5xl'],
    gap: spacing.md,
  },
  emptyText: {
    ...typography.body,
    color: colors.text.tertiary,
  },
});
