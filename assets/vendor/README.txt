Libraries used as they are (not changed), taken from the npm registry and checked against the
registry's sha512 checksum of each package on 2026-09-30.

qrcode.js
    qrcode-generator 2.0.4 by Kazuhiko Arase, MIT license (the notice is at the top of the file).
    Package sha512-mZSiP6RnbHl4xL2Ap5HfkjLnmxfKcPWpWe/c+5XxCuetEenqmNFf1FH/ftXPCtFG5/TDobjsjz6sSNL0Sr8Z9g==
    File dist/qrcode.js, sha256 79ec86f82856005b1c887905cfccfcfbec3821ca61c7fd5a952faa5f778f791c
    Draws the QR code on a guest's ticket (customer/ticket.php).

jsQR.js
    jsQR 1.4.0 (https://github.com/cozmo/jsQR), Apache License 2.0 (jsQR.LICENSE.txt).
    Package sha512-dxLob7q65Xg2DvstYkRpkYtmKm2sPJ9oFhrhmudT1dZvNFFTlroai3AWSpLey/w5vMcLBXRgOJsbXpdN9HzU/A==
    File dist/jsQR.js, sha256 bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859
    (a three-line comment naming the source and license was added above it)
    Reads QR codes from the camera or a photo on the admin's scanner (admin/scan.php), where the
    browser has no built-in BarcodeDetector.
