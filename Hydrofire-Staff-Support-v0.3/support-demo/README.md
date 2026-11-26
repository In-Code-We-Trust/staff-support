# Staff Support presentation demo v0.3

Run setup and PHP from this folder in VS Code. Full instructions and demo accounts/workflow are in `../START-HERE.md`.

```powershell
& "C:\wamp64\bin\php\php8.3.14\php.exe" bin\setup.php
& "C:\wamp64\bin\php\php8.3.14\php.exe" -d upload_max_filesize=5M -d post_max_size=6M -S 127.0.0.1:8080 -t public
```

Open http://127.0.0.1:8080/index.html. Save the setup passwords. Optional launcher: `./start-demo.ps1`.
Setup is additive. `bin/setup.php --reset-passwords` creates new demo passwords without deleting requests.

Requires PHP 8.1+ with PDO SQLite and Fileinfo. All demo data lives in `var/` outside the public directory. No other database or API configuration is required. Real mail is disabled in the demo wrapper. The worker also remains available as `bin/worker.php`.

Tests: `php tests/presentation.php` runs both the original workflow assertions and the new presentation checks against isolated test databases. Run with the same full PHP executable path on Windows.
