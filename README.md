# User Upload Script

A command line PHP script that parses a CSV file of user data and inserts valid records into a PostgreSQL database.

## Features

- **CSV parsing** — Reads CSV files with `name`, `surname`, and `email` columns.
- **Data normalisation** — Standardizes user data  before inserting into the database.
- **Validation** — Validates email format prior to database insertion.
- **Dry run mode** — Preview parsed data without modifying the database.
- **Database management** — Creates or rebuilds the PostgreSQL users table.

## Prerequisites

- PHP 8.3 installed.
- PostgreSQL 13 or higher installed and running.
- PHP PostgreSQL extension (`php-pgsql`).

    ```bash
    sudo apt-get install php-pgsql
    ```

## Installation

1. Clone the repository:

    ```bash
    git clone https://github.com/ssj365/moodle_coding_challenge.git
    cd moodle_coding_challenge
    ```

2. Ensure `user_upload.php` and `users.csv` are in the same directory.

## Running the Script

Run from the directory containing the script and CSV file:

```bash
cd /path/to/moodle_coding_challenge
```

**Create the users table:**

```bash
php user_upload.php --create_table -u dbuser -p dbpass -h localhost
```

**Parse the CSV and insert records:**

```bash
php user_upload.php --file users.csv -u dbuser -p dbpass -h localhost
```

**Dry run (preview without inserting):**

```bash
php user_upload.php --file users.csv --dry_run
```

**View all available options:**

```bash
php user_upload.php --help
```

