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
        versionCode = 12
        versionName = "0.6.0"
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

dependencies {
    // FileProvider: hands the camera app a file to save the goat photo into.
    implementation("androidx.core:core:1.13.1")
}
