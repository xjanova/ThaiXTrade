package com.xmanstudio.tpix_trade

import io.flutter.embedding.android.FlutterFragmentActivity

// local_auth needs a FragmentActivity to show BiometricPrompt; with a plain
// FlutterActivity every authenticate() call fails with no_fragment_activity.
class MainActivity : FlutterFragmentActivity()
