<p align="center"><a href="#" target="_blank"><img src="./.github/assets/logo.svg" width="400" alt="Trakli Logo"></a></p>

<p align="center"><img src="./.github/assets/trakli-dashboard-showcase.png" alt="Trakli dashboard and mobile preview" width="820"></p>

# Trakli Webservice

[![Tests](https://github.com/trakli/webservice/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/trakli/webservice/actions/workflows/tests.yml)
[![Lint & Sniffs](https://github.com/trakli/webservice/actions/workflows/lint-sniffs.yml/badge.svg?branch=main)](https://github.com/trakli/webservice/actions/workflows/lint-sniffs.yml)

Money apps want your bank login, and then they want your money to be in one country, in one currency, in
an account they can read. Get paid from abroad, keep cash, hold two currencies at once, or move money over
mobile money, and most of your money is invisible to them. So it ends up in a spreadsheet, which holds
anything and tells you nothing.

Trakli tracks money the way it actually moves. Cash, mobile money, bank and card sit beside each other as
wallets, in any of forty-eight currencies, converting at the rate you say applies and not one scraped from a
market you cannot get. Your phone keeps working with no signal and settles up when it reconnects. And it
is yours: your server, your database, no one else holding the ledger of what you earn.

This is the Laravel API behind it, and what the web and mobile apps talk to.

## Features

What you will not find elsewhere:

- **Wallets that match reality:** cash, mobile money, bank and card, side by side.
- **Any currency, at your rate:** forty-eight of them, held at once; transfers convert at the rate you set.
- **Offline-first:** the phone works with no signal, and changes settle cleanly when it reconnects.
- **An assistant that can act:** ask it in plain words, and it proposes every change for you to confirm
  before anything is written.
- **Yours to run:** your server, your database, no bank login handed to anyone.

The rest, done properly:

- **Transactions:** Income and expenses across multiple wallets, with attachments and recurring rules.
- **Transfers:** Move money between wallets, including cross-currency at user-set rates.
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
