# Staff Support Desk

A staff help desk that replaced walk-up requests with a trackable queue. I designed it around a real problem at a small retail business and built it with OpenAI Codex.

> **This is a demo version.** All customers, orders and staff are fictional. No real company systems, data or email services are connected.

## The problem

Staff were walking up to a finance colleague's desk whenever they needed help with an order, a quote or the website. Her work was constantly interrupted, and nobody had a record of what had been asked, who was handling it or whether it had been fixed.

## The solution

One place where staff ask for help, see who is handling it and know what happens next.

- **Ask from where you are.** Search for a sales order by order number, invoice number, customer name, email or phone. Click **Get help with this** and the order details are attached automatically.
- **Report website problems easily.** Only a description is required. The page URL and a screenshot are optional, so staff can report a fault with whatever information they have. Screenshots can be pasted or dragged in.
- **Clear follow-up.** Support staff acknowledge each request, set when the next update is due, reply, and keep internal notes the requester never sees.
- **Confirmed fixes.** When a website fix goes live, the requester is asked to confirm it worked or reopen the request.
- **Email at every step.** Requesters and handlers are notified of new requests, replies, updates and resolutions. In this demo, emails are captured locally so you can preview them.
- **The right people see the right things.** Branch and permission rules control who can see which requests and orders.

## My role

- Spotted the problem through process testing and defined what the tool needed to do.
- Wrote the requirements, including which fields are mandatory and how requests should be routed.
- Built the system with Codex, reviewing and correcting its output along the way.
- Tested each workflow and checked fixes before accepting them.

## Try it

Requires PHP 8.1 or later with the PDO SQLite and Fileinfo extensions. No database server, API keys or build step needed.

From the `support-demo` folder:

```bash
php bin/setup.php
php -d upload_max_filesize=5M -d post_max_size=6M -S 127.0.0.1:8080 -t public
```

Then open http://127.0.0.1:8080/index.html. Setup prints a password for each demo account, so save them.

On Windows with WAMP, replace `php` with the full path to your PHP executable, or run `start-demo.ps1`. See `START-HERE.md` for details.

### Demo accounts

| Username | Role |
| --- | --- |
| `sales` | Salesperson, Cape Town branch |
| `sales2` | Salesperson, Johannesburg branch (cannot see Cape Town requests) |
| `alex` | Support agent, handles order requests |
| `sam` | Admin, handles website requests |

`START-HERE.md` has a five-minute walkthrough. Try searching for **SO-10482** as `sales`.

## Tests

130 automated checks cover permissions, privacy, concurrent edits, notifications and the interface.

```bash
# From support-demo: 95 workflow and presentation checks
php tests/presentation.php

# Optional, from qa (needs Node.js): 35 HTTP and interface checks
npm install
node presentation.cjs PATH_TO_PHP ../support-demo
```

See `VALIDATION.md` for full results and a manual checklist.

## Built with

PHP, SQLite, vanilla JavaScript and CSS, with optional email delivery through Brevo. Built with OpenAI Codex.

## Project structure

```
support-demo/     Runnable demo (PHP + SQLite)
module-source/    The same support module files, ready to integrate into an existing intranet
qa/               Optional interface tests
START-HERE.md     Setup and walkthrough
VALIDATION.md     Test results and limitations
```
