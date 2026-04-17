/// TPIX TRADE — Auto-Update Service
/// ดาวน์โหลด APK จาก GitHub Releases แล้วติดตั้งในแอป
/// Adapted from TPIX Wallet — เปลี่ยน repo target เป็น ThaiXTrade
///
/// Developed by Xman Studio

import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:open_filex/open_filex.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

class UpdateService {
  static const String _owner = 'xjanova';
  static const String _repo = 'ThaiXTrade';
  static const String _apiUrl =
      'https://api.github.com/repos/$_owner/$_repo/releases/latest';
  static const String _downloadPageUrl = 'https://tpix.online/download';

  final Dio _dio = Dio(BaseOptions(
    connectTimeout: const Duration(seconds: 10),
    receiveTimeout: const Duration(seconds: 10),
    headers: {'Accept': 'application/vnd.github.v3+json'},
  ));

  Future<String> getCurrentVersion() async {
    final info = await PackageInfo.fromPlatform();
    return info.version;
  }

  Future<ReleaseInfo?> getLatestRelease() async {
    try {
      final response = await _dio.get(_apiUrl);
      if (response.statusCode == 200) {
        return ReleaseInfo.fromJson(response.data);
      }
    } catch (e) {
      debugPrint('Update check failed: ${e.runtimeType}');
    }
    return null;
  }

  Future<UpdateResult> checkForUpdate() async {
    try {
      final currentVersion = await getCurrentVersion();

      // Scan up to 5 most recent releases — the LATEST tag might be a web-only
      // release.yml trigger without an APK attached yet. Find the newest release
      // (by semver) that still has an APK asset.
      final releases = await _getRecentReleases();
      if (releases.isEmpty) {
        return UpdateResult(available: false, currentVersion: currentVersion);
      }

      // Pick: newest release that has an APK and is > current
      ReleaseInfo? best;
      ReleaseInfo? latestAny;
      for (final r in releases) {
        latestAny ??= r;
        if (r.apkDownloadUrl == null) continue;
        if (!_isNewerVersion(currentVersion, r.version)) continue;
        if (best == null || _isNewerVersion(best.version, r.version)) {
          best = r;
        }
      }

      // Fallback: no installable APK found in recent releases
      if (best == null) {
        // If the newest-any tag IS newer than current but has no APK, report
        // "pending build" so the UI can tell user "new version is being built"
        // instead of wrongly claiming up-to-date or silently pushing them to web.
        final newerButNoApk = latestAny != null &&
            _isNewerVersion(currentVersion, latestAny.version);
        return UpdateResult(
          available: false,
          currentVersion: currentVersion,
          latestVersion: latestAny?.version,
          pendingApkBuild: newerButNoApk,
        );
      }

      return UpdateResult(
        available: true,
        currentVersion: currentVersion,
        latestVersion: best.version,
        releaseNotes: best.body,
        releaseDate: best.publishedAt,
        apkDownloadUrl: best.apkDownloadUrl,
        apkSize: best.apkSize,
      );
    } catch (e) {
      debugPrint('Update check error: ${e.runtimeType}');
      return UpdateResult(available: false, currentVersion: 'unknown');
    }
  }

  /// Fetch up to 5 most recent releases — used to skip releases that are
  /// missing an APK asset (e.g. web-only backend releases).
  Future<List<ReleaseInfo>> _getRecentReleases() async {
    try {
      final response = await _dio.get(
        'https://api.github.com/repos/$_owner/$_repo/releases',
        queryParameters: {'per_page': 5},
      );
      if (response.statusCode != 200) return [];
      final list = response.data as List<dynamic>;
      return list
          .map((e) => ReleaseInfo.fromJson(e as Map<String, dynamic>))
          .toList();
    } catch (e) {
      debugPrint('Recent releases fetch failed: ${e.runtimeType}');
      return [];
    }
  }

