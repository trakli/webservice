<p align="center"><a href="#" target="_blank"><img src="https://github.com/whilesmart/trakli/blob/main/logo.svg" width="400" alt="Trakli Logo"></a></p>

# Trakli Webservice [![Checks](https://github.com/trakli/trakli-webservice/actions/workflows/checks.yml/badge.svg)](https://github.com/trakli/trakli-webservice/actions/workflows/checks.yml)

## Overview

Trakli is a personal income tracking application built using Laravel. The application allows users to manage and categorize their income and expenses under various groups and categories.

## Features

- Register and log in to the application
- Manage user profile information
- Create, view, update, and delete groups (e.g., Home, Office, Personal)
- Manage income categories (e.g., Sales, Salary, Gift, Bonus, Interest)
- Manage expense categories (e.g., Utilities, Transport, Electricity, Rent, Tax, Health)
- Manage parties (e.g., individuals or entities from which money comes or goes)
- Manage wallets and bank accounts (e.g., cash, bank accounts)
- Record income and expense entries with details such as date, party, description, source/target wallet, and optional attachments

## Setup instructions

### Prerequisites

- Docker
- Docker Compose

### Quick installation guide
- `git clone git@github.com:whilesmart/trakli-webservice.git`
- `cd trakli-webservice`
- `cp .env.example .env`
- `docker compose up -d app`
- `docker compose exec app composer install`
- `docker compose exec app php artisan key:generate`
- `docker compose exec app php artisan migrate`
- `docker compose exec app php artisan db:seed`

For detailed and explained installation steps see : [INSTALLATION.md](INSTALLATION.md)

### Accessing the Application

- The application will be available at `http://localhost:8000`.
- API documentation will be accessible at `http://localhost:8000/docs/swagger`.

### Stopping the Containers

To stop the Docker containers, run:

```bash
docker compose stop
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
