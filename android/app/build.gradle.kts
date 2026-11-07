plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.kkfarm.scanner"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.kkfarm.scanner"
        minSdk = 26
        targetSdk = 34
        versionCode = 2
        versionName = "0.1.1"
    }

    buildTypes {
        // Debug-signed for the hardware spike. A release keystore is set up before distribution.
        debug { isMinifyEnabled = false }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}
