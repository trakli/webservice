<p align="center"><a href="#" target="_blank"><img src="./.github/assets/logo.svg" width="400" alt="Trakli Logo"></a></p>

<p align="center"><img src="./.github/assets/trakli-dashboard-showcase.png" alt="Trakli dashboard and mobile preview" width="820"></p>

# Trakli Webservice

[![Tests](https://github.com/trakli/webservice/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/trakli/webservice/actions/workflows/tests.yml)
[![Lint & Sniffs](https://github.com/trakli/webservice/actions/workflows/lint-sniffs.yml/badge.svg?branch=main)](https://github.com/trakli/webservice/actions/workflows/lint-sniffs.yml)

Trakli gets your money under control by showing you where it actually goes. Every wallet, balance, and
holding you have, in as many accounts and currencies as you keep them, adds up to your real net worth,
not just what you spent this month. And you barely type any of it: say what happened, or hand over a
receipt, and the assistant does the logging.

This is the Laravel API behind it, and what the web and mobile apps talk to.

## Features

AI native:

- **An assistant that acts:** say what happened ("log $40 dinner at Mama's, split under food") and it
  records, categorizes, transfers, and cleans up your books in bulk, proposing every change for you to
  approve before anything is written.
- **Reads your paperwork:** hand it receipts, statements, CSVs, or PDFs and it turns them into
  transactions.
- **Reports on a canvas:** ask for a report, watch it get built, then take it with you.
- **MCP built in:** Trakli runs an MCP server, so Claude or any MCP client can read your books and record
  transactions through tokens you mint and revoke yourself.

For pro users:

- **Wallets that match reality:** cash, mobile money, bank and card, side by side.
- **Any currency, at your rate:** forty-eight of them, held at once; transfers convert at the rate you
  set, not one scraped from a market you cannot get.
- **Offline-first:** the phone works with no signal, and changes settle cleanly when it reconnects.
- **Yours to run:** open source end to end; audit it, fork it, self-host it, with no one else holding the
  ledger of what you earn.

A full finance app, not a spreadsheet:

- **Transactions:** Income and expenses across multiple wallets, with attachments and recurring rules.
- **Transfers:** Move money between wallets, including cross-currency at user-set rates.
- **Holdings:** Crypto, stocks, property, any asset; crypto priced live, everything else at the price you set.
- **Budgets:** Scoped to categories, groups, or wallets; weekly / monthly / yearly / custom range; optional rollover; threshold and forecast alerts.
- **Refunds:** Mark an income as refunding an earlier expense; matching budgets adjust automatically.
- **Reminders:** Bills, budget alerts, and custom events with pause, resume, and snooze.
- **Imports:** Pull transactions from CSVs, PDFs, and photos of receipts.
- **Insights:** Dashboard stats and digest emails.

## Setup instructions

### Prerequisites

- Docker
- Docker Compose
- Make

### Quick installation guide
- `git clone git@github.com:whilesmart/trakli-webservice.git`
- `cd trakli-webservice`
- `cp .env.example .env`
- `make setup`

### Development Commands
- `make up` - Start containers
- `make down` - Stop containers
- `make restart` - Restart containers
- `make test` - Run tests
- `make lint` - Check code style
- `make lint-fix` - Fix code style issues
- `make phpmd` - Runs PHP Mess Detector
- `make phpstan` - Runs PHP Static Analyzer
- `make format` - Additional code style checks
- `make format-fix` - Fix additional code style issues
- `make migrate` - Run database migrations
- `make migrate-fresh` - Fresh migrations (drops all tables)
- `make seed` - Run database seeders
- `make migrate-fresh-seed` - Fresh migrations + seeders
- `make optimize` - Optimize Laravel application (cache config, routes, views)
- `make tinker` - Open Laravel Tinker REPL
- `make bash` - Access container bash shell
- `make logs` - View application logs
- `make fix-permissions` - Fix file permission issues

For detailed and explained installation steps see : [INSTALLATION.md](INSTALLATION.md)

### Accessing the Application

- The application will be available at `http://localhost:8000`.
- API documentation will be accessible at `http://localhost:8000/docs/swagger`.

### Stopping the Containers

To stop the Docker containers, run:

```bash
make down
```

### LICENSE

```
MIT License

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
