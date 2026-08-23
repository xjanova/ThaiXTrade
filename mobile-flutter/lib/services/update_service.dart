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
  static const String _apiBase = 'https://tpix.online/api/v1/app';
  static const String _downloadPageUrl = 'https://tpix.online/download';

  final Dio _dio = Dio(BaseOptions(
    connectTimeout: const Duration(seconds: 10),
    receiveTimeout: const Duration(seconds: 10),
    headers: {'Accept': 'application/json'},
  ));

  Future<String> getCurrentVersion() async {
    final info = await PackageInfo.fromPlatform();
    return info.version;
  }

  Future<UpdateResult> checkForUpdate() async {
    final currentVersion = await getCurrentVersion();

    try {
      final response = await _dio.get(
        '$_apiBase/update-check',
        queryParameters: {'version': currentVersion},
      );

      if (response.statusCode != 200) {
        return UpdateResult(available: false, currentVersion: currentVersion);
      }

      final body = response.data as Map<String, dynamic>;

      if (body['success'] != true || body['data'] is! Map) {
        return UpdateResult(available: false, currentVersion: currentVersion);
      }

      final data = body['data'] as Map<String, dynamic>;

      return UpdateResult(
        available: data['available'] == true,
        currentVersion: currentVersion,
        latestVersion: data['latest_version'] as String?,
        releaseNotes: data['release_notes'] as String?,
        releaseDate: data['published_at'] as String?,
        apkDownloadUrl: data['download_url'] as String?,
        apkSize: data['file_size'] as int?,
        pendingApkBuild: data['pending_build'] == true,
      );
    } catch (e) {
      debugPrint('Update check failed: ${e.runtimeType}');
      return UpdateResult(available: false, currentVersion: currentVersion);
    }
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
      final launched =
          await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!launched) throw Exception('launchUrl returned false');
    } catch (e) {
      // ไม่มีทางสำรองไปหน้า GitHub แล้ว — repo เป็นไพรเวท ผู้ใช้เปิดไม่ได้อยู่ดี
      debugPrint('Open download page failed: ${e.runtimeType}');
    }
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
