import java.util.Properties
import java.io.FileInputStream

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Load keystore.properties (ถ้ามี) — ใช้สำหรับ release signing
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

android {
    namespace = "com.xmanstudio.tpix_trade"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        applicationId = "com.xmanstudio.tpix_trade"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        create("release") {
            // ลำดับการหา keystore:
            // 1. key.properties (dev local)
            // 2. ENV vars (CI: KEYSTORE_PATH, KEYSTORE_PASSWORD, KEY_ALIAS, KEY_PASSWORD)
            // 3. fallback ไฟล์ tpix-trade.keystore ใน app/ (committed — ใช้ key เดียวกันทุกครั้ง)
            val envKeystorePath = System.getenv("KEYSTORE_PATH")
            if (keystoreProperties.containsKey("storeFile")) {
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
                storeFile = file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
            } else if (envKeystorePath != null && file(envKeystorePath).exists()) {
                keyAlias = System.getenv("KEY_ALIAS") ?: "tpix_trade"
                keyPassword = System.getenv("KEY_PASSWORD") ?: "tpix2026"
                storeFile = file(envKeystorePath)
                storePassword = System.getenv("KEYSTORE_PASSWORD") ?: "tpix2026"
            } else {
                // Fallback: persistent keystore ใน repo (ใช้ key เดียวกันทุก build)
                keyAlias = "tpix_trade"
                keyPassword = "tpix2026"
                storeFile = file("tpix-trade.keystore")
                storePassword = "tpix2026"
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.getByName("release")
            isMinifyEnabled = false
            isShrinkResources = false
        }
    }
}

flutter {
    source = "../.."
}
