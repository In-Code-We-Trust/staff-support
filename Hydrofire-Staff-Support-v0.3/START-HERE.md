# Hydrofire Staff Support v0.3 — presentation version

Extract this package into a **new folder**. Open **support-demo** in VS Code.
Stop any earlier demo server with Ctrl+C before using the same port.

In a PowerShell terminal, from support-demo:

```powershell
& "C:\wamp64\bin\php\php8.3.14\php.exe" bin\setup.php
```

Save the four passwords printed by setup. Then:

```powershell
& "C:\wamp64\bin\php\php8.3.14\php.exe" -d upload_max_filesize=5M -d post_max_size=6M -S 127.0.0.1:8080 -t public
```

Open http://127.0.0.1:8080/index.html (no full stop after html). Leave the terminal running.
An optional `start-demo.ps1` runs both commands from the correct directory.

Forgot the passwords? In a second terminal in support-demo:

```powershell
& "C:\wamp64\bin\php\php8.3.14\php.exe" bin\setup.php --reset-passwords
```

This resets the demo passwords without deleting tickets.

## A five-minute presentation

1. Sign in as **sales**. Search **SO-10482**. You can also try `so10482`, `10482`, `INV-20482`, `Demo Patio` or `0821234567`. Open the customer, choose the order and click **Get help with this**. Its details are included automatically.
2. Submit a request. Sign out and sign in as **stacey**. Acknowledge it, choose **Next working hour**, add a short update and save. Send a reply. Open **Test email outbox** to show the recipient and ticket link.
3. As **sales**, choose **Get help → Website problem**. Enter only a problem description and submit. URL and screenshot are optional. You can paste a screenshot or drop an image/PDF onto the form.
4. As **sam**, acknowledge the website request and choose **Live on site — ask requester to confirm**. Add a note and save. As **sales**, confirm the resolution or reopen it.
5. **sales2** belongs to Johannesburg and can search **SO-10501**; they cannot see the first salesperson's requests or order search results.

The in-app Demo guide repeats these examples. Demo SOs are fictional; these are working local searches, not a verified connection to real orders. Captured emails are previews, not inbox delivery. No payment automation is included.

## Package contents

- `support-demo/`: runnable PHP/SQLite demonstration. No framework, build step or npm required to run it.
- `module-source/`: the same support PHP/JS/CSS files for later developer integration. This is **not a deployment patch**.
- `qa/`: optional developer HTTP/interface tests.
- `VALIDATION.md`: results, limitations and a manual checklist.
- `HANDOVER.md`: change inventory and staging follow-up.