  bool _isNewerVersion(String current, String remote) {
    final currentParts = current.replaceAll('v', '').split('.');
    final remoteParts = remote.replaceAll('v', '').split('.');

    for (int i = 0; i < 3; i++) {
      final c = i < currentParts.length ? int.tryParse(currentParts[i]) ?? 0 : 0;
      final r = i < remoteParts.length ? int.tryParse(remoteParts[i]) ?? 0 : 0;
      if (r > c) return true;
      if (r < c) return false;
    }
    return false;
  }

  Future<bool> downloadAndInstall(
    String downloadUrl,
    String version, {
    int? expectedSize,
    void Function(int received, int total)? onProgress,
    CancelToken? cancelToken,
  }) async {
    try {
      final dir = await getTemporaryDirectory();
      final filePath = '${dir.path}/TPIX-Trade-v$version.apk';

      final oldFile = File(filePath);
      if (oldFile.existsSync()) oldFile.deleteSync();

      await Dio().download(
        downloadUrl,
        filePath,
        onReceiveProgress: onProgress,
        cancelToken: cancelToken,
        options: Options(
          receiveTimeout: const Duration(minutes: 5),
          headers: {'Accept': 'application/octet-stream'},
        ),
      );

      final file = File(filePath);
      if (!file.existsSync() || file.lengthSync() < 1024) return false;

      if (expectedSize != null && file.lengthSync() != expectedSize) {
        debugPrint(
            'APK size mismatch: expected $expectedSize, got ${file.lengthSync()}');
        file.deleteSync();
        return false;
      }

      final result = await OpenFilex.open(filePath);
      return result.type == ResultType.done;
    } catch (e) {
      if (e is DioException && e.type == DioExceptionType.cancel) rethrow;
      debugPrint('Download/install failed: ${e.runtimeType}');
      return false;
    }
  }

  Future<void> openDownloadPage() async {
    final uri = Uri.parse(_downloadPageUrl);
    try {
      final launched = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!launched) throw Exception('launchUrl returned false');
    } catch (_) {
      final fallback =
          Uri.parse('https://github.com/$_owner/$_repo/releases/latest');
      await launchUrl(fallback, mode: LaunchMode.externalApplication);
    }
  }
}

class ReleaseInfo {
  final String version;
  final String? body;
  final String? publishedAt;
  final String? apkDownloadUrl;
  final int? apkSize;

  ReleaseInfo({
    required this.version,
    this.body,
    this.publishedAt,
    this.apkDownloadUrl,
    this.apkSize,
  });

  factory ReleaseInfo.fromJson(Map<String, dynamic> json) {
    String? apkUrl;
    int? apkSize;
    final assets = json['assets'] as List<dynamic>? ?? [];
    for (final asset in assets) {
      final name = (asset['name'] as String? ?? '').toLowerCase();
      if (name.endsWith('.apk')) {
        apkUrl = asset['browser_download_url'] as String?;
        apkSize = asset['size'] as int?;
        break;
      }
    }

    return ReleaseInfo(
      version: (json['tag_name'] as String? ?? '').replaceAll('v', ''),
      body: json['body'] as String?,
      publishedAt: json['published_at'] as String?,
      apkDownloadUrl: apkUrl,
      apkSize: apkSize,
    );
  }
}

class UpdateResult {
  final bool available;
  final String currentVersion;
  final String? latestVersion;
  final String? releaseNotes;
  final String? releaseDate;
  final String? apkDownloadUrl;
  final int? apkSize;

  /// true ถ้า tag ใหม่มีอยู่บน GitHub แล้วแต่ APK ยังไม่ถูก build/attach
  /// (เช่น release.yml เพิ่งสร้าง tag แต่ build-flutter-apk.yml ยังไม่รัน).
  /// UI ควรแสดงว่า "มีเวอร์ชั่นใหม่กำลัง build — ลองใหม่อีกครั้งภายหลัง"
  /// แทนที่จะเด้งไป download page ทันที
  final bool pendingApkBuild;

  UpdateResult({
    required this.available,
    required this.currentVersion,
    this.latestVersion,
    this.releaseNotes,
    this.releaseDate,
    this.apkDownloadUrl,
    this.apkSize,
    this.pendingApkBuild = false,
  });
}
